<?php
/**
 * VGold — read a supplier bill and turn it into a draft document.
 *
 * The model is asked for one strict JSON object and nothing else. Everything it
 * returns is then treated as untrusted: numbers are coerced, dates normalised,
 * and the vendor/category/item names are matched against records that already
 * exist rather than being used to create anything. Nothing here writes to the
 * database — the draft is handed to the person to review first, because an
 * OCR'd total silently becoming a payable is exactly the wrong failure mode.
 */
require_once __DIR__ . '/AiClient.php';

class BillExtractor {

    const SYSTEM = <<<'TXT'
You extract structured data from supplier bills, invoices and receipts.
Reply with ONE JSON object and nothing else — no prose, no code fences.

Schema:
{
  "vendor_name": string|null,          // the company that ISSUED the bill (who is owed)
  "vendor_email": string|null,
  "vendor_tax_id": string|null,
  "document_number": string|null,      // the supplier's own invoice/bill number
  "order_number": string|null,         // PO or order reference if shown
  "issued_at": "YYYY-MM-DD"|null,      // invoice date
  "due_at": "YYYY-MM-DD"|null,         // payment due date, if stated
  "currency": string|null,             // ISO code, e.g. "USD", "EUR", "AED"
  "subtotal": number|null,
  "tax_total": number|null,
  "total": number|null,                // the amount payable
  "category_hint": string|null,        // one or two words, e.g. "Software", "Shipping"
  "notes": string|null,                // payment terms or reference lines worth keeping
  "line_items": [
    { "name": string, "quantity": number, "unit_price": number, "total": number|null }
  ],
  "confidence": "high"|"medium"|"low", // how legible and unambiguous the document was
  "warnings": [string]                 // anything a human should check, in plain language
}

Rules:
- Use the numerals printed on the document. Never estimate, round or invent a figure.
- If a field is not clearly present, use null. A null is far better than a guess.
- quantity defaults to 1 when the document does not show one.
- unit_price excludes tax where the document separates them.
- Dates: convert any format to YYYY-MM-DD. If the day/month order is ambiguous
  (e.g. 03/04/2026), leave the date null and say so in warnings.
- If the totals do not add up, still report what is printed and note it in warnings.
- If this does not look like a bill or invoice at all, set confidence to "low",
  leave the fields null, and explain what the document appears to be in warnings.
TXT;

    const PROMPT = 'Extract this bill into the JSON object described. Return only the JSON.';

    /**
     * Run the extraction.
     *
     * @param string $absPath Absolute path of the uploaded file.
     * @param string $mime    Its MIME type.
     * @return array Normalised draft, ready for review.
     */
    public static function extract($absPath, $mime, $userId = null) {
        if (!is_readable($absPath)) throw new Exception('The uploaded file could not be read.');
        $bytes = filesize($absPath);
        // Base64 inflates by 4/3 and every provider caps the request body.
        if ($bytes > 12 * 1024 * 1024) {
            throw new Exception('That file is ' . round($bytes / 1048576, 1) . 'MB. Bills up to 12MB can be read — try a smaller scan or a photo.');
        }

        $raw = AiClient::complete(self::PROMPT, self::SYSTEM, [
            'user_id'    => $userId,
            'max_tokens' => 4096,
            'timeout'    => 120,
            'attachment' => [
                'mime' => $mime,
                'data' => base64_encode(file_get_contents($absPath)),
                'name' => basename($absPath),
                // Given the file, a PDF can be rasterised straight from disk for
                // providers that only take images — no needless re-encode.
                'path' => $absPath,
            ],
        ]);

        $data = AiClient::extractJson($raw);
        if (!is_array($data)) {
            throw new Exception('The document could not be read as a bill. Try a clearer scan, or enter it by hand.');
        }
        return self::normalise($data);
    }

    /** Coerce whatever came back into the shape the editor expects. */
    private static function normalise(array $d) {
        $out = [
            'vendor_name'     => self::str($d['vendor_name'] ?? null),
            'vendor_email'    => self::email($d['vendor_email'] ?? null),
            'vendor_tax_id'   => self::str($d['vendor_tax_id'] ?? null),
            'document_number' => self::str($d['document_number'] ?? null),
            'order_number'    => self::str($d['order_number'] ?? null),
            'issued_at'       => self::date($d['issued_at'] ?? null),
            'due_at'          => self::date($d['due_at'] ?? null),
            'currency'        => self::currency($d['currency'] ?? null),
            'subtotal'        => self::num($d['subtotal'] ?? null),
            'tax_total'       => self::num($d['tax_total'] ?? null),
            'total'           => self::num($d['total'] ?? null),
            'category_hint'   => self::str($d['category_hint'] ?? null),
            'notes'           => self::str($d['notes'] ?? null),
            'confidence'      => in_array($d['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $d['confidence'] : 'low',
            'warnings'        => [],
            'line_items'      => [],
        ];

        foreach ((array)($d['warnings'] ?? []) as $w) {
            $w = self::str($w);
            if ($w !== null) $out['warnings'][] = $w;
        }

        foreach ((array)($d['line_items'] ?? []) as $li) {
            if (!is_array($li)) continue;
            $name = self::str($li['name'] ?? null);
            if ($name === null) continue;
            // Settle the quantity first: a line that shows a total but no unit
            // price is common on itemised bills, and deriving the price needs a
            // usable quantity to divide by.
            $qty = self::num($li['quantity'] ?? null);
            if ($qty === null || $qty == 0) $qty = 1;

            $price = self::num($li['unit_price'] ?? null);
            $total = self::num($li['total'] ?? null);
            if ($price === null && $total !== null) $price = round($total / $qty, 4);

            $out['line_items'][] = [
                'name'       => mb_substr($name, 0, 191),
                'quantity'   => $qty,
                'unit_price' => $price ?? 0,
                'total'      => $total,
            ];
        }

        // A bill with no readable lines still needs something to save against.
        if (!$out['line_items'] && $out['total'] !== null) {
            $out['line_items'][] = [
                'name'       => $out['category_hint'] ?: ($out['vendor_name'] ? $out['vendor_name'] . ' — bill' : 'Bill'),
                'quantity'   => 1,
                'unit_price' => $out['subtotal'] ?? $out['total'],
                'total'      => $out['subtotal'] ?? $out['total'],
            ];
            $out['warnings'][] = 'No individual line items were readable, so the whole bill was entered as a single line.';
        }

        // Arithmetic check — worth flagging, never worth silently correcting.
        $lineSum = 0.0;
        foreach ($out['line_items'] as $li) $lineSum += $li['quantity'] * $li['unit_price'];
        $expected = ($out['subtotal'] !== null) ? $out['subtotal'] : (($out['total'] !== null && $out['tax_total'] !== null) ? $out['total'] - $out['tax_total'] : null);
        if ($expected !== null && $lineSum > 0 && abs($lineSum - $expected) > max(0.02, $expected * 0.01)) {
            $out['warnings'][] = sprintf(
                'The line items add up to %s but the bill states %s before tax — check the lines.',
                number_format($lineSum, 2), number_format($expected, 2)
            );
        }
        if ($out['due_at'] && $out['issued_at'] && $out['due_at'] < $out['issued_at']) {
            $out['warnings'][] = 'The due date is before the invoice date — check both.';
            $out['due_at'] = null;
        }

        return $out;
    }

    /**
     * Match the extracted names onto records that already exist, so a review can
     * be one click. Never creates anything — an unmatched vendor is reported as
     * such and the person decides.
     */
    public static function match(array $draft) {
        $draft['vendor_id']   = null;
        $draft['vendor_match'] = null;
        $draft['category_id'] = null;

        if (!empty($draft['vendor_name'])) {
            $vendors = DB::fetchAll("SELECT id, name, email FROM acc_contacts WHERE type = 'vendor' AND deleted_at IS NULL");
            $best = self::bestMatch($draft['vendor_name'], $vendors, 'name');
            // An exact email hit beats any name similarity.
            if (!empty($draft['vendor_email'])) {
                foreach ($vendors as $v) {
                    if ($v['email'] && strcasecmp(trim($v['email']), $draft['vendor_email']) === 0) {
                        $best = ['row' => $v, 'score' => 1.0];
                        break;
                    }
                }
            }
            if ($best && $best['score'] >= 0.72) {
                $draft['vendor_id']    = (int)$best['row']['id'];
                $draft['vendor_match'] = ['name' => $best['row']['name'], 'score' => round($best['score'], 2)];
            }
        }

        if (!empty($draft['category_hint'])) {
            try {
                $cats = DB::fetchAll("SELECT id, name FROM acc_categories WHERE type IN ('expense','other') AND deleted_at IS NULL");
                $best = self::bestMatch($draft['category_hint'], $cats, 'name');
                if ($best && $best['score'] >= 0.6) $draft['category_id'] = (int)$best['row']['id'];
            } catch (\Throwable $e) { /* categories are optional */ }
        }

        // Point each line at a catalog item when one clearly corresponds.
        try {
            $items = DB::fetchAll("SELECT id, name FROM acc_items WHERE deleted_at IS NULL");
            foreach ($draft['line_items'] as $i => $li) {
                $best = self::bestMatch($li['name'], $items, 'name');
                $draft['line_items'][$i]['item_id'] = ($best && $best['score'] >= 0.8) ? (int)$best['row']['id'] : null;
            }
        } catch (\Throwable $e) { /* catalog is optional */ }

        return $draft;
    }

    /** Best row by normalised similarity, or null. */
    protected static function bestMatch($needle, array $rows, $field) {
        $needle = self::norm($needle);
        if ($needle === '') return null;
        $best = null;
        foreach ($rows as $r) {
            $hay = self::norm($r[$field] ?? '');
            if ($hay === '') continue;
            if ($hay === $needle) return ['row' => $r, 'score' => 1.0];
            // Containment scores well: "Acme Ltd" vs "Acme Limited Trading".
            if (strpos($hay, $needle) !== false || strpos($needle, $hay) !== false) {
                $score = min(strlen($hay), strlen($needle)) / max(strlen($hay), strlen($needle));
                $score = 0.75 + ($score * 0.24);
            } else {
                similar_text($needle, $hay, $pct);
                $score = $pct / 100;
            }
            if (!$best || $score > $best['score']) $best = ['row' => $r, 'score' => $score];
        }
        return $best;
    }

    /** Lower-case, strip punctuation and the company suffixes that add no signal. */
    protected static function norm($s) {
        $s = mb_strtolower(trim((string)$s));
        $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s);
        $s = preg_replace('/\b(ltd|limited|llc|inc|incorporated|gmbh|bv|sa|sarl|plc|co|company|corp|corporation|fz|fzco|llp|pvt)\b/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    protected static function str($v) {
        if (!is_scalar($v)) return null;
        $v = trim((string)$v);
        return ($v === '' || strcasecmp($v, 'null') === 0 || strcasecmp($v, 'n/a') === 0) ? null : $v;
    }

    protected static function email($v) {
        $v = self::str($v);
        return ($v && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : null;
    }

    protected static function currency($v) {
        $v = self::str($v);
        return ($v && preg_match('/^[A-Za-z]{3}$/', $v)) ? strtoupper($v) : null;
    }

    /** Accepts 1.234,56 and 1,234.56 and "$1,234.56" alike. */
    protected static function num($v) {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (!is_string($v)) return null;
        $s = trim($v);
        if ($s === '') return null;
        $s = preg_replace('/[^0-9,.\-]/', '', $s);
        if ($s === '' || $s === '-') return null;

        $lastDot = strrpos($s, '.');
        $lastCom = strrpos($s, ',');
        if ($lastDot !== false && $lastCom !== false) {
            // Whichever separator comes last is the decimal point.
            if ($lastCom > $lastDot) $s = str_replace('.', '', $s);
            else                     $s = str_replace(',', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($lastCom !== false) {
            // A lone comma with exactly two trailing digits is a decimal comma.
            $s = (preg_match('/,\d{2}$/', $s) && substr_count($s, ',') === 1)
                ? str_replace(',', '.', $s)
                : str_replace(',', '', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    /** Normalise to YYYY-MM-DD, refusing anything genuinely ambiguous. */
    protected static function date($v) {
        $v = self::str($v);
        if ($v === null) return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
