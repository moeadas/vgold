<?php
/**
 * VGo — is the code on this server the code in the repository?
 *
 * The deploy copies files out of a pinned commit tarball, so in principle the
 * two always agree. In practice a hotfix edited over SFTP, a half-finished
 * deploy or a file that failed to copy leaves the server quietly ahead of or
 * behind the repo — and you only discover it when you try to rebuild from git
 * and the result behaves differently.
 *
 * This compares every tracked file against the commit it claims to be at, using
 * git's own blob hash so the answer is exact rather than a guess from
 * timestamps.
 */
class CodeVersion {

    const REPO       = 'moeadas/vgold';
    const STAMP_FILE = 'storage/deployed-sha.txt';
    /** Not code — differences here are expected and mean nothing. */
    const IGNORE = [
        'config/database.php', 'config/database.sg.php', 'config/graph.php',
        'config/app_key.php', 'config/push.php',
        'uploads/', 'storage/', 'vendor/', '.git/',
    ];

    public static function root() { return dirname(__DIR__, 2); }

    /** The commit the running code was deployed from, if we know it. */
    public static function deployedSha() {
        $f = self::root() . '/' . self::STAMP_FILE;
        if (is_file($f)) {
            $sha = trim((string)file_get_contents($f));
            if (preg_match('/^[0-9a-f]{40}$/i', $sha)) return strtolower($sha);
        }
        // A checkout deployed with git rather than the tarball helper.
        $head = self::root() . '/.git/HEAD';
        if (is_file($head)) {
            $h = trim((string)file_get_contents($head));
            if (preg_match('/^[0-9a-f]{40}$/i', $h)) return strtolower($h);
            if (preg_match('#^ref: (.+)$#', $h, $m)) {
                $ref = self::root() . '/.git/' . trim($m[1]);
                if (is_file($ref)) {
                    $sha = trim((string)file_get_contents($ref));
                    if (preg_match('/^[0-9a-f]{40}$/i', $sha)) return strtolower($sha);
                }
            }
        }
        return null;
    }

    public static function recordDeployedSha($sha) {
        if (!preg_match('/^[0-9a-f]{40}$/i', (string)$sha)) return false;
        $dir = dirname(self::root() . '/' . self::STAMP_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return (bool)@file_put_contents(self::root() . '/' . self::STAMP_FILE, strtolower($sha) . "\n");
    }

    /** git's blob id for a file: sha1("blob <len>\0" + contents). */
    public static function blobHash($path) {
        $data = @file_get_contents($path);
        if ($data === false) return null;
        return sha1('blob ' . strlen($data) . "\0" . $data);
    }

    private static function ignored($relPath) {
        foreach (self::IGNORE as $i) {
            if (substr($i, -1) === '/') { if (strpos($relPath, $i) === 0) return true; }
            elseif ($relPath === $i) return true;
        }
        return false;
    }

    /** The file list of a commit, from the GitHub API. */
    private static function repoTree($sha) {
        $url = 'https://api.github.com/repos/' . self::REPO . '/git/trees/' . rawurlencode($sha) . '?recursive=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['User-Agent: vgold-version-check', 'Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new Exception('GitHub would not return that commit (HTTP ' . $code . ').');
        $json = json_decode($body, true);
        if (!empty($json['truncated'])) throw new Exception('The repository listing came back truncated.');
        return $json['tree'] ?? [];
    }

    /** The newest commit on the default branch. */
    public static function repoHead() {
        $ch = curl_init('https://api.github.com/repos/' . self::REPO . '/commits/main');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['User-Agent: vgold-version-check', 'Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        $j = json_decode($body, true);
        return [
            'sha'     => $j['sha'] ?? null,
            'message' => strtok((string)($j['commit']['message'] ?? ''), "\n"),
            'date'    => $j['commit']['committer']['date'] ?? null,
        ];
    }

    /**
     * Compare the live tree against the commit it claims to be.
     *
     * Returns which files differ, which are missing, and which exist on the
     * server but not in the repo — the last being the one that silently breaks
     * a rebuild, since nothing in git will recreate them.
     */
    public static function check($sha = null) {
        $sha = $sha ?: self::deployedSha();
        if (!$sha) {
            return ['ok' => false, 'reason' => 'This server has no record of which commit it was deployed from.'];
        }

        $tree = self::repoTree($sha);
        $root = self::root();
        $expected = [];
        $modified = $missing = [];

        foreach ($tree as $node) {
            if (($node['type'] ?? '') !== 'blob') continue;
            $rel = $node['path'];
            if (self::ignored($rel)) continue;
            $expected[$rel] = $node['sha'];

            $abs = $root . '/' . $rel;
            if (!is_file($abs)) { $missing[] = $rel; continue; }
            if (self::blobHash($abs) !== $node['sha']) $modified[] = $rel;
        }

        // Anything present on the server that the repo does not know about.
        $extra = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($f) use ($root) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                    if ($f->isDir()) $rel .= '/';
                    return !self::ignored($rel) && strpos($rel, '.git/') !== 0;
                }
            )
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if (self::ignored($rel)) continue;
            if (!isset($expected[$rel])) $extra[] = $rel;
        }

        sort($modified); sort($missing); sort($extra);
        $head = self::repoHead();

        return [
            'ok'            => !$modified && !$missing,
            'sha'           => $sha,
            'checked'       => count($expected),
            'modified'      => $modified,
            'missing'       => $missing,
            'extra'         => array_slice($extra, 0, 50),
            'extra_total'   => count($extra),
            'repo_head'     => $head['sha'] ?? null,
            'head_message'  => $head['message'] ?? null,
            'head_date'     => $head['date'] ?? null,
            'up_to_date'    => $head && isset($head['sha']) ? ($head['sha'] === $sha) : null,
            'commit_url'    => 'https://github.com/' . self::REPO . '/commit/' . $sha,
        ];
    }
}
