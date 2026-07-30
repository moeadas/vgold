<?php
/**
 * VGold — read a contractor's monthly invoice into a draft for review.
 *
 * Close cousin of BillExtractor, and deliberately not the same class. A supplier
 * bill and a contractor's monthly invoice ask different questions: here the
 * vendor is already known (the person submitting is signed in), and what matters
 * instead is WHICH PERIOD is being billed for — the single most common source of
 * a duplicate payment.
 *
 * Two things the prompt is explicit about, both deliberate:
 *
 *  - Bank account, routing and SWIFT numbers are NOT extracted. They are the
 *    highest-value target on the page and VGold has no use for them: the
 *    approver reads them off the attached PDF, which is shown beside the figures.
 *    Never asking for them is a stronger guarantee than asking and discarding.
 *
 *  - The name on the invoice is captured only so a mismatch can be pointed out.
 *    Who is owed the money is decided by the session, never by the document.
 */
require_once __DIR__ . '/AiClient.php';
require_once __DIR__ . '/BillExtractor.php';

class ContractorInvoiceExtractor extends BillExtractor
{
    const SYSTEM = <<<'TXT'
You extract structured data from invoices submitted by independent contractors
and freelancers to the company that engaged them.
Reply with ONE JSON object and nothing else — no prose, no code fences.

Schema:
{
  "contractor_name": string|null,      // the person or business ISSUING the invoice
  "billed_to": string|null,            // the company being billed, as printed
  "invoice_number": string|null,       // the contractor's own invoice number/reference
  "issued_at": "YYYY-MM-DD"|null,      // the invoice date
  "due_at": "YYYY-MM-DD"|null,         // payment due date, only if stated
  "period_label": string|null,         // the work period as printed, e.g. "March 2026"
  "period_start": "YYYY-MM-DD"|null,   // first day of the work period, if determinable
  "period_end": "YYYY-MM-DD"|null,     // last day of the work period, if determinable
  "currency": string|null,             // ISO code. null unless actually shown or unambiguous
  "subtotal": number|null,
  "tax_total": number|null,
  "total": number|null,                // the amount payable
  "notes": string|null,                // description of the work, terms worth keeping
  "line_items": [
    { "name": string, "quantity": number, "unit_price": number, "total": number|null }
  ],
  "confidence": "high"|"medium"|"low",
  "warnings": [string]
}

Rules:
- Use the numerals printed on the document. Never estimate, round or invent a figure.
- If a field is not clearly present, use null. A null is far better than a guess.
- DO NOT extract bank account numbers, routing numbers, IBAN, SWIFT/BIC codes,
  card numbers or any other payment credentials. Leave them out entirely, even
  if they appear prominently. They are not wanted in any field.
- The work period is important. "March 2026 services", "for the month of March",
  or a date range in a line item all describe it. Put what is printed in
  period_label, and only fill period_start/period_end when the month and year
  are both unambiguous.
- quantity is hours, days or units when shown; unit_price is the rate. If only a
  total is given, set quantity 1 and unit_price to that total.
- currency: only report one if a symbol or code is actually on the document, or
  if the country is unambiguous from an address. A bare number is null — say so
  in warnings. Do not infer a currency from bank details.
- Dates: convert any format to YYYY-MM-DD. If the day/month order is ambiguous
  (e.g. 03/04/2026), leave the date null and say so in warnings.
- If this does not look like an invoice at all, set confidence to "low", leave
  the fields null, and say what the document appears to be in warnings.
TXT;

    const PROMPT = 'Extract this contractor invoice into the JSON object described. Return only the JSON.';

    /** Bytes; matches what the providers will accept once base64 inflates it. */
    const MAX_BYTES = 12582912;

    public static function extract($absPath, $mime, $userId = null)
    {
        if (!is_readable($absPath)) throw new Exception('The uploaded file could not be read.');
        $bytes = filesize($absPath);
        if ($bytes > self::MAX_BYTES) {
            throw new Exception('That file is ' . round($bytes / 1048576, 1) . 'MB. Invoices up to 12MB can be read.');
        }

        $raw = AiClient::complete(self::PROMPT, self::SYSTEM, [
            'user_id'    => $userId,
            'max_tokens' => 4096,
            'timeout'    => 120,
            'attachment' => [
                'mime' => $mime,
                'data' => base64_encode(file_get_contents($absPath)),
                'name' => basename($absPath),
            ],
        ]);

        $data = AiClient::extractJson($raw);
        if (!is_array($data)) {
            throw new Exception('That document could not be read as an invoice. Check it is the right file, or fill the details in by hand.');
        }
        return self::shape($data);
    }

    /** Coerce the model's answer into the shape the review screen expects. */
    public static function shape(array $d)
    {
        $out = [
            'contractor_name' => self::str($d['contractor_name'] ?? null),
            'billed_to'       => self::str($d['billed_to'] ?? null),
            'invoice_number'  => self::str($d['invoice_number'] ?? null),
            'issued_at'       => self::date($d['issued_at'] ?? null),
            'due_at'          => self::date($d['due_at'] ?? null),
            'period_label'    => self::str($d['period_label'] ?? null),
            'period_start'    => self::date($d['period_start'] ?? null),
            'period_end'      => self::date($d['period_end'] ?? null),
            'currency'        => self::currency($d['currency'] ?? null),
            'subtotal'        => self::num($d['subtotal'] ?? null),
            'tax_total'       => self::num($d['tax_total'] ?? null),
            'total'           => self::num($d['total'] ?? null),
            'notes'           => self::str($d['notes'] ?? null),
            'confidence'      => in_array($d['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $d['confidence'] : 'low',
            'warnings'        => [],
            'line_items'      => [],
        ];

        foreach ((array)($d['warnings'] ?? []) as $w) {
            $w = self::str($w);
            if ($w !== null) $out['warnings'][] = mb_substr($w, 0, 300);
        }

        foreach ((array)($d['line_items'] ?? []) as $li) {
            if (!is_array($li)) continue;
            $name = self::str($li['name'] ?? null);
            if ($name === null) continue;
            // Quantity settles first — a line showing a total but no rate is the
            // norm on these, and deriving the rate needs something to divide by.
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

        // An invoice with no readable lines still needs one to bill against.
        if (!$out['line_items'] && $out['total'] !== null) {
            $out['line_items'][] = [
                'name'       => $out['period_label'] ? $out['period_label'] . ' — contractor services' : 'Contractor services',
                'quantity'   => 1,
                'unit_price' => $out['subtotal'] ?? $out['total'],
                'total'      => $out['subtotal'] ?? $out['total'],
            ];
            $out['warnings'][] = 'No individual lines were readable, so the whole invoice was entered as one line.';
        }

        // The period is what stops the same month being paid twice, so its
        // absence is worth saying out loud rather than leaving a quiet blank.
        if (!$out['period_label'] && !$out['period_start']) {
            $guess = self::periodFromLines($out['line_items']);
            if ($guess) {
                $out['period_label'] = $guess['label'];
                $out['period_start'] = $guess['start'];
                $out['period_end']   = $guess['end'];
            } else {
                $out['warnings'][] = 'No work period was found on the invoice. Set which month this covers before submitting — it is what stops a month being paid twice.';
            }
        } elseif ($out['period_label'] && !$out['period_start']) {
            $guess = self::monthFromText($out['period_label']);
            if ($guess) { $out['period_start'] = $guess['start']; $out['period_end'] = $guess['end']; }
        }

        if ($out['total'] === null) {
            $out['warnings'][] = 'No total was readable. Enter the amount you are invoicing for.';
        }
        if ($out['currency'] === null) {
            $out['warnings'][] = 'No currency is printed on this invoice — confirm which one it is in.';
        }
        if ($out['due_at'] && $out['issued_at'] && $out['due_at'] < $out['issued_at']) {
            $out['warnings'][] = 'The due date is before the invoice date — check both.';
            $out['due_at'] = null;
        }

        // Lines against the stated subtotal. Flagged, never silently corrected.
        $lineSum = 0.0;
        foreach ($out['line_items'] as $li) $lineSum += $li['quantity'] * $li['unit_price'];
        $expected = ($out['subtotal'] !== null) ? $out['subtotal']
            : (($out['total'] !== null && $out['tax_total'] !== null) ? $out['total'] - $out['tax_total'] : null);
        if ($expected !== null && $lineSum > 0 && abs($lineSum - $expected) > max(0.02, abs($expected) * 0.01)) {
            $out['warnings'][] = sprintf(
                'The lines add up to %s but the invoice states %s before tax — check the lines.',
                number_format($lineSum, 2), number_format($expected, 2)
            );
        }

        return $out;
    }

    /** "March 2026 services" in a line item is a work period like any other. */
    private static function periodFromLines(array $lines)
    {
        foreach ($lines as $li) {
            $g = self::monthFromText($li['name']);
            if ($g) return $g;
        }
        return null;
    }

    /**
     * Read "March 2026" / "Mar 2026" / "03/2026" / "2026-03" out of free text.
     * Returns null rather than guessing a year — an invoice for the wrong year
     * is worse than one with no period at all, because it looks fine.
     */
    public static function monthFromText($text)
    {
        $t = trim((string)$text);
        if ($t === '') return null;

        $months = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
                   'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];
        $m = null; $y = null;

        if (preg_match('/\b([A-Za-z]{3,9})\.?\s+(\d{4})\b/', $t, $hit)) {
            $word = strtolower($hit[1]);
            foreach ($months as $name => $n) {
                // The word must be a PREFIX of the month, not merely share three
                // letters with it — otherwise "Marketing 2026" reads as March.
                if (strlen($word) >= 3 && strpos($name, $word) === 0) { $m = $n; break; }
            }
            $y = (int)$hit[2];
        } elseif (preg_match('/\b(\d{4})-(\d{1,2})\b/', $t, $hit)) {
            $y = (int)$hit[1]; $m = (int)$hit[2];
        } elseif (preg_match('/\b(\d{1,2})\/(\d{4})\b/', $t, $hit)) {
            $m = (int)$hit[1]; $y = (int)$hit[2];
        }

        if (!$m || !$y || $m < 1 || $m > 12 || $y < 2000 || $y > 2100) return null;
        $start = sprintf('%04d-%02d-01', $y, $m);
        return [
            'label' => date('F Y', strtotime($start)),
            'start' => $start,
            'end'   => date('Y-m-t', strtotime($start)),
        ];
    }
}
