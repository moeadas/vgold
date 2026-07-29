<?php
/**
 * StatementParser — turn a bank's export into rows VGold can reason about.
 *
 * Banks agree on almost nothing. A statement may be CSV or OFX/QFX; the CSV may
 * carry six lines of preamble before the header, use ; or tab, write amounts as
 * "1.234,56" or "(1,234.56)" or "1234.56 DR", and split money in and money out
 * across two columns instead of one signed one.
 *
 * Two things here are deliberate and worth stating, because both are places a
 * quiet guess would move real money into the wrong month:
 *
 *  1. Dates are inferred from the WHOLE column, never per row. 03/04/2026 is
 *     unknowable alone; a column containing 17/04/2026 is not. When the column
 *     genuinely cannot be resolved, this says so (`date_ambiguous`) and the
 *     caller must ask a human rather than pick.
 *
 *  2. Nothing is written. sniff() reads a sample and proposes a mapping;
 *     rows() applies a mapping the caller has confirmed. Import is a separate,
 *     explicit step.
 */
class StatementParser
{
    /** Refuse absurd files rather than time out mid-parse. */
    const MAX_ROWS = 10000;
    const SAMPLE_ROWS = 8;

    /** Candidate delimiters, most likely first. */
    const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Date layouts we can recognise. `ambiguous` pairs are the ones that need
     * whole-column evidence before either can be chosen.
     */
    const DATE_FORMATS = [
        'Y-m-d'  => '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
        'Y/m/d'  => '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/',
        'd/m/Y'  => '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/',
        'm/d/Y'  => '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/',
        'd-m-Y'  => '/^(\d{1,2})-(\d{1,2})-(\d{4})$/',
        'm-d-Y'  => '/^(\d{1,2})-(\d{1,2})-(\d{4})$/',
        'd.m.Y'  => '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/',
        'd/m/y'  => '/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/',
        'm/d/y'  => '/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/',
        'Ymd'    => '/^(\d{4})(\d{2})(\d{2})$/',
    ];

    /** Header synonyms → the role a column plays. Checked longest-first. */
    const ROLE_HINTS = [
        'date'        => ['transaction date', 'posting date', 'posted date', 'value date', 'book date', 'date posted', 'trans date', 'datum', 'date'],
        'description' => ['transaction description', 'description', 'narrative', 'details', 'particulars', 'memo', 'transaction', 'remarks', 'reason', 'note'],
        'payee'       => ['payee', 'merchant', 'counterparty', 'name', 'beneficiary', 'paid to', 'received from'],
        'amount'      => ['amount', 'value', 'transaction amount', 'betrag', 'montant'],
        'debit'       => ['debit', 'withdrawal', 'withdrawals', 'money out', 'paid out', 'payments', 'out', 'expense', 'dr'],
        'credit'      => ['credit', 'deposit', 'deposits', 'money in', 'paid in', 'receipts', 'in', 'income', 'cr'],
        'balance'     => ['balance', 'running balance', 'closing balance', 'ledger balance'],
        'reference'   => ['reference', 'ref', 'cheque number', 'check number', 'check no', 'cheque no', 'transaction id', 'transaction ref', 'fitid', 'id'],
        'type'        => ['type', 'transaction type', 'dr/cr', 'debit/credit', 'indicator'],
    ];

    /* ================================================================
     * Entry points
     * ================================================================ */

    /**
     * Look at a file and propose how to read it.
     *
     * @return array format, columns, sample, mapping, date_format, date_ambiguous,
     *               rows_total, statement_start/end, closing_balance, warnings
     */
    public static function sniff($path, $originalName = '')
    {
        $head = (string)@file_get_contents($path, false, null, 0, 65536);
        if (trim($head) === '') throw new RuntimeException('That file is empty.');

        if (self::looksLikeOfx($head, $originalName)) return self::sniffOfx($path);
        return self::sniffCsv($path);
    }

    /**
     * Apply a confirmed mapping and return normalised rows.
     *
     * @return array rows[], skipped[], warnings[]
     */
    public static function rows($path, array $mapping)
    {
        if (($mapping['format'] ?? 'csv') === 'ofx') return self::ofxRows($path);
        return self::csvRows($path, $mapping);
    }

    /* ================================================================
     * CSV
     * ================================================================ */

    private static function sniffCsv($path)
    {
        list($delimiter, $grid) = self::readGrid($path);
        if (!count($grid)) throw new RuntimeException('No rows could be read from that file.');

        $headerAt = self::findHeaderRow($grid);
        $header   = $headerAt === null ? [] : $grid[$headerAt];
        $dataFrom = $headerAt === null ? 0 : $headerAt + 1;

        $data = array_slice($grid, $dataFrom);
        $width = self::modalWidth($grid);
        $data = array_values(array_filter($data, function ($r) use ($width) {
            return count(array_filter($r, fn($c) => trim((string)$c) !== '')) > 0 && count($r) >= max(2, $width - 1);
        }));
        if (!count($data)) throw new RuntimeException('The file has a header but no transaction rows.');

        $columns = [];
        for ($i = 0; $i < $width; $i++) {
            $columns[] = [
                'index'  => $i,
                'header' => trim((string)($header[$i] ?? '')),
                'sample' => array_values(array_filter(array_map(
                    fn($r) => trim((string)($r[$i] ?? '')),
                    array_slice($data, 0, 6)
                ), fn($v) => $v !== '')),
            ];
        }

        $mapping = self::suggestMapping($columns, $data, $width);
        $mapping['format'] = 'csv';
        $mapping['delimiter'] = $delimiter;
        $mapping['header_row'] = $headerAt;
        $mapping['data_from'] = $dataFrom;

        $warnings = [];
        if ($mapping['date'] === null) $warnings[] = 'No date column was recognised — choose one below.';
        if ($mapping['amount'] === null && ($mapping['debit'] === null && $mapping['credit'] === null)) {
            $warnings[] = 'No amount column was recognised — choose one, or a pair of money-in / money-out columns.';
        }
        if (!empty($mapping['date_ambiguous'])) {
            $warnings[] = 'Every date in this file could be read as either day/month or month/day. Confirm which your bank uses — getting it wrong moves transactions into the wrong month.';
        }
        if (($mapping['amount_sign'] ?? '') === 'unsigned' && $mapping['type'] === null) {
            $warnings[] = 'No amount in this file is negative, and there is no column saying which way the money went. Point “Money out” at the withdrawals column, or “Debit/credit marker” at the column that distinguishes them — otherwise every row would import as money received.';
        }

        $preview = self::previewRows($data, $mapping, self::SAMPLE_ROWS);

        return [
            'format'         => 'csv',
            'delimiter'      => $delimiter,
            'columns'        => $columns,
            'sample'         => $preview,
            'mapping'        => $mapping,
            'rows_total'     => count($data),
            'warnings'       => $warnings,
        ];
    }

    /** Read the whole file into a grid, choosing the delimiter that fits best. */
    private static function readGrid($path)
    {
        $best = null; $bestDelim = ','; $bestScore = -1;
        foreach (self::DELIMITERS as $d) {
            $grid = self::readWithDelimiter($path, $d);
            if (!count($grid)) continue;
            $width = self::modalWidth($grid);
            if ($width < 2) continue;
            // Prefer the delimiter giving the most columns, consistently.
            $consistent = count(array_filter($grid, fn($r) => count($r) === $width));
            $score = $width * 100 + (int)round(100 * $consistent / max(1, count($grid)));
            if ($score > $bestScore) { $bestScore = $score; $best = $grid; $bestDelim = $d; }
        }
        if ($best === null) throw new RuntimeException('That file does not look like a CSV statement. Try exporting as CSV, OFX or QFX.');
        return [$bestDelim, $best];
    }

    private static function readWithDelimiter($path, $delimiter)
    {
        $rows = [];
        $fh = @fopen($path, 'r');
        if (!$fh) throw new RuntimeException('The uploaded file could not be read.');
        $first = true;
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === false) continue;
            if ($first) {
                // Strip a UTF-8 BOM from the very first cell.
                if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
                $first = false;
            }
            $rows[] = array_map(fn($c) => self::clean((string)$c), $row);
            if (count($rows) > self::MAX_ROWS + 50) break;
        }
        fclose($fh);
        return $rows;
    }

    private static function clean($s)
    {
        $s = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], [' ', ''], $s);
        return trim($s);
    }

    private static function modalWidth(array $grid)
    {
        $counts = [];
        foreach ($grid as $r) { $n = count($r); $counts[$n] = ($counts[$n] ?? 0) + 1; }
        if (!$counts) return 0;
        arsort($counts);
        return (int)array_key_first($counts);
    }

    /**
     * Find the header row: the first row of modal width whose cells look like
     * labels (mostly non-numeric, at least one recognised role) and whose next
     * row does not. Preamble lines are usually narrower, which is why width is
     * checked first.
     */
    private static function findHeaderRow(array $grid)
    {
        $width = self::modalWidth($grid);
        $limit = min(count($grid), 25);
        for ($i = 0; $i < $limit; $i++) {
            $row = $grid[$i];
            if (count($row) !== $width) continue;
            $nonEmpty = array_values(array_filter($row, fn($c) => $c !== ''));
            if (count($nonEmpty) < 2) continue;
            $numeric = count(array_filter($nonEmpty, fn($c) => self::parseAmount($c) !== null || self::looksLikeDate($c)));
            if ($numeric > 0) continue;                       // a data row, not a header
            $roles = 0;
            foreach ($row as $c) if (self::roleFor($c) !== null) $roles++;
            if ($roles >= 2) return $i;
        }
        // Headerless export: only accept that if the first row parses as data.
        $first = $grid[0] ?? [];
        foreach ($first as $c) if (self::looksLikeDate($c)) return null;
        return null;
    }

    private static function roleFor($header)
    {
        $h = strtolower(trim((string)$header));
        $h = preg_replace('/[^a-z0-9\/ ]+/', ' ', $h);
        $h = trim(preg_replace('/\s+/', ' ', $h));
        if ($h === '') return null;
        foreach (self::ROLE_HINTS as $role => $hints) {
            foreach ($hints as $hint) {
                if ($h === $hint) return $role;
            }
        }
        foreach (self::ROLE_HINTS as $role => $hints) {
            foreach ($hints as $hint) {
                if (strlen($hint) >= 3 && strpos($h, $hint) !== false) return $role;
            }
        }
        return null;
    }

    /** Choose which column plays which role, from headers first and shape second. */
    private static function suggestMapping(array $columns, array $data, $width)
    {
        $map = ['date' => null, 'description' => null, 'payee' => null, 'amount' => null,
                'debit' => null, 'credit' => null, 'balance' => null, 'reference' => null,
                'type' => null, 'date_format' => null, 'date_ambiguous' => false,
                'amount_sign' => 'natural'];

        $taken = [];
        foreach ($columns as $c) {
            $role = self::roleFor($c['header']);
            if ($role === null || $map[$role] !== null) continue;
            $map[$role] = $c['index'];
            $taken[$c['index']] = true;
        }

        // No usable headers: fall back to what the values look like.
        if ($map['date'] === null) {
            foreach ($columns as $c) {
                if (isset($taken[$c['index']])) continue;
                $vals = self::columnValues($data, $c['index']);
                if (count($vals) && self::mostlyDates($vals)) { $map['date'] = $c['index']; $taken[$c['index']] = true; break; }
            }
        }
        if ($map['amount'] === null && $map['debit'] === null && $map['credit'] === null) {
            $numericCols = [];
            foreach ($columns as $c) {
                if (isset($taken[$c['index']])) continue;
                $vals = self::columnValues($data, $c['index']);
                if (count($vals) && self::mostlyAmounts($vals)) $numericCols[] = $c['index'];
            }
            // A trailing running balance is the usual second numeric column.
            if (count($numericCols) === 1) $map['amount'] = $numericCols[0];
            elseif (count($numericCols) >= 2) {
                $map['amount'] = $numericCols[0];
                $map['balance'] = end($numericCols);
            }
        }
        if ($map['description'] === null) {
            $bestLen = -1;
            foreach ($columns as $c) {
                if (isset($taken[$c['index']]) || $c['index'] === $map['balance']) continue;
                $vals = self::columnValues($data, $c['index']);
                if (!count($vals) || self::mostlyAmounts($vals) || self::mostlyDates($vals)) continue;
                $len = array_sum(array_map('strlen', $vals)) / count($vals);
                if ($len > $bestLen) { $bestLen = $len; $map['description'] = $c['index']; }
            }
        }

        // Debit/credit pairs are written positive in both columns by most banks;
        // a few write debits negative. Look before deciding.
        if ($map['debit'] !== null) {
            $vals = array_map([self::class, 'parseAmount'], self::columnValues($data, $map['debit']));
            $vals = array_values(array_filter($vals, fn($v) => $v !== null && $v != 0));
            $map['debit_negative'] = count($vals) > 0 && count(array_filter($vals, fn($v) => $v < 0)) >= count($vals) * 0.8;
        }

        if ($map['date'] !== null) {
            $detected = self::detectDateFormat(self::columnValues($data, $map['date']));
            $map['date_format'] = $detected['format'];
            $map['date_ambiguous'] = $detected['ambiguous'];
            if ($detected['ambiguous']) $map['date_alternatives'] = $detected['alternatives'];
        }

        // A single amount column where nothing is negative means the direction of
        // the money is carried elsewhere — usually a Dr/Cr type column. If there
        // is no such column the file cannot be read without being told, and
        // sniffCsv() raises that rather than importing everything as income.
        if ($map['amount'] !== null && $map['debit'] === null && $map['credit'] === null) {
            $vals = array_map([self::class, 'parseAmount'], self::columnValues($data, $map['amount']));
            $vals = array_values(array_filter($vals, fn($v) => $v !== null));
            if (count($vals) && !count(array_filter($vals, fn($v) => $v < 0))) {
                $map['amount_sign'] = 'unsigned';
            }
        }

        return $map;
    }

    private static function columnValues(array $data, $index, $limit = 400)
    {
        $out = [];
        foreach ($data as $r) {
            $v = trim((string)($r[$index] ?? ''));
            if ($v !== '') $out[] = $v;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function mostlyDates(array $vals)
    {
        $hits = count(array_filter($vals, fn($v) => self::looksLikeDate($v)));
        return $hits >= max(1, (int)floor(count($vals) * 0.8));
    }

    private static function mostlyAmounts(array $vals)
    {
        $hits = count(array_filter($vals, fn($v) => self::parseAmount($v) !== null));
        return $hits >= max(1, (int)floor(count($vals) * 0.8));
    }

    private static function looksLikeDate($v)
    {
        $v = trim((string)$v);
        if ($v === '') return false;
        foreach (self::DATE_FORMATS as $re) if (preg_match($re, $v)) return true;
        return (bool)preg_match('/^\d{1,2}[ \-\/][A-Za-z]{3,9}[ \-\/]\d{2,4}$/', $v)
            || (bool)preg_match('/^[A-Za-z]{3,9} \d{1,2},? \d{4}$/', $v);
    }

    /* ---------------- dates ---------------- */

    /**
     * Decide a column's date layout from all of its values.
     *
     * Returns ambiguous=true only when day/month order genuinely cannot be told
     * apart — never a coin flip dressed up as a decision.
     */
    public static function detectDateFormat(array $values)
    {
        $values = array_values(array_filter(array_map('trim', $values), fn($v) => $v !== ''));
        if (!count($values)) return ['format' => null, 'ambiguous' => false, 'alternatives' => []];

        $scores = [];
        foreach (array_keys(self::DATE_FORMATS) as $fmt) $scores[$fmt] = 0;
        $textual = 0;

        foreach ($values as $v) {
            if (preg_match('/[A-Za-z]{3}/', $v)) { $textual++; continue; }
            foreach (self::DATE_FORMATS as $fmt => $re) {
                if (self::applyFormat($v, $fmt) !== null) $scores[$fmt]++;
            }
        }

        $n = count($values);
        if ($textual >= $n * 0.8) return ['format' => 'textual', 'ambiguous' => false, 'alternatives' => []];

        $full = array_keys(array_filter($scores, fn($s) => $s >= $n - $textual && $s > 0));
        if (!count($full)) {
            arsort($scores);
            $best = array_key_first($scores);
            return ['format' => $scores[$best] > 0 ? $best : null, 'ambiguous' => false, 'alternatives' => []];
        }

        // Unambiguous layouts win outright when they alone parse everything.
        $dmy = ['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y'];
        $mdy = ['m/d/Y', 'm-d-Y', 'm/d/y'];
        $inD = array_values(array_intersect($full, $dmy));
        $inM = array_values(array_intersect($full, $mdy));

        if (count($inD) && count($inM)) {
            // Both survive: no value had a component above 12 to break the tie.
            return ['format' => null, 'ambiguous' => true, 'alternatives' => [$inD[0], $inM[0]]];
        }
        if (count($inD)) return ['format' => $inD[0], 'ambiguous' => false, 'alternatives' => []];
        if (count($inM)) return ['format' => $inM[0], 'ambiguous' => false, 'alternatives' => []];
        return ['format' => $full[0], 'ambiguous' => false, 'alternatives' => []];
    }

    /** Parse one value in a known layout. Returns Y-m-d or null. */
    public static function applyFormat($value, $format)
    {
        $value = trim((string)$value);
        if ($value === '') return null;

        if ($format === 'textual' || $format === null) return self::parseTextualDate($value);

        $re = self::DATE_FORMATS[$format] ?? null;
        if (!$re || !preg_match($re, $value, $m)) return null;

        switch ($format) {
            case 'Y-m-d': case 'Y/m/d': case 'Ymd':
                list($y, $mo, $d) = [(int)$m[1], (int)$m[2], (int)$m[3]]; break;
            case 'd/m/Y': case 'd-m-Y': case 'd.m.Y':
                list($d, $mo, $y) = [(int)$m[1], (int)$m[2], (int)$m[3]]; break;
            case 'm/d/Y': case 'm-d-Y':
                list($mo, $d, $y) = [(int)$m[1], (int)$m[2], (int)$m[3]]; break;
            case 'd/m/y':
                list($d, $mo, $y) = [(int)$m[1], (int)$m[2], self::century((int)$m[3])]; break;
            case 'm/d/y':
                list($mo, $d, $y) = [(int)$m[1], (int)$m[2], self::century((int)$m[3])]; break;
            default: return null;
        }
        if (!checkdate($mo, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    private static function century($yy)
    {
        // Statements are recent; 70+ is last century, everything else this one.
        return $yy >= 70 ? 1900 + $yy : 2000 + $yy;
    }

    private static function parseTextualDate($value)
    {
        $t = strtotime($value);
        if ($t === false) return null;
        return date('Y-m-d', $t);
    }

    /* ---------------- amounts ---------------- */

    /**
     * Parse a bank-formatted amount. Returns a float, or null when the value is
     * not an amount at all (so callers can tell "zero" from "not a number").
     *
     * Handles: 1,234.56 · 1.234,56 · (1,234.56) · -1234.56 · 1234.56 CR ·
     * $1,234.56 · 1 234,56 · 1234.56- (trailing minus, common in German exports)
     */
    public static function parseAmount($raw)
    {
        $s = trim((string)$raw);
        if ($s === '') return null;
        $s = str_replace(["\xC2\xA0", ' '], '', $s);

        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        if (preg_match('/(CR|DR)$/i', $s, $m)) {
            if (strtoupper($m[1]) === 'DR') $neg = true;
            $s = preg_replace('/(CR|DR)$/i', '', $s);
        }
        // Currency symbols and codes, leading or trailing.
        $s = preg_replace('/^[^\d\-+.,()]+/u', '', $s);
        $s = preg_replace('/[^\d\-+.,]+$/u', '', $s);
        if ($s === '') return null;
        if (substr($s, -1) === '-') { $neg = true; $s = substr($s, 0, -1); }
        if (substr($s, 0, 1) === '-') { $neg = !$neg; $s = substr($s, 1); }
        if (substr($s, 0, 1) === '+') $s = substr($s, 1);
        if ($s === '' || !preg_match('/^[\d.,]+$/', $s)) return null;
        if (!preg_match('/\d/', $s)) return null;

        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Whichever comes last is the decimal point.
            $dec = $lastDot > $lastComma ? '.' : ',';
            $grp = $dec === '.' ? ',' : '.';
            $s = str_replace($grp, '', $s);
            $s = str_replace($dec, '.', $s);
        } elseif ($lastComma !== false) {
            $after = strlen($s) - $lastComma - 1;
            $count = substr_count($s, ',');
            // "1,234" is thousands; "1,23" and "1,234,56" are not.
            $s = ($count === 1 && $after !== 3) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($lastDot !== false) {
            $after = strlen($s) - $lastDot - 1;
            $count = substr_count($s, '.');
            if ($count > 1 || ($after === 3 && preg_match('/^\d{1,3}(\.\d{3})+$/', $s))) $s = str_replace('.', '', $s);
        }

        if (!is_numeric($s)) return null;
        $v = (float)$s;
        return $neg ? -$v : $v;
    }

    /* ---------------- applying a mapping ---------------- */

    private static function previewRows(array $data, array $mapping, $limit)
    {
        $out = [];
        foreach ($data as $r) {
            $row = self::mapRow($r, $mapping);
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function csvRows($path, array $mapping)
    {
        $delimiter = $mapping['delimiter'] ?? ',';
        $grid = self::readWithDelimiter($path, $delimiter);
        $from = (int)($mapping['data_from'] ?? 0);
        $data = array_slice($grid, $from);

        $rows = []; $skipped = [];
        foreach ($data as $i => $r) {
            if (!count(array_filter($r, fn($c) => trim((string)$c) !== ''))) continue;
            $row = self::mapRow($r, $mapping);
            if ($row['error']) { $skipped[] = ['line' => $from + $i + 1, 'reason' => $row['error'], 'raw' => implode(' | ', array_slice($r, 0, 6))]; continue; }
            unset($row['error']);
            $rows[] = $row;
            if (count($rows) >= self::MAX_ROWS) {
                $skipped[] = ['line' => $from + $i + 1, 'reason' => 'Only the first ' . self::MAX_ROWS . ' rows of a statement are imported.', 'raw' => ''];
                break;
            }
        }
        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** Turn one raw CSV row into the shape acc_bank_lines stores. */
    private static function mapRow(array $r, array $mapping)
    {
        $cell = function ($key) use ($r, $mapping) {
            $i = $mapping[$key] ?? null;
            if ($i === null || $i === '') return null;
            $v = $r[(int)$i] ?? null;
            return $v === null ? null : trim((string)$v);
        };

        $error = null;
        $rawDate = $cell('date');
        $date = null;
        if ($rawDate === null || $rawDate === '') {
            $error = 'no date';
        } else {
            $date = self::applyFormat($rawDate, $mapping['date_format'] ?? null);
            if ($date === null) $date = self::parseTextualDate($rawDate);
            if ($date === null) $error = 'the date "' . mb_substr($rawDate, 0, 24) . '" could not be read';
        }

        $amount = null;
        if (($mapping['debit'] ?? null) !== null || ($mapping['credit'] ?? null) !== null) {
            $d = self::parseAmount($cell('debit'));
            $c = self::parseAmount($cell('credit'));
            $d = $d === null ? 0.0 : ($mapping['debit_negative'] ?? false ? $d : -abs($d));
            $c = $c === null ? 0.0 : abs($c);
            $amount = $c + $d;
            if ($c == 0.0 && $d == 0.0) $amount = null;
        } else {
            $amount = self::parseAmount($cell('amount'));
            if ($amount !== null && ($mapping['amount_sign'] ?? 'natural') === 'unsigned') {
                $type = strtolower((string)$cell('type'));
                if ($type !== '' && preg_match('/^(d|dr|debit|withdrawal|out|payment)/', $type)) $amount = -abs($amount);
                elseif ($type !== '' && preg_match('/^(c|cr|credit|deposit|in|receipt)/', $type)) $amount = abs($amount);
            }
        }
        if ($amount === null && $error === null) $error = 'no amount';
        if ($amount !== null && abs($amount) < 0.0000001) $error = $error ?: 'zero amount';

        $desc = trim(implode(' ', array_values(array_filter([$cell('description'), $cell('payee')], fn($v) => $v !== null && $v !== ''))));

        return [
            'posted_at'   => $date,
            'amount'      => $amount === null ? null : round($amount, 4),
            'description' => mb_substr($desc, 0, 500),
            'payee'       => $cell('payee') ? mb_substr($cell('payee'), 0, 191) : null,
            'reference'   => $cell('reference') ? mb_substr($cell('reference'), 0, 100) : null,
            'balance'     => self::parseAmount($cell('balance')),
            'fitid'       => null,
            'error'       => $error,
        ];
    }

    /* ================================================================
     * OFX / QFX
     * ================================================================ */

    private static function looksLikeOfx($head, $originalName)
    {
        $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['ofx', 'qfx'], true)) return true;
        return stripos($head, '<OFX') !== false || stripos($head, 'OFXHEADER') !== false;
    }

    private static function sniffOfx($path)
    {
        $parsed = self::ofxRows($path);
        $rows = $parsed['rows'];
        if (!count($rows)) throw new RuntimeException('No transactions were found in that OFX file.');

        $dates = array_values(array_filter(array_column($rows, 'posted_at')));
        sort($dates);

        return [
            'format'           => 'ofx',
            'delimiter'        => null,
            'columns'          => [],
            'sample'           => array_slice($rows, 0, self::SAMPLE_ROWS),
            'mapping'          => ['format' => 'ofx', 'date_format' => 'Y-m-d', 'date_ambiguous' => false],
            'rows_total'       => count($rows),
            'statement_start'  => $parsed['statement_start'] ?: ($dates[0] ?? null),
            'statement_end'    => $parsed['statement_end'] ?: (count($dates) ? end($dates) : null),
            'closing_balance'  => $parsed['closing_balance'],
            'account_hint'     => $parsed['account_hint'],
            'warnings'         => [],
        ];
    }

    /**
     * OFX 1.x is SGML with unclosed tags; OFX 2.x is XML. One line-oriented
     * reader handles both, because in either case a value follows its tag on
     * the same line.
     */
    public static function ofxRows($path)
    {
        $raw = (string)@file_get_contents($path);
        if ($raw === '') throw new RuntimeException('The uploaded file could not be read.');

        // OFX 1.x puts headers before the <OFX> body; drop them.
        $pos = stripos($raw, '<OFX');
        if ($pos !== false) $raw = substr($raw, $pos);
        $raw = preg_replace('/\r\n?/', "\n", $raw);
        // Give every tag its own line so unclosed SGML tags read the same as XML.
        $raw = preg_replace('/></', ">\n<", $raw);
        $raw = str_replace('<', "\n<", $raw);

        $rows = []; $cur = null;
        $closing = null; $start = null; $end = null; $acct = null; $inLedger = false;

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '<') continue;
            if (!preg_match('/^<([A-Za-z0-9._]+)>\s*(.*)$/s', $line, $m)) {
                if (preg_match('/^<\/([A-Za-z0-9._]+)>/', $line, $c)) {
                    $tag = strtoupper($c[1]);
                    if ($tag === 'STMTTRN' && $cur !== null) { $rows[] = self::finishOfxRow($cur); $cur = null; }
                    if ($tag === 'LEDGERBAL') $inLedger = false;
                }
                continue;
            }
            $tag = strtoupper($m[1]);
            $val = self::decodeOfx(trim($m[2]));
            // A value may run to the closing tag on the same line (XML form).
            $val = preg_replace('/<\/[A-Za-z0-9._]+>.*$/s', '', $val);
            $val = trim($val);

            if ($tag === 'STMTTRN') { if ($cur !== null) $rows[] = self::finishOfxRow($cur); $cur = []; continue; }
            if ($tag === 'LEDGERBAL') { $inLedger = true; continue; }

            if ($cur !== null) {
                $cur[$tag] = ($cur[$tag] ?? '') === '' ? $val : $cur[$tag] . ' ' . $val;
                continue;
            }
            if ($tag === 'BALAMT' && $inLedger) $closing = self::parseAmount($val);
            elseif ($tag === 'DTSTART') $start = self::ofxDate($val);
            elseif ($tag === 'DTEND')   $end = self::ofxDate($val);
            elseif ($tag === 'ACCTID')  $acct = $val;
        }
        if ($cur !== null) $rows[] = self::finishOfxRow($cur);

        $skipped = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            if ($r['posted_at'] === null) { $skipped[] = ['line' => $i + 1, 'reason' => 'no usable date', 'raw' => (string)$r['description']]; continue; }
            if ($r['amount'] === null || abs($r['amount']) < 0.0000001) { $skipped[] = ['line' => $i + 1, 'reason' => 'no amount', 'raw' => (string)$r['description']]; continue; }
            $clean[] = $r;
        }

        return [
            'rows' => $clean, 'skipped' => $skipped,
            'closing_balance' => $closing, 'statement_start' => $start, 'statement_end' => $end,
            'account_hint' => $acct,
        ];
    }

    private static function finishOfxRow(array $t)
    {
        $name = trim(($t['NAME'] ?? '') . ' ' . ($t['MEMO'] ?? ''));
        if ($name === '') $name = trim((string)($t['TRNTYPE'] ?? ''));
        return [
            'posted_at'   => self::ofxDate($t['DTPOSTED'] ?? ($t['DTUSER'] ?? '')),
            'amount'      => self::parseAmount($t['TRNAMT'] ?? ''),
            'description' => mb_substr($name, 0, 500),
            'payee'       => isset($t['NAME']) && $t['NAME'] !== '' ? mb_substr($t['NAME'], 0, 191) : null,
            'reference'   => mb_substr(trim((string)($t['CHECKNUM'] ?? ($t['REFNUM'] ?? ''))), 0, 100) ?: null,
            'balance'     => null,
            'fitid'       => mb_substr(trim((string)($t['FITID'] ?? '')), 0, 120) ?: null,
        ];
    }

    /** OFX dates are YYYYMMDD with an optional time and [tz] suffix. */
    private static function ofxDate($v)
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) return null;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private static function decodeOfx($v)
    {
        return html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /* ================================================================
     * Dedup keys
     * ================================================================ */

    /**
     * The identity of a statement line, for spotting a re-uploaded overlap.
     *
     * Two genuinely separate £3.20 coffees on the same day share a key — which
     * is correct. Counting how many of a key already exist, rather than testing
     * for existence, is what keeps both of them.
     */
    public static function dedupeKey($accountId, array $row)
    {
        if (!empty($row['fitid'])) return sha1('fitid|' . (int)$accountId . '|' . $row['fitid']);
        $desc = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$row['description']));
        return sha1(implode('|', [
            'row', (int)$accountId, (string)$row['posted_at'],
            (string)round((float)$row['amount'] * 100), mb_substr($desc, 0, 60),
        ]));
    }
}
