<?php
/**
 * VGold — backup status, manual runs, downloads, and the code/repo check.
 * Admin only: these expose the whole database and the server's file layout.
 */
class BackupController {

    public static function index()
    {
        Auth::requireAdmin();
        $runs = Backup::history(20);
        $out = array_map(function ($r) {
            $details = json_decode((string)$r['details'], true) ?: [];
            return [
                'id'          => (int)$r['id'],
                'started_at'  => $r['started_at'],
                'finished_at' => $r['finished_at'],
                'status'      => $r['status'],
                'trigger'     => $r['run_trigger'],
                'bytes'       => $r['bytes'] !== null ? (int)$r['bytes'] : null,
                'file_name'   => $r['file_name'],
                'sha256'      => $r['sha256'],
                'remote_path' => $r['remote_path'],
                'error'       => $r['error'],
                'downloadable'=> (bool)$r['exists_locally'],
                'tables'      => $details['csv']['tables'] ?? null,
                'rows'        => $details['csv']['rows'] ?? null,
            ];
        }, $runs);

        $cronUrl = APP_URL . '/backup-cron.php?secret=' . Backup::cronSecret();

        jsonResponse([
            'runs'      => $out,
            'healthy'   => Backup::isHealthy(),
            'cron_url'  => $cronUrl,
            'keep'      => ['local' => Backup::KEEP_LOCAL, 'remote' => Backup::KEEP_REMOTE, 'weekly' => Backup::KEEP_WEEKLY],
            'destination' => self::destination(),
            'dir_exposed' => Backup::dirIsExposed(),
        ]);
    }

    /** Where the off-server copy goes, for display. */
    private static function destination()
    {
        $f = dirname(__DIR__, 2) . '/config/graph.php';
        if (!is_file($f)) return ['configured' => false, 'label' => 'Not configured'];
        $cfg = require $f;
        return [
            'configured' => !empty($cfg['drive_id']),
            'label'      => 'SharePoint · ' . ($cfg['site_url'] ?? '?'),
            'folder'     => Backup::REMOTE_FOLDER,
        ];
    }

    public static function runNow()
    {
        Auth::requireAdmin();
        $run = Backup::run(['trigger' => 'manual']);
        if (($run['status'] ?? '') === 'failed') {
            jsonError($run['error'] ?: 'The backup failed.', 500);
        }
        jsonResponse([
            'ok'      => true,
            'status'  => $run['status'],
            'bytes'   => (int)$run['bytes'],
            'file'    => $run['file_name'],
            'remote'  => $run['remote_path'],
            'warning' => $run['error'],
        ]);
    }

    /** Stream a local archive. Path-confined so an id can never walk the disk. */
    public static function download($id)
    {
        Auth::requireAdmin();
        Schema::ensureBackups();
        $row = DB::fetch("SELECT * FROM backup_runs WHERE id = ?", [(int)$id]);
        if (!$row || empty($row['file_name'])) jsonError('That backup is not on record', 404);

        $dir  = realpath(Backup::dir());
        $path = realpath($dir . '/' . basename($row['file_name']));
        if (!$path || strpos($path, $dir) !== 0 || !is_file($path)) {
            jsonError('That archive is no longer on the server. Fetch it from SharePoint instead.', 404);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Does the code on this server match the commit it was deployed from? */
    public static function versionCheck()
    {
        Auth::requireAdmin();
        try {
            jsonResponse(CodeVersion::check(isset($_GET['sha']) ? (string)$_GET['sha'] : null));
        } catch (\Throwable $e) {
            jsonError($e->getMessage(), 502);
        }
    }
}
