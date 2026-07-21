<?php
// app/lib/SharePointStore.php — SharePoint document library storage wrapper
require_once __DIR__ . '/Graph.php';

class SharePointStore {
    private static function cfg() { return require __DIR__ . '/../../config/graph.php'; }
    private static function drive() { return self::cfg()['drive_id']; }

    /** Small files (<4MB): single PUT. */
    public static function uploadSmall($projectId, $tmpPath, $originalName) {
        $path = self::itemPath($projectId, $originalName);
        $resp = Graph::request('PUT',
            "/drives/" . self::drive() . "/root:/" . self::encodePath($path) . ":/content",
            file_get_contents($tmpPath),
            ['Content-Type: application/octet-stream']);
        if ($resp['code'] >= 300) throw new Exception('Upload failed: ' . $resp['body']);
        return json_decode($resp['body'], true);
    }

    /** Large files: chunked upload session, streamed from disk (constant memory). */
    public static function uploadLarge($projectId, $tmpPath, $originalName) {
        $path = self::itemPath($projectId, $originalName);
        $create = Graph::request('POST',
            "/drives/" . self::drive() . "/root:/" . self::encodePath($path) . ":/createUploadSession",
            json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]),
            ['Content-Type: application/json']);
        if ($create['code'] >= 300) throw new Exception('Session create failed: ' . $create['body']);
        $uploadUrl = json_decode($create['body'], true)['uploadUrl'];

        $size = filesize($tmpPath);
        // 10 MB chunks (must be multiple of 320 KiB)
        $chunk = intdiv(10 * 1024 * 1024, 327680) * 327680;
        $fh = fopen($tmpPath, 'rb');
        $start = 0; $last = null;
        while ($start < $size) {
            $end = min($start + $chunk, $size) - 1;
            $len = $end - $start + 1;
            fseek($fh, $start);
            $data = fread($fh, $len);
            $resp = Graph::request('PUT', $uploadUrl, $data, [
                'Content-Length: ' . $len,
                'Content-Range: bytes ' . $start . '-' . $end . '/' . $size,
            ], true);
            if (!in_array($resp['code'], [200, 201, 202])) {
                fclose($fh);
                throw new Exception('Chunk upload failed at ' . $start . ': ' . $resp['body']);
            }
            $last = $resp; $start = $end + 1;
        }
        fclose($fh);
        return json_decode($last['body'], true); // final 200/201 returns the DriveItem
    }

    /** Auto-pick method by size. */
    public static function upload($projectId, $tmpPath, $originalName) {
        return (filesize($tmpPath) < 4 * 1024 * 1024)
            ? self::uploadSmall($projectId, $tmpPath, $originalName)
            : self::uploadLarge($projectId, $tmpPath, $originalName);
    }

    /** Short-lived embeddable URL for Office-for-the-web viewing/editing. */
    public static function previewUrl($itemId) {
        $resp = Graph::request('POST',
            "/drives/" . self::drive() . "/items/" . $itemId . "/preview",
            '{}', ['Content-Type: application/json']);
        if ($resp['code'] >= 300) throw new Exception('Preview failed: ' . $resp['body']);
        $d = json_decode($resp['body'], true);
        return $d['getUrl'] ?? null;
    }

    /** Short-lived direct download URL. */
    public static function downloadUrl($itemId) {
        $resp = Graph::request('GET',
            "/drives/" . self::drive() . "/items/" . $itemId . "?select=@microsoft.graph.downloadUrl");
        $d = json_decode($resp['body'], true);
        return $d['@microsoft.graph.downloadUrl'] ?? null;
    }

    public static function delete($itemId) {
        $resp = Graph::request('DELETE', "/drives/" . self::drive() . "/items/" . $itemId);
        return $resp['code'] < 300;
    }

    /**
     * Build the full upload path: AppFiles/{Category}/{Project}/{filename}
     * Uses Category > Project hierarchy names, not raw IDs.
     */
    private static function itemPath($projectId, $name) {
        $folder = self::projectFolderPath($projectId);
        $file = self::sanitizeSegment($name);
        if (!$file) $file = 'file_' . time();
        return $folder . '/' . $file;
    }

    /**
     * Build SharePoint folder path: AppFiles/{Category}/{Project}
     * Categories are parent projects (parent_id IS NULL on the projects table).
     * Falls back gracefully if a name is missing.
     */
    private static function projectFolderPath($projectId) {
        $row = DB::fetch(
            "SELECT p.name AS project_name, c.name AS category_name
             FROM projects p
             LEFT JOIN projects c ON c.id = p.parent_id AND c.parent_id IS NULL
             WHERE p.id = ?",
            [$projectId]
        );
        $category = self::sanitizeSegment($row['category_name'] ?? '');
        if (!$category) $category = 'Uncategorized';
        $project = self::sanitizeSegment($row['project_name'] ?? '');
        if (!$project) $project = 'project_' . $projectId;
        return self::cfg()['upload_root'] . '/' . $category . '/' . $project;
    }

    /**
     * Sanitize a folder/file name segment for SharePoint.
     * Strips forbidden characters, trims spaces/dots, limits length.
     */
    private static function sanitizeSegment($name) {
        $name = preg_replace('/[\\\\\/:*?"<>|#%]/', '', (string)$name);
        $name = trim($name);
        $name = trim($name, '.');
        $name = preg_replace('/\s+/', ' ', $name);
        return mb_substr($name, 0, 120);
    }

    private static function encodePath($path) {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}