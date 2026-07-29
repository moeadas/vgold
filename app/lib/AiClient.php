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

class AiClient {

    /** Preference order. Ollama first to match the existing Ask behaviour. */
    const ORDER = ['ollama', 'anthropic', 'openai', 'gemini'];

    /** Which providers can read an attachment, and of what kind. */
    const CAPABILITIES = [
        'anthropic' => ['image' => true,  'pdf' => true],
        'gemini'    => ['image' => true,  'pdf' => true],
        'openai'    => ['image' => true,  'pdf' => false],
        'ollama'    => ['image' => true,  'pdf' => false],
    ];

    /**
     * The user's active provider, optionally restricted to ones that can read a
     * given attachment kind ('image' | 'pdf' | null).
     *
     * Returns ['provider' => string, 'config' => row] or null.
     */
    public static function resolveProvider($userId, $needs = null) {
        foreach (self::ORDER as $p) {
            if ($needs && empty(self::CAPABILITIES[$p][$needs])) continue;
            $sql = "SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1";
            if ($p !== 'ollama') $sql .= " AND api_key != ''";
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
     * Run a completion.
     *
     * $opts:
     *   provider    — force one, otherwise resolved from the user's keys
     *   user_id     — whose keys to use (default: current user)
     *   max_tokens  — default 4096; document extraction needs far more than the
     *                 1024 the older code allowed
     *   timeout     — seconds, default 120
     *   attachment  — ['mime' => string, 'data' => base64 string, 'name' => string]
     *
     * Returns the model's text. Throws with the provider's own error body,
     * because "API error (400)" alone is not debuggable.
     */
    public static function complete($prompt, $systemPrompt = '', array $opts = []) {
        $userId = $opts['user_id'] ?? Auth::userId();
        $att    = $opts['attachment'] ?? null;
        $needs  = null;
        if ($att) $needs = self::isPdf($att['mime']) ? 'pdf' : 'image';

        if (!empty($opts['provider'])) {
            $cfg = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1", [$userId, $opts['provider']]);
            $sel = $cfg ? ['provider' => $opts['provider'], 'config' => $cfg] : null;
        } else {
            $sel = self::resolveProvider($userId, $needs);
        }

        if (!$sel) {
            $active = self::activeProviders($userId);
            if ($needs === 'pdf' && $active) {
                throw new Exception(
                    'Reading a PDF needs Anthropic or Google Gemini, and you have '
                    . implode(' / ', $active) . ' connected. Either add one of those keys in '
                    . 'Settings → AI Connections, or upload a photo or screenshot of the bill instead.'
                );
            }
            throw new Exception('No AI provider is connected. Add a key in Settings → AI Connections.');
        }

        $maxTokens = (int)($opts['max_tokens'] ?? 4096);
        $timeout   = (int)($opts['timeout'] ?? 120);

        switch ($sel['provider']) {
            case 'anthropic': return self::anthropic($sel['config'], $prompt, $systemPrompt, $att, $maxTokens, $timeout);
            case 'openai':    return self::openai($sel['config'], $prompt, $systemPrompt, $att, $maxTokens, $timeout);
            case 'gemini':    return self::gemini($sel['config'], $prompt, $systemPrompt, $att, $maxTokens, $timeout);
            case 'ollama':    return self::ollama($sel['config'], $prompt, $systemPrompt, $att, $maxTokens, $timeout);
        }
        throw new Exception('Unsupported AI provider: ' . $sel['provider']);
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

    private static function anthropic($cfg, $prompt, $system, $att, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://api.anthropic.com';
        $model = $cfg['model'] ?: 'claude-sonnet-4-20250514';

        $content = [];
        if ($att) {
            $content[] = self::isPdf($att['mime'])
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $att['data']]]
                : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $att['mime'],      'data' => $att['data']]];
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

    private static function openai($cfg, $prompt, $system, $att, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://api.openai.com';
        $model = $cfg['model'] ?: 'gpt-4o';

        if ($att && self::isPdf($att['mime'])) {
            throw new Exception('OpenAI chat models cannot read PDFs directly. Upload a photo or screenshot of the bill, or connect an Anthropic or Gemini key.');
        }

        $userContent = $att
            ? [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $att['mime'] . ';base64,' . $att['data']]],
                ['type' => 'text', 'text' => $prompt],
              ]
            : $prompt;

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

    private static function gemini($cfg, $prompt, $system, $att, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta';
        $model = $cfg['model'] ?: 'gemini-2.0-flash';

        $parts = [];
        if ($att) $parts[] = ['inline_data' => ['mime_type' => $att['mime'], 'data' => $att['data']]];
        $parts[] = ['text' => ($system !== '' ? $system . "\n\n" : '') . $prompt];

        $json = self::post(
            "$base/models/" . rawurlencode($model) . ':generateContent?key=' . urlencode(Crypto::decrypt($cfg['api_key'])),
            ['contents' => [['parts' => $parts]], 'generationConfig' => ['maxOutputTokens' => $maxTokens]],
            [], $timeout, 'Gemini'
        );

        $out = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $p) $out .= $p['text'] ?? '';
        return $out !== '' ? $out : 'No response';
    }

    private static function ollama($cfg, $prompt, $system, $att, $maxTokens, $timeout) {
        $base  = $cfg['base_url'] ?: 'http://localhost:11434';
        $model = $cfg['model'] ?: 'glm-5.1:cloud';

        if ($att && self::isPdf($att['mime'])) {
            throw new Exception('The local model cannot read PDFs. Upload a photo or screenshot of the bill instead.');
        }

        $payload = [
            'model'   => $model,
            'prompt'  => ($system !== '' ? $system . "\n\n" : '') . $prompt,
            'stream'  => false,
            'options' => ['num_predict' => $maxTokens],
        ];
        if ($att) $payload['images'] = [$att['data']];

        $json = self::post("$base/api/generate", $payload, [], $timeout, 'Ollama');
        return $json['response'] ?? 'No response';
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
