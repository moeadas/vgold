<?php
/**
 * VGold — daily backups.
 *
 * One archive per run containing, in order of usefulness when something has
 * gone wrong:
 *
 *   database.sql.gz  a mysqldump — the only artifact that restores faithfully.
 *                    79 related tables with foreign keys, typed columns, NULL
 *                    versus empty string and auto-increment counters do not
 *                    survive a round trip through CSV.
 *   csv/             one file per table, for reading in Excel or moving data
 *                    somewhere that is not MySQL.
 *   uploads.tar.gz   the attachment files the database rows point at.
 *   manifest.json    which commit was live, which app version, row counts and
 *                    checksums — so a restored database can be matched to the
 *                    exact code that wrote it.
 *
 * The archive is copied to SharePoint through the Graph app the rest of VGold
 * already uses, which is a different provider from the web host: losing the
 * server does not lose the backups.
 */
class Backup {

    const DIR            = 'storage/backups';
    const REMOTE_FOLDER  = 'VGoldBackups';
    const KEEP_LOCAL     = 7;   // days kept on the server for a fast restore
    const KEEP_REMOTE    = 14;  // daily archives kept off-server
    const KEEP_WEEKLY    = 8;   // Sunday archives kept beyond that

    /* ------------------------------------------------------------------ */

    /**
     * Where archives live.
     *
     * These files are the entire database. The document root is the repository
     * root on this host, so anything under storage/ is one guessed URL away from
     * being downloaded — prefer a directory beside the docroot, and only fall
     * back inside it with a deny rule and unguessable filenames.
     */
    public static function dir() {
        static $resolved = null;
        if ($resolved) return $resolved;

        $outside = dirname(dirname(__DIR__, 2)) . '/vgold-backups';
        if (is_dir($outside) ? is_writable($outside) : @mkdir($outside, 0750, true)) {
            return $resolved = $outside;
        }

        $inside = dirname(__DIR__, 2) . '/' . self::DIR;
        if (!is_dir($inside)) @mkdir($inside, 0750, true);
        $ht = $inside . '/.htaccess';
        if (is_dir($inside) && !is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        return $resolved = $inside;
    }

    /** True when archives are sitting inside the document root. */
    public static function dirIsExposed() {
        $root = realpath(dirname(__DIR__, 2));
        $dir  = realpath(self::dir());
        return $root && $dir && strpos($dir, $root) === 0;
    }

    /** Run a full backup. Returns the run row. Never throws — records instead. */
    public static function run(array $opts = []) {
        Schema::ensureBackups();
        @set_time_limit(900);

        $trigger = $opts['trigger'] ?? 'manual';
        $started = date('Y-m-d H:i:s');
        $runId = DB::insert('backup_runs', [
            'started_at' => $started,
            'status'     => 'running',
            'run_trigger' => $trigger,
        ]);

        $work = self::dir() . '/tmp-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $steps = [];
        try {
            if (!@mkdir($work, 0750, true)) throw new Exception('Could not create a working directory under ' . self::DIR);

            $steps['database'] = self::dumpDatabase($work . '/database.sql.gz');
            $steps['csv']      = self::dumpCsv($work . '/csv');
            $steps['uploads']  = self::dumpUploads($work . '/uploads.tar.gz');

            $manifest = self::manifest($steps);
            file_put_contents($work . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($work . '/RESTORE.md', self::restoreDoc($manifest));

            // The random suffix means the archive cannot be found by guessing a
            // date, even if a web-server rule is ever misconfigured.
            $name    = 'vgold-backup-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(5)) . '.zip';
            $archive = self::dir() . '/' . $name;
            self::zip($work, $archive);
            self::rmdir($work);

            $bytes  = filesize($archive);
            $sha256 = hash_file('sha256', $archive);

            $remote = null; $remoteError = null;
            try {
                $remote = self::uploadToSharePoint($archive, $name);
            } catch (\Throwable $e) {
                // A backup that exists only locally is still a backup. Record the
                // failure loudly rather than failing the whole run.
                $remoteError = $e->getMessage();
                error_log('Backup: off-server copy failed: ' . $remoteError);
            }

            self::pruneLocal();
            if ($remote) { try { self::pruneRemote(); } catch (\Throwable $e) { error_log('Backup prune remote: ' . $e->getMessage()); } }

            DB::update('backup_runs', [
                'finished_at'  => date('Y-m-d H:i:s'),
                'status'       => $remoteError ? 'local_only' : 'ok',
                'bytes'        => $bytes,
                'file_name'    => $name,
                'sha256'       => $sha256,
                'remote_path'  => $remote,
                'error'        => $remoteError,
                'details'      => json_encode($steps),
            ], 'id = ?', [$runId]);

        } catch (\Throwable $e) {
            self::rmdir($work);
            DB::update('backup_runs', [
                'finished_at' => date('Y-m-d H:i:s'),
                'status'      => 'failed',
                'error'       => mb_substr($e->getMessage(), 0, 1000),
                'details'     => json_encode($steps),
            ], 'id = ?', [$runId]);
            error_log('Backup failed: ' . $e->getMessage());
        }

        return DB::fetch("SELECT * FROM backup_runs WHERE id = ?", [$runId]);
    }

    /* ---------------------------- the database ------------------------- */

    /**
     * mysqldump, gzipped.
     *
     * The password goes in a 0600 defaults-file rather than on the command
     * line, where every other process on a shared host could read it out of ps.
     */
    private static function dumpDatabase($outPath) {
        $bin = self::which('mysqldump');
        if (!$bin) throw new Exception('mysqldump is not available on this server.');

        $cnf = self::dir() . '/.my-' . bin2hex(random_bytes(6)) . '.cnf';
        $ok = file_put_contents($cnf, "[client]\nuser=" . DB_USER . "\npassword=\"" . str_replace('"', '\\"', DB_PASS) . "\"\nhost=" . DB_HOST . "\nport=" . DB_PORT . "\n");
        if ($ok === false) throw new Exception('Could not write the temporary database credentials file.');
        @chmod($cnf, 0600);

        try {
            // --single-transaction: consistent snapshot without locking anyone out.
            // --no-tablespaces: shared hosting rarely grants the PROCESS privilege.
            $base = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf)
                  . ' --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4'
                  . ' --routines --triggers --add-drop-table --skip-lock-tables';

            $attempts = [
                $base . ' --events ',   // events need extra privileges on some hosts
                $base . ' ',
            ];
            $lastErr = '';
            foreach ($attempts as $cmd) {
                $full = $cmd . escapeshellarg(DB_NAME) . ' 2>' . escapeshellarg($cnf . '.err')
                      . ' | ' . escapeshellarg(self::which('gzip') ?: 'gzip') . ' -9 > ' . escapeshellarg($outPath);
                @exec($full, $out, $rc);
                $lastErr = trim((string)@file_get_contents($cnf . '.err'));
                @unlink($cnf . '.err');
                if ($rc === 0 && is_file($outPath) && filesize($outPath) > 100) {
                    // gzip returns 0 even when mysqldump upstream failed, so check
                    // the dump actually ends the way a complete dump ends.
                    if (!self::gzipTailHas($outPath, 'Dump completed')) {
                        $lastErr = $lastErr ?: 'The dump ended early — it is incomplete.';
                        @unlink($outPath);
                        continue;
                    }
                    return ['bytes' => filesize($outPath), 'tool' => trim((string)@shell_exec(escapeshellarg($bin) . ' --version 2>&1'))];
                }
                @unlink($outPath);
            }
            throw new Exception('mysqldump failed: ' . ($lastErr ?: 'no output'));
        } finally {
            @unlink($cnf);
        }
    }

    /** Does the gzipped dump end with mysqldump's completion marker? */
    private static function gzipTailHas($path, $needle) {
        $fh = @gzopen($path, 'rb');
        if (!$fh) return false;
        $tail = '';
        while (!gzeof($fh)) {
            $chunk = gzread($fh, 65536);
            if ($chunk === false) break;
            $tail = substr($tail . $chunk, -4096);
        }
        gzclose($fh);
        return strpos($tail, $needle) !== false;
    }

    /** One CSV per table — for reading and for moving data somewhere else. */
    private static function dumpCsv($dir) {
        if (!@mkdir($dir, 0750, true)) throw new Exception('Could not create the CSV directory.');
        $tables = array_column(DB::fetchAll(
            "SELECT TABLE_NAME t FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 't');

        $rows = 0; $written = 0;
        foreach ($tables as $t) {
            $fh = @fopen($dir . '/' . preg_replace('/[^A-Za-z0-9_]/', '_', $t) . '.csv', 'w');
            if (!$fh) continue;
            fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
            try {
                $stmt = DB::conn()->query("SELECT * FROM `$t`");
                $header = false;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!$header) { fputcsv($fh, array_keys($row)); $header = true; }
                    // NULL and '' are different things; keep them distinguishable.
                    fputcsv($fh, array_map(fn($v) => $v === null ? '\\N' : $v, array_values($row)));
                    $rows++;
                }
                if (!$header) {
                    $cols = array_column(DB::fetchAll("SHOW COLUMNS FROM `$t`"), 'Field');
                    if ($cols) fputcsv($fh, $cols);
                }
                $written++;
            } catch (\Throwable $e) {
                error_log('Backup CSV ' . $t . ': ' . $e->getMessage());
            }
            fclose($fh);
        }
        return ['tables' => $written, 'rows' => $rows];
    }

    /** The uploaded files the database rows refer to. */
    private static function dumpUploads($outPath) {
        $root = dirname(__DIR__, 2);
        $dirs = array_values(array_filter(['uploads', 'storage/uploads'], fn($d) => is_dir($root . '/' . $d)));
        if (!$dirs) return ['bytes' => 0, 'note' => 'no upload directories present'];

        $tar = self::which('tar');
        if (!$tar) return ['bytes' => 0, 'note' => 'tar unavailable — files not included'];

        $cmd = escapeshellarg($tar) . ' -czf ' . escapeshellarg($outPath)
             . ' -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $dirs)) . ' 2>&1';
        @exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($outPath)) {
            return ['bytes' => 0, 'note' => 'tar failed: ' . implode(' ', array_slice($out, 0, 3))];
        }
        return ['bytes' => filesize($outPath), 'dirs' => $dirs];
    }

    /* ---------------------------- the manifest ------------------------- */

    /** Which code was live, and what the data looked like at that moment. */
    private static function manifest(array $steps) {
        $counts = [];
        try {
            foreach (DB::fetchAll("SELECT TABLE_NAME t, TABLE_ROWS n FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $r) {
                $counts[$r['t']] = (int)$r['n'];
            }
        } catch (\Throwable $e) { /* approximate counts are a nicety */ }

        return [
            'created_at'   => date('c'),
            'app_version'  => defined('APP_VERSION') ? APP_VERSION : null,
            'app_build'    => defined('APP_BUILD') ? APP_BUILD : null,
            'asset_version'=> defined('ASSET_VERSION') ? ASSET_VERSION : null,
            'deployed_sha' => CodeVersion::deployedSha(),
            'repo'         => CodeVersion::REPO,
            'database'     => defined('DB_NAME') ? DB_NAME : null,
            'php'          => PHP_VERSION,
            'server'       => $_SERVER['HTTP_HOST'] ?? gethostname(),
            'steps'        => $steps,
            'table_rows'   => $counts,
        ];
    }

    private static function restoreDoc(array $m) {
        $sha = $m['deployed_sha'] ?: '<the sha this backup was taken at>';
        return <<<MD
# Restoring VGold from this backup

Taken {$m['created_at']} from VGold {$m['app_version']} (build {$m['app_build']}).
Code commit: {$sha}

## 1. Put the same code back

    git clone https://github.com/{$m['repo']}.git vgold
    cd vgold
    git checkout {$sha}

Then restore `config/database.php` (or `config/database.sg.php`), `config/graph.php`
and `config/app_key.php` — these are deliberately NOT in the repo and NOT in this
backup, because they hold credentials. Keep them in your password manager.

> `config/app_key.php` matters: SMTP passwords and API keys in the database are
> encrypted with it. Without the same key those values cannot be decrypted and
> must be re-entered.

## 2. Restore the database

    gunzip < database.sql.gz | mysql --default-character-set=utf8mb4 -u USER -p DATABASE

The charset flag is not optional — without it a client that negotiates latin1
will silently mangle every accented and non-Latin character on the way in.

That is the whole restore. The dump recreates every table, its data, foreign
keys, triggers and auto-increment counters exactly as they were.

## 3. Restore the uploaded files

    tar -xzf uploads.tar.gz -C /path/to/vgold

## 4. Check it

Sign in and compare against `manifest.json`, which lists the row counts each
table had when this backup was taken.

## About the CSV folder

`csv/` holds one file per table for reading in Excel or importing elsewhere.
It is NOT the restore path — CSV cannot carry foreign keys, column types, the
difference between NULL and an empty string, or insert ordering. `\\N` in a CSV
cell means SQL NULL. Restore from `database.sql.gz`; use the CSVs to read.
MD;
    }

    /* ---------------------------- packaging ---------------------------- */

    private static function zip($sourceDir, $archivePath) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Could not create the archive.');
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($sourceDir) + 1));
            }
            $zip->close();
        } else {
            $bin = self::which('zip');
            if (!$bin) throw new Exception('Neither the zip extension nor the zip command is available.');
            @exec('cd ' . escapeshellarg($sourceDir) . ' && ' . escapeshellarg($bin) . ' -qr ' . escapeshellarg($archivePath) . ' . 2>&1', $o, $rc);
            if ($rc !== 0) throw new Exception('zip failed: ' . implode(' ', array_slice($o, 0, 3)));
        }
        if (!is_file($archivePath) || filesize($archivePath) < 100) throw new Exception('The archive came out empty.');
        @chmod($archivePath, 0640);
    }

    /* ------------------------- the off-server copy ---------------------- */

    /** Copy the archive to SharePoint through the existing app-only Graph app. */
    public static function uploadToSharePoint($localPath, $name) {
        $cfgFile = dirname(__DIR__, 2) . '/config/graph.php';
        if (!is_file($cfgFile)) throw new Exception('config/graph.php is missing, so there is nowhere off-server to copy to.');
        $cfg = require $cfgFile;
        if (empty($cfg['drive_id'])) throw new Exception('No SharePoint drive_id is configured.');

        require_once __DIR__ . '/Graph.php';
        $remote = self::REMOTE_FOLDER . '/' . date('Y-m') . '/' . $name;
        $encoded = implode('/', array_map('rawurlencode', explode('/', $remote)));
        $drive = $cfg['drive_id'];
        $size = filesize($localPath);

        if ($size < 4 * 1024 * 1024) {
            $resp = Graph::request('PUT', "/drives/$drive/root:/$encoded:/content",
                file_get_contents($localPath), ['Content-Type: application/zip']);
            if (($resp['code'] ?? 0) >= 300) throw new Exception('SharePoint rejected the upload (HTTP ' . ($resp['code'] ?? '?') . '): ' . substr((string)($resp['body'] ?? ''), 0, 200));
            return $remote;
        }

        // Larger archives go up in chunks so memory stays flat.
        $create = Graph::request('POST', "/drives/$drive/root:/$encoded:/createUploadSession",
            json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]), ['Content-Type: application/json']);
        if (($create['code'] ?? 0) >= 300) throw new Exception('Could not start the upload session: ' . substr((string)($create['body'] ?? ''), 0, 200));
        $url = json_decode($create['body'], true)['uploadUrl'] ?? null;
        if (!$url) throw new Exception('SharePoint did not return an upload URL.');

        $chunk = 5 * 1024 * 1024;
        $fh = fopen($localPath, 'rb');
        for ($off = 0; $off < $size; $off += $chunk) {
            $data = fread($fh, $chunk);
            $end  = $off + strlen($data) - 1;
            $r = Graph::rawCall('PUT', $url, $data, [
                'Content-Length: ' . strlen($data),
                "Content-Range: bytes $off-$end/$size",
            ]);
            if (($r['code'] ?? 0) >= 300) { fclose($fh); throw new Exception('Chunk upload failed (HTTP ' . ($r['code'] ?? '?') . ')'); }
        }
        fclose($fh);
        return $remote;
    }

    /* ---------------------------- retention ---------------------------- */

    private static function pruneLocal() {
        $files = glob(self::dir() . '/vgold-backup-*.zip') ?: [];
        if (count($files) <= self::KEEP_LOCAL) return;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::KEEP_LOCAL) as $old) @unlink($old);
    }

    /**
     * Keep the last KEEP_REMOTE dailies, plus KEEP_WEEKLY Sunday archives so a
     * problem noticed weeks later is still recoverable.
     */
    private static function pruneRemote() {
        $cfg = require dirname(__DIR__, 2) . '/config/graph.php';
        $drive = $cfg['drive_id'] ?? null;
        if (!$drive) return;
        require_once __DIR__ . '/Graph.php';

        $all = [];
        foreach (self::remoteMonths($drive) as $month) {
            $path = implode('/', array_map('rawurlencode', [self::REMOTE_FOLDER, $month]));
            $r = Graph::request('GET', "/drives/$drive/root:/$path:/children?\$top=200");
            if (($r['code'] ?? 0) >= 300) continue;
            foreach (json_decode($r['body'], true)['value'] ?? [] as $item) {
                if (empty($item['file'])) continue;
                if (!preg_match('/vgold-backup-(\d{4})-(\d{2})-(\d{2})/', $item['name'], $m)) continue;
                $all[] = ['id' => $item['id'], 'name' => $item['name'], 'date' => "$m[1]-$m[2]-$m[3]"];
            }
        }
        if (count($all) <= self::KEEP_REMOTE) return;
        usort($all, fn($a, $b) => strcmp($b['name'], $a['name']));

        $keep = array_slice($all, 0, self::KEEP_REMOTE);
        $keepIds = array_column($keep, 'id');
        $weekly = 0;
        foreach (array_slice($all, self::KEEP_REMOTE) as $item) {
            $isSunday = (int)date('w', strtotime($item['date'])) === 0;
            if ($isSunday && $weekly < self::KEEP_WEEKLY) { $weekly++; $keepIds[] = $item['id']; }
        }
        foreach ($all as $item) {
            if (in_array($item['id'], $keepIds, true)) continue;
            Graph::request('DELETE', "/drives/$drive/items/" . $item['id']);
        }
    }

    private static function remoteMonths($drive) {
        $r = Graph::request('GET', '/drives/' . $drive . '/root:/' . rawurlencode(self::REMOTE_FOLDER) . ':/children?$top=200');
        if (($r['code'] ?? 0) >= 300) return [];
        $out = [];
        foreach (json_decode($r['body'], true)['value'] ?? [] as $item) {
            if (!empty($item['folder']) && preg_match('/^\d{4}-\d{2}$/', $item['name'])) $out[] = $item['name'];
        }
        return $out;
    }

    /* ------------------------------ helpers ---------------------------- */

    public static function which($bin) {
        $p = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        return $p ? trim($p) : null;
    }

    private static function rmdir($d) {
        if (!is_dir($d)) return;
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d . '/' . $f;
            is_dir($p) ? self::rmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    /** Recent runs, for the Settings panel. */
    public static function history($limit = 20) {
        Schema::ensureBackups();
        $rows = DB::fetchAll("SELECT * FROM backup_runs ORDER BY id DESC LIMIT " . (int)$limit);
        foreach ($rows as &$r) {
            $r['exists_locally'] = $r['file_name'] && is_file(self::dir() . '/' . $r['file_name']);
        }
        return $rows;
    }

    /** Has a successful backup happened in the last 26 hours? */
    public static function isHealthy() {
        try {
            Schema::ensureBackups();
            $r = DB::fetch("SELECT finished_at FROM backup_runs
                             WHERE status IN ('ok','local_only') AND finished_at > (NOW() - INTERVAL 26 HOUR)
                             ORDER BY id DESC LIMIT 1");
            return (bool)$r;
        } catch (\Throwable $e) { return false; }
    }

    /** The secret that authorises the daily cron call. Created on first use. */
    public static function cronSecret() {
        Schema::ensureBackups();
        $row = DB::fetch("SELECT setting_value v FROM workspace_settings WHERE setting_group = 'backup' AND setting_key = 'cron_secret' LIMIT 1");
        if ($row && !empty($row['v'])) return $row['v'];
        $secret = bin2hex(random_bytes(24));
        DB::query("INSERT INTO workspace_settings (workspace_id, setting_group, setting_key, setting_value)
                   VALUES (0, 'backup', 'cron_secret', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$secret]);
        return $secret;
    }
}
