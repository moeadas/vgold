<?php
/**
 * VGold — reusable AI client.
 *
 * AIController's provider calls are private, text-only, hard-capped at 1024
 * output tokens, and its provider-selection loop is copy-pasted per caller.
 * None of that suits reading a scanned bill, so this is a separate, self-
 * contained client: it takes an optional attachment, shapes the right payload
 * per provider, and returns the raw completion. AIController is left untouched
 * so the Ask feature cannot regress.
 */
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/PdfRaster.php';

class AiClient {

    /** Preference order. Ollama first to match the existing Ask behaviour. */
    const ORDER = ['ollama', 'ollama_cloud', 'anthropic', 'openai', 'gemini'];

    /**
     * Which providers can read an attachment, and of what kind.
     *
     * `pdf` here means "accepts a PDF over the wire". Only two do. The rest are
     * still usable for documents when Ghostscript is available, because a PDF is
     * rendered to page images first — see resolveProvider() and attachmentParts().
     */
    const CAPABILITIES = [
        'anthropic'    => ['image' => true,  'pdf' => true],
        'gemini'       => ['image' => true,  'pdf' => true],
        'openai'       => ['image' => true,  'pdf' => false],
        'ollama'       => ['image' => true,  'pdf' => false],
        'ollama_cloud' => ['image' => true,  'pdf' => false],
    ];

    /** Providers that authenticate with a key rather than being open locally. */
    const NEEDS_KEY = ['anthropic', 'openai', 'gemini', 'ollama_cloud'];

    /**
     * The user's active provider, optionally restricted to ones that can read a
     * given attachment kind ('image' | 'pdf' | null).
     *
     * When a PDF is wanted and this host can rasterise, an image-only provider
     * qualifies: a rendered page is a picture of the document, and asking
     * someone to go and buy a second API key is not a real answer.
     *
     * Returns ['provider' => string, 'config' => row] or null.
     */
    public static function resolveProvider($userId, $needs = null) {
        $canRaster = ($needs === 'pdf') && PdfRaster::available();
        foreach (self::ORDER as $p) {
            if ($needs && empty(self::CAPABILITIES[$p][$needs])) {
                if (!($canRaster && !empty(self::CAPABILITIES[$p]['image']))) continue;
            }
            $sql = "SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1";
            if (in_array($p, self::NEEDS_KEY, true)) $sql .= " AND api_key != ''";
            $row = DB::fetch($sql, [$userId, $p]);
            if ($row) return ['provider' => $p, 'config' => $row];
        }
        return null;
    }

    /** Every provider this user has switched on, for error messages. */
    public static function activeProviders($userId) {
        $rows = DB::fetchAll(
            "SELECT provider FROM user_api_keys WHERE user_id = ? AND is_active = 1 AND (api_key != '' OR provider = 'ollama')",
            [$userId]
        );
        return array_column($rows, 'provider');
    }

    /**
     * The models a provider currently offers this user.
     *
     * Typing a model name from memory is how you discover, three screens later,
     * that it was renamed. Ollama's cloud catalogue in particular changes with
     * every deprecation notice.
     *
     * @return array ['models' => [['id'=>string,'label'=>string,'vision'=>bool]], 'source' => string]
     */
    public static function listModels($provider, array $cfg, $timeout = 20) {
        $key = !empty($cfg['api_key']) ? Crypto::decrypt($cfg['api_key']) : '';

        switch ($provider) {
            case 'ollama_cloud':
            case 'ollama': {
                $base = self::baseFor($provider, $cfg);
                $json = self::get(rtrim($base, '/') . '/api/tags',
                    $key !== '' ? ['Authorization: Bearer ' . $key] : [], $timeout,
                    $provider === 'ollama' ? 'Ollama' : 'Ollama Cloud');
                $out = [];
                foreach ($json['models'] ?? [] as $m) {
                    $id = $m['name'] ?? $m['model'] ?? null;
                    if (!$id) continue;
                    $fams = array_map('strtolower', (array)($m['details']['families'] ?? []));
                    $size = $m['details']['parameter_size'] ?? null;
                    $out[] = [
                        'id'     => $id,
                        'label'  => $id . ($size ? '  ·  ' . $size : ''),
                        'vision' => in_array('clip', $fams, true) || in_array('mllama', $fams, true)
                                    || (bool)preg_match('/(vl|vision|llava|kimi|gemma|minimax|qwen3\.5)/i', $id),
                    ];
                }
                return ['models' => $out, 'source' => rtrim($base, '/') . '/api/tags'];
            }
            case 'openai': {
                $base = ($cfg['base_url'] ?? null) ?: 'https://api.openai.com';
                $json = self::get(rtrim($base, '/') . '/v1/models', ['Authorization: Bearer ' . $key], $timeout, 'OpenAI');
                $out = [];
                foreach ($json['data'] ?? [] as $m) {
                    $id = $m['id'] ?? null;
                    if (!$id || !preg_match('/^(gpt|o\d|chatgpt)/i', $id)) continue;
                    $out[] = ['id' => $id, 'label' => $id, 'vision' => (bool)preg_match('/(4o|4\.1|4\.5|o[34]|5)/i', $id)];
                }
                usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
                return ['models' => $out, 'source' => rtrim($base, '/') . '/v1/models'];
            }
            case 'anthropic': {
                $base = ($cfg['base_url'] ?? null) ?: 'https://api.anthropic.com';
                $json = self::get(rtrim($base, '/') . '/v1/models?limit=100',
                    ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], $timeout, 'Anthropic');
                $out = [];
                foreach ($json['data'] ?? [] as $m) {
                    if (empty($m['id'])) continue;
                    $out[] = ['id' => $m['id'], 'label' => $m['display_name'] ?? $m['id'], 'vision' => true];
                }
                return ['models' => $out, 'source' => rtrim($base, '/') . '/v1/models'];
            }
            case 'gemini': {
                $base = ($cfg['base_url'] ?? null) ?: 'https://generativelanguage.googleapis.com/v1beta';
                $json = self::get(rtrim($base, '/') . '/models?pageSize=200&key=' . urlencode($key), [], $timeout, 'Gemini');
                $out = [];
                foreach ($json['models'] ?? [] as $m) {
                    $id = isset($m['name']) ? preg_replace('#^models/#', '', $m['name']) : null;
                    if (!$id) continue;
                    $methods = (array)($m['supportedGenerationMethods'] ?? []);
                    if ($methods && !in_array('generateContent', $methods, true)) continue;
                    $out[] = ['id' => $id, 'label' => $m['displayName'] ?? $id, 'vision' => true];
                }
                return ['models' => $out, 'source' => rtrim($base, '/') . '/models'];
            }
        }
        throw new Exception('Unknown provider: ' . $provider);
    }

    /** Where a provider's API lives, honouring an override. */
    private static function baseFor($provider, array $cfg) {
        $custom = trim((string)($cfg['base_url'] ?? ''));
        if ($custom !== '') return $custom;
        return $provider === 'ollama_cloud' ? 'https://ollama.com' : 'http://localhost:11434';
    }

    /**
     * Run a completion.
     *
     * $opts:
     *   provider    — force one, otherwise resolved from the user's keys
     *   config      — use this row rather than looking one up (with `provider`)
     *   user_id     — whose keys to use (default: current user)
     *   max_tokens  — default 4096; document extraction needs far more than the
     *                 1024 the older code allowed
     *   timeout     — seconds, default 120
     *   attachment  — ['mime' => string, 'data' => base64 string, 'name' => string,
     *                  'path' => optional absolute path, saves re-encoding a PDF
     *                  that is already on disk before it is rasterised]
     *
     * Returns the model's text. Throws with the provider's own error body,
     * because "API error (400)" alone is not debuggable.
     */
    public static function complete($prompt, $systemPrompt = '', array $opts = []) {
        $userId = $opts['user_id'] ?? Auth::userId();
        $att    = $opts['attachment'] ?? null;
        $needs  = null;
        if ($att) $needs = self::isPdf($att['mime']) ? 'pdf' : 'image';

        if (!empty($opts['provider']) && !empty($opts['config'])) {
            // A caller that already holds the configuration — the connection
            // test, which must be able to try settings before they are saved.
            $sel = ['provider' => $opts['provider'], 'config' => $opts['config']];
        } elseif (!empty($opts['provider'])) {
            $cfg = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1", [$userId, $opts['provider']]);
            $sel = $cfg ? ['provider' => $opts['provider'], 'config' => $cfg] : null;
        } else {
            $sel = self::resolveProvider($userId, $needs);
        }

        if (!$sel) {
            $active = self::activeProviders($userId);
            if ($needs === 'pdf' && $active) {
                throw new Exception(
                    PdfRaster::available()
                        ? 'None of the connected providers (' . implode(' / ', $active) . ') can read this document.'
                          . ' Check the key is still valid in Settings → AI Connections.'
                        : 'Reading a PDF needs Anthropic or Google Gemini, and you have '
                          . implode(' / ', $active) . ' connected. Either add one of those keys in '
                          . 'Settings → AI Connections, or upload a photo or screenshot instead.'
                );
            }
            throw new Exception('No AI provider is connected. Add a key in Settings → AI Connections.');
        }

        $maxTokens = (int)($opts['max_tokens'] ?? 4096);
        $timeout   = (int)($opts['timeout'] ?? 120);
        $parts     = self::attachmentParts($att, $sel['provider']);

        switch ($sel['provider']) {
            case 'anthropic':    return self::anthropic($sel['config'], $prompt, $systemPrompt, $parts, $maxTokens, $timeout);
            case 'openai':       return self::openai($sel['config'], $prompt, $systemPrompt, $parts, $maxTokens, $timeout);
            case 'gemini':       return self::gemini($sel['config'], $prompt, $systemPrompt, $parts, $maxTokens, $timeout);
            case 'ollama':
            case 'ollama_cloud': return self::ollamaChat($sel['provider'], $sel['config'], $prompt, $systemPrompt, $parts, $maxTokens, $timeout);
        }
        throw new Exception('Unsupported AI provider: ' . $sel['provider']);
    }

    /**
     * Turn one attachment into the list of parts a provider can actually take.
     *
     * A PDF stays a PDF for Anthropic and Gemini. For everyone else it becomes
     * page images, which is the whole reason a key for a vision model is enough
     * to read an invoice here.
     */
    private static function attachmentParts($att, $provider) {
        if (!$att) return [];
        if (!self::isPdf($att['mime'] ?? '')) {
            return [['mime' => $att['mime'], 'data' => $att['data']]];
        }
        if (!empty(self::CAPABILITIES[$provider]['pdf'])) {
            return [['mime' => 'application/pdf', 'data' => $att['data']]];
        }
        if (!PdfRaster::available()) {
            throw new Exception('This provider cannot read PDFs, and this server cannot convert them to images. '
                . 'Connect an Anthropic or Google Gemini key, or upload a photo instead.');
        }

        // Rasterise from the file where one exists; otherwise stage the bytes we
        // were handed, and clean up either way.
        $path = $att['path'] ?? null;
        $temp = null;
        if (!$path || !is_readable($path)) {
            $temp = tempnam(sys_get_temp_dir(), 'vgpdf');
            if ($temp === false) throw new Exception('Could not stage the PDF for conversion.');
            file_put_contents($temp, base64_decode($att['data']));
            $path = $temp;
        }
        try {
            return PdfRaster::pages($path);
        } finally {
            if ($temp) @unlink($temp);
        }
    }

    public static function isPdf($mime) {
        return stripos((string)$mime, 'pdf') !== false;
    }

    /** POST JSON and return the decoded body, or throw with what the API said. */
    private static function post($url, array $payload, array $headers, $timeout, $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new Exception("$label could not be reached: " . ($cerr ?: 'connection failed'));
        $json = json_decode($body, true);
        if ($code !== 200) {
            $msg = $json['error']['message'] ?? $json['error']['type'] ?? $json['message'] ?? substr((string)$body, 0, 300);
            throw new Exception("$label refused the request ($code): $msg");
        }
        return $json ?: [];
    }

    /** GET JSON and return the decoded body, or throw with what the API said. */
    private static function get($url, array $headers, $timeout, $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new Exception("$label could not be reached: " . ($cerr ?: 'connection failed'));
        $json = json_decode($body, true);
        if ($code !== 200) {
            $msg = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? substr((string)$body, 0, 300);
            if (is_array($msg)) $msg = json_encode($msg);
            throw new Exception("$label refused the request ($code): $msg");
        }
        if (!is_array($json)) throw new Exception("$label returned something that is not JSON.");
        return $json;
    }

    private static function anthropic($cfg, $prompt, $system, $parts, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://api.anthropic.com';
        $model = $cfg['model'] ?: 'claude-sonnet-4-20250514';

        $content = [];
        foreach ($parts as $part) {
            $content[] = self::isPdf($part['mime'])
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $part['data']]]
                : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $part['mime'],     'data' => $part['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ];
        if ($system !== '') $payload['system'] = $system;

        $json = self::post("$base/v1/messages", $payload, [
            'x-api-key: ' . Crypto::decrypt($cfg['api_key']),
            'anthropic-version: 2023-06-01',
        ], $timeout, 'Anthropic');

        $out = '';
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $out .= $block['text'];
        }
        return $out !== '' ? $out : 'No response';
    }

    private static function openai($cfg, $prompt, $system, $parts, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://api.openai.com';
        $model = $cfg['model'] ?: 'gpt-4o';

        $userContent = $prompt;
        if ($parts) {
            $userContent = [];
            foreach ($parts as $part) {
                if (self::isPdf($part['mime'])) {
                    throw new Exception('OpenAI chat models cannot read PDFs directly.');
                }
                $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['mime'] . ';base64,' . $part['data']]];
            }
            $userContent[] = ['type' => 'text', 'text' => $prompt];
        }

        $messages = [];
        if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $json = self::post("$base/v1/chat/completions", [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ], ['Authorization: Bearer ' . Crypto::decrypt($cfg['api_key'])], $timeout, 'OpenAI');

        return $json['choices'][0]['message']['content'] ?? 'No response';
    }

    private static function gemini($cfg, $prompt, $system, $parts, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta';
        $model = $cfg['model'] ?: 'gemini-2.0-flash';

        $body = [];
        foreach ($parts as $part) $body[] = ['inline_data' => ['mime_type' => $part['mime'], 'data' => $part['data']]];
        $body[] = ['text' => ($system !== '' ? $system . "\n\n" : '') . $prompt];

        $json = self::post(
            "$base/models/" . rawurlencode($model) . ':generateContent?key=' . urlencode(Crypto::decrypt($cfg['api_key'])),
            ['contents' => [['parts' => $body]], 'generationConfig' => ['maxOutputTokens' => $maxTokens]],
            [], $timeout, 'Gemini'
        );

        $out = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $p) $out .= $p['text'] ?? '';
        return $out !== '' ? $out : 'No response';
    }

    /**
     * Ollama, local or cloud. Same wire protocol either way — the cloud is the
     * same server on someone else's machine, behind a Bearer token.
     *
     * /api/chat rather than /api/generate so the system prompt is a real system
     * message and images attach to the message that refers to them.
     */
    private static function ollamaChat($provider, $cfg, $prompt, $system, $parts, $maxTokens, $timeout) {
        $isCloud = ($provider === 'ollama_cloud');
        $base    = self::baseFor($provider, $cfg);
        $model   = $cfg['model'] ?: ($isCloud ? 'kimi-k2.5' : 'llama3.2');
        $label   = $isCloud ? 'Ollama Cloud' : 'Ollama';

        $images = [];
        foreach ($parts as $part) {
            if (self::isPdf($part['mime'])) {
                throw new Exception($label . ' cannot read PDFs directly, and this server could not convert it to images.');
            }
            $images[] = $part['data'];
        }

        $messages = [];
        if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $user = ['role' => 'user', 'content' => $prompt];
        if ($images) $user['images'] = $images;
        $messages[] = $user;

        $headers = [];
        if ($isCloud) {
            $key = !empty($cfg['api_key']) ? Crypto::decrypt($cfg['api_key']) : '';
            if ($key === '') throw new Exception('Ollama Cloud needs an API key. Add one in Settings → AI Connections.');
            $headers[] = 'Authorization: Bearer ' . $key;
        } elseif (!empty($cfg['api_key'])) {
            // A self-hosted Ollama behind a reverse proxy may still want one.
            $headers[] = 'Authorization: Bearer ' . Crypto::decrypt($cfg['api_key']);
        }

        $json = self::post(rtrim($base, '/') . '/api/chat', [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => ['num_predict' => $maxTokens],
        ], $headers, $timeout, $label);

        $text = $json['message']['content'] ?? '';
        // Reasoning models put their answer after a thinking block; some return
        // it in a separate field entirely.
        if ($text === '' && isset($json['message']['thinking'])) $text = (string)$json['message']['thinking'];
        if ($text === '' && isset($json['response'])) $text = (string)$json['response'];
        return $text !== '' ? $text : 'No response';
    }

    /**
     * Pull a JSON object out of a model response.
     *
     * Models wrap JSON in prose or ```json fences however firmly you ask them not
     * to, so take the outermost balanced braces rather than trusting the shape.
     */
    public static function extractJson($text) {
        $text = trim((string)$text);
        if ($text === '') return null;

        if (preg_match('/```(?:json)?\s*(.+?)```/is', $text, $m)) $text = trim($m[1]);

        $start = strpos($text, '{');
        if ($start === false) return null;
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inStr) {
                if ($esc)            { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"')  { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; continue; }
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return json_decode(substr($text, $start, $i - $start + 1), true);
                }
            }
        }
        return null;
    }
}
