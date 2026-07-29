<?php
/**
 * BankMatcher — pair statement lines with transactions already in VGold.
 *
 * The rule that shapes everything here: a match is only offered automatically
 * when it is the ONLY plausible one. Two £45.00 card payments on the same day
 * are indistinguishable to any scoring function, so the tie is handed to a
 * person instead of being broken arbitrarily. A wrong auto-match is worse than
 * no match — it settles the wrong invoice and hides itself by looking done.
 *
 * Amount and direction are hard gates, never scored: a line for −1,200.00 can
 * only ever match an expense of exactly 1,200.00. Everything else (date
 * distance, wording, reference numbers) only ranks the survivors.
 */
class BankMatcher
{
    /** How far either side of the statement date a transaction may sit. */
    const DATE_WINDOW = 5;

    /** Score needed to offer a match without being asked. */
    const HIGH = 150;
    /** Score needed to show a match as a suggestion worth a glance. */
    const MEDIUM = 112;
    /** How far clear of the runner-up the winner must be to be unambiguous. */
    const MARGIN = 25;

    /** Words every bank sprays over descriptions; they carry no signal. */
    const NOISE = [
        'CARD', 'POS', 'PURCHASE', 'PAYMENT', 'PMT', 'REF', 'REFERENCE', 'TFR', 'TRANSFER',
        'DD', 'SO', 'BACS', 'FPS', 'CHAPS', 'SEPA', 'ACH', 'DEBIT', 'CREDIT', 'DIRECT',
        'ONLINE', 'BANK', 'TRANSACTION', 'PAID', 'FROM', 'TO', 'THE', 'AND', 'LTD', 'LIMITED',
        'INC', 'LLC', 'PLC', 'GMBH', 'CO', 'COMPANY', 'INTL', 'INTERNATIONAL', 'WWW', 'COM',
    ];

    /* ================================================================
     * Matching statement lines to existing transactions
     * ================================================================ */

    /**
     * Score every pending line against the account's unlinked transactions.
     *
     * One query for the whole batch — a 400-row statement should not be 400
     * round trips.
     *
     * @param array $lines rows from acc_bank_lines (id, posted_at, amount, description, reference)
     * @return array line id → ['candidates' => [...], 'best' => int|null, 'confidence' => string]
     */
    public static function suggestAll(array $lines, $accountId)
    {
        if (!count($lines)) return [];

        $dates = array_values(array_filter(array_column($lines, 'posted_at')));
        if (!count($dates)) return [];
        sort($dates);
        $from = date('Y-m-d', strtotime($dates[0] . ' -' . self::DATE_WINDOW . ' days'));
        $to   = date('Y-m-d', strtotime(end($dates) . ' +' . self::DATE_WINDOW . ' days'));

        $candidates = DB::fetchAll(
            "SELECT t.id, t.type, t.paid_at, t.amount, t.description, t.reference, t.contact_id,
                    t.category_id, t.document_id, t.is_transfer,
                    c.name AS contact_name, cat.name AS category_name,
                    d.number AS document_number
               FROM acc_transactions t
          LEFT JOIN acc_contacts c   ON c.id = t.contact_id
          LEFT JOIN acc_categories cat ON cat.id = t.category_id
          LEFT JOIN acc_documents d  ON d.id = t.document_id
              WHERE t.account_id = ? AND t.deleted_at IS NULL
                AND t.bank_line_id IS NULL
                AND t.paid_at >= ? AND t.paid_at <= ?",
            [(int)$accountId, $from, $to]
        );
        if (!count($candidates)) return [];

        // Bucket by signed cents so the inner loop only sees real possibilities.
        $byAmount = [];
        foreach ($candidates as $t) {
            $signed = ($t['type'] === 'income' ? 1 : -1) * round((float)$t['amount'] * 100);
            $byAmount[(string)$signed][] = $t;
        }

        $out = [];
        foreach ($lines as $line) {
            $key = (string)round((float)$line['amount'] * 100);
            $pool = $byAmount[$key] ?? [];
            $out[(int)$line['id']] = self::rank($line, $pool);
        }
        return $out;
    }

    /** Score one line against an already amount-filtered pool. */
    public static function rank(array $line, array $pool)
    {
        $scored = [];
        foreach ($pool as $t) {
            $days = self::dayGap($line['posted_at'], $t['paid_at']);
            if ($days === null || $days > self::DATE_WINDOW) continue;
            $score = 100 + max(0, 40 - $days * 6);
            $why = [];
            $why[] = $days === 0 ? 'same day' : ($days === 1 ? '1 day apart' : $days . ' days apart');

            $sim = self::similarity($line['description'] ?? '', $t['description'] ?? '');
            if ($sim > 0) { $score += (int)round($sim * 40); if ($sim >= 0.34) $why[] = 'wording matches'; }

            if (!empty($t['contact_name']) && self::mentions($line['description'] ?? '', $t['contact_name'])) {
                $score += 25; $why[] = $t['contact_name'] . ' named on the statement';
            }
            if (!empty($t['document_number']) && self::mentions($line['description'] ?? '', $t['document_number'])) {
                $score += 25; $why[] = 'invoice ' . $t['document_number'] . ' quoted';
            }
            $lref = self::refDigits($line['reference'] ?? '');
            $tref = self::refDigits($t['reference'] ?? '');
            if ($lref !== '' && $lref === $tref) { $score += 30; $why[] = 'reference matches'; }

            $scored[] = ['transaction' => $t, 'score' => $score, 'reasons' => $why, 'days' => $days];
        }

        if (!count($scored)) return ['candidates' => [], 'best' => null, 'confidence' => 'none'];

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return $a['days'] <=> $b['days'];
        });

        $top = $scored[0];
        $runnerUp = $scored[1]['score'] ?? -PHP_INT_MAX;
        $clear = ($top['score'] - $runnerUp) >= self::MARGIN;

        // A tie on score is a genuine ambiguity, not a near miss to nudge past.
        $confidence = 'low';
        if ($clear && $top['score'] >= self::HIGH) $confidence = 'high';
        elseif ($clear && $top['score'] >= self::MEDIUM) $confidence = 'medium';
        elseif (!$clear) $confidence = 'ambiguous';

        return [
            'candidates' => array_slice($scored, 0, 5),
            'best' => $confidence === 'high' ? (int)$top['transaction']['id'] : null,
            'confidence' => $confidence,
        ];
    }

    /* ================================================================
     * Remembering how a description was treated last time
     * ================================================================ */

    /**
     * What VGold did with this payee before — the basis of a suggested
     * category and contact when a line has to be added rather than matched.
     *
     * Returns null rather than a weak guess: an unhelpful blank field is better
     * than a confidently wrong category nobody re-reads.
     */
    public static function recall($description, $isIncome)
    {
        $tokens = self::tokens($description);
        if (!count($tokens)) return null;

        // The rarest strong token is the one worth searching on.
        $probe = null;
        foreach ($tokens as $t) if (strlen($t) >= 4 && ($probe === null || strlen($t) > strlen($probe))) $probe = $t;
        if ($probe === null) return null;

        try {
            $rows = DB::fetchAll(
                "SELECT t.description, t.category_id, t.contact_id, t.payment_method,
                        c.name AS contact_name, cat.name AS category_name
                   FROM acc_transactions t
              LEFT JOIN acc_contacts c ON c.id = t.contact_id
              LEFT JOIN acc_categories cat ON cat.id = t.category_id
                  WHERE t.deleted_at IS NULL AND t.type = ?
                    AND (t.category_id IS NOT NULL OR t.contact_id IS NOT NULL)
                    AND t.description LIKE ?
               ORDER BY t.paid_at DESC, t.id DESC LIMIT 25",
                [$isIncome ? 'income' : 'expense', '%' . $probe . '%']
            );
        } catch (\Throwable $e) {
            return null;
        }

        $best = null; $bestSim = 0.0;
        foreach ($rows as $r) {
            $sim = self::similarity($description, $r['description'] ?? '');
            if ($sim > $bestSim) { $bestSim = $sim; $best = $r; }
        }
        if (!$best || $bestSim < 0.4) return null;

        return [
            'category_id'   => $best['category_id'] ? (int)$best['category_id'] : null,
            'category_name' => $best['category_name'],
            'contact_id'    => $best['contact_id'] ? (int)$best['contact_id'] : null,
            'contact_name'  => $best['contact_name'],
            'from'          => $best['description'],
            'similarity'    => round($bestSim, 2),
        ];
    }

    /* ================================================================
     * Text helpers
     * ================================================================ */

    /** Whole days between two Y-m-d dates, or null if either is unusable. */
    public static function dayGap($a, $b)
    {
        $ta = strtotime((string)$a . ' 00:00:00');
        $tb = strtotime((string)$b . ' 00:00:00');
        if ($ta === false || $tb === false || !$a || !$b) return null;
        return (int)round(abs($ta - $tb) / 86400);
    }

    /** Meaningful uppercase tokens, with the bank's boilerplate removed. */
    public static function tokens($s)
    {
        $s = strtoupper((string)$s);
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
        $parts = preg_split('/\s+/', trim($s));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || strlen($p) < 2) continue;
            if (in_array($p, self::NOISE, true)) continue;
            if (ctype_digit($p) && strlen($p) < 4) continue;   // "01", "2 of 3"
            $out[$p] = true;
        }
        return array_keys($out);
    }

    /**
     * How alike two descriptions are, 0..1.
     *
     * Token overlap (Jaccard) rather than string distance, because banks pad
     * the same payee differently every time: "SQ *BLUE BOTTLE 1234" and
     * "BLUE BOTTLE COFFEE" should read as the same shop.
     */
    public static function similarity($a, $b)
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if (!count($ta) || !count($tb)) return 0.0;
        $inter = count(array_intersect($ta, $tb));
        if ($inter === 0) return 0.0;
        $union = count(array_unique(array_merge($ta, $tb)));
        $jaccard = $inter / max(1, $union);
        // Containment matters too: a two-word payee inside a long bank string.
        $containment = $inter / min(count($ta), count($tb));
        return max($jaccard, $containment * 0.85);
    }

    /** Does the haystack name this thing, ignoring punctuation and case? */
    public static function mentions($haystack, $needle)
    {
        $h = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string)$haystack));
        $n = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string)$needle));
        if ($n === '' || strlen($n) < 3 || $h === '') return false;
        return strpos($h, $n) !== false;
    }

    /** Digits of a reference, so "CHQ 000123" and "123" compare equal. */
    public static function refDigits($ref)
    {
        $d = preg_replace('/\D+/', '', (string)$ref);
        $d = ltrim($d, '0');
        return strlen($d) >= 3 ? $d : '';
    }
}
