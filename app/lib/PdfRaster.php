<?php
/**
 * PdfRaster — turn a PDF into page images so any vision model can read it.
 *
 * Only Anthropic and Gemini accept a PDF over the wire. OpenAI and Ollama take
 * images only, which used to mean "connect a different provider" — an answer
 * that helps nobody holding the key they actually have. Ghostscript is already
 * on this server, and rendering a page to PNG is exactly what it is for.
 *
 * Rendering happens in a private temporary directory that is always removed,
 * including when the conversion throws. A statement or invoice left decoded on
 * disk is worse than the failure that produced it.
 */
class PdfRaster
{
    /** Invoices are one or two pages; three is generous and bounds the cost. */
    const MAX_PAGES = 3;

    /** 150dpi is the usual floor for reliable OCR of 8–10pt print. */
    const DPI = 150;

    /** Beyond this a page is downscaled — models gain nothing and payloads hurt. */
    const MAX_EDGE = 1800;

    /** Refuse a PDF this size rather than hand Ghostscript something absurd. */
    const MAX_INPUT_BYTES = 25165824;   // 24MB

    /**
     * Is rasterising possible on this host?
     * Cached because it shells out, and the answer cannot change mid-request.
     */
    public static function available()
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        return $cached = (self::binary() !== null);
    }

    /** Path to Ghostscript, or null when it is not usable here. */
    public static function binary()
    {
        static $found = false, $path = null;
        if ($found) return $path;
        $found = true;

        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true) || !function_exists('exec')) return $path = null;

        foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) return $path = $candidate;
        }
        $out = []; $code = 1;
        @exec('command -v gs 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0]) && is_executable(trim($out[0]))) return $path = trim($out[0]);
        return $path = null;
    }

    /**
     * Render the first pages of a PDF.
     *
     * @param string $absPath  the PDF on disk
     * @param int    $maxPages how many pages at most
     * @return array list of ['mime' => 'image/png', 'data' => base64, 'page' => n]
     * @throws Exception when the file cannot be rendered
     */
    public static function pages($absPath, $maxPages = self::MAX_PAGES)
    {
        $gs = self::binary();
        if (!$gs) throw new Exception('This server cannot convert PDFs to images.');
        if (!is_readable($absPath)) throw new Exception('The PDF could not be read.');
        $size = (int)filesize($absPath);
        if ($size <= 0) throw new Exception('That PDF is empty.');
        if ($size > self::MAX_INPUT_BYTES) {
            throw new Exception('That PDF is ' . round($size / 1048576, 1) . 'MB, too large to convert. Export a smaller file.');
        }
        $maxPages = max(1, min((int)$maxPages, 10));

        $dir = self::tempDir();
        try {
            $cmd = escapeshellcmd($gs)
                 . ' -q -dNOPAUSE -dBATCH -dSAFER'
                 . ' -sDEVICE=png16m'
                 . ' -r' . (int)self::DPI
                 . ' -dFirstPage=1 -dLastPage=' . $maxPages
                 . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4'
                 . ' -sOutputFile=' . escapeshellarg($dir . '/page-%d.png')
                 . ' ' . escapeshellarg($absPath)
                 . ' 2>&1';

            $out = []; $code = 1;
            @exec($cmd, $out, $code);

            $files = glob($dir . '/page-*.png') ?: [];
            // Ghostscript exits non-zero on a damaged file but often still
            // renders what it could, so judge on output rather than status.
            if (!$files) {
                // Ghostscript's own diagnostics are a PostScript stack dump —
                // useful in the log, useless on screen.
                error_log('PdfRaster: gs produced nothing (' . $code . '): ' . implode(' ', array_slice($out, 0, 5)));
                throw new Exception('That PDF could not be converted to an image. '
                    . 'It may be password-protected, damaged, or not really a PDF.');
            }

            natsort($files);
            $pages = [];
            foreach (array_values($files) as $i => $f) {
                if ($i >= $maxPages) break;
                $png = self::downscale($f);
                if ($png === null) continue;
                $pages[] = ['mime' => 'image/png', 'data' => base64_encode($png), 'page' => $i + 1];
            }
            if (!$pages) throw new Exception('That PDF rendered no readable pages.');
            return $pages;
        } finally {
            self::rmdir($dir);
        }
    }

    /**
     * Shrink an oversized render. Returns the PNG bytes, or null if unreadable.
     * GD is used only when it is actually needed and actually present.
     */
    private static function downscale($file)
    {
        $bytes = @file_get_contents($file);
        if ($bytes === false || $bytes === '') return null;
        if (!function_exists('imagecreatefromstring')) return $bytes;

        $info = @getimagesize($file);
        if (!$info) return $bytes;
        list($w, $h) = $info;
        $edge = max($w, $h);
        if ($edge <= self::MAX_EDGE) return $bytes;

        if (!function_exists('imagecopyresampled') || !function_exists('imagecreatetruecolor')) return $bytes;
        $img = @imagecreatefromstring($bytes);
        if (!$img) return $bytes;
        $scale = self::MAX_EDGE / $edge;
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        // imagecopyresampled rather than imagescale: the latter depends on the
        // IMG_BICUBIC constant, and a GD build without it turns a resize into a
        // fatal rather than a slightly larger image.
        $small = @imagecreatetruecolor($nw, $nh);
        if (!$small) { imagedestroy($img); return $bytes; }
        @imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        ob_start();
        $encoded = @imagepng($small, null, 6);
        $outBytes = ob_get_clean();
        imagedestroy($small);
        return ($encoded && $outBytes) ? $outBytes : $bytes;
    }

    private static function tempDir()
    {
        $base = sys_get_temp_dir() . '/vgold-pdf-' . bin2hex(random_bytes(6));
        if (!@mkdir($base, 0700, true)) throw new Exception('Could not create a working directory for the conversion.');
        return $base;
    }

    private static function rmdir($dir)
    {
        if (!$dir || !is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}
