<?php
require_once __DIR__ . '/../lib/Crypto.php';
class AIController {
    
    private static $providers = [
        'gemini' => [
            'label' => 'Google Gemini',
            'default_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'default_model' => 'gemini-2.0-flash',
            'docs' => 'Get your API key from Google AI Studio',
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'default_url' => 'https://api.anthropic.com',
            'default_model' => 'claude-sonnet-4-20250514',
            'docs' => 'Get your API key from console.anthropic.com',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'default_url' => 'https://api.openai.com',
            'default_model' => 'gpt-4o',
            'docs' => 'Get your API key from platform.openai.com',
        ],
        'ollama_cloud' => [
            'label' => 'Ollama Cloud',
            'default_url' => 'https://ollama.com',
            'default_model' => 'kimi-k2.5',
            'docs' => 'Create a key at ollama.com/settings/keys',
            'needs_key' => true,
            'can_list_models' => true,
        ],
        'ollama' => [
            'label' => 'Ollama (self-hosted)',
            'default_url' => 'http://localhost:11434',
            'default_model' => 'llama3.2',
            'docs' => 'Your own Ollama server — must be reachable from this app, not from your laptop',
            'needs_key' => false,
            'can_list_models' => true,
        ],
    ];

    /**
     * The models a provider currently offers.
     *
     * A key may be supplied in the request so the list can be fetched before it
     * is saved — otherwise setting one up means saving a guess, discovering it
     * was wrong, and coming back.
     */
    public static function models() {
        $data = input();
        $provider = trim((string)($data['provider'] ?? $_GET['provider'] ?? ''));
        if (!isset(self::$providers[$provider])) jsonError('Unknown provider');

        $cfg = DB::fetch(
            "SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ?",
            [Auth::userId(), $provider]
        ) ?: ['api_key' => '', 'base_url' => null, 'model' => null];

        // An unsaved key arrives in plain text; encrypt it in memory only, so
        // listModels() can decrypt it the same way it would a stored one.
        $typed = trim((string)($data['api_key'] ?? ''));
        if ($typed !== '') $cfg['api_key'] = Crypto::encrypt($typed);
        $typedUrl = trim((string)($data['base_url'] ?? ''));
        if ($typedUrl !== '') $cfg['base_url'] = $typedUrl;

        $needsKey = !empty(self::$providers[$provider]['needs_key']);
        if ($needsKey && empty($cfg['api_key'])) {
            jsonError('Enter the API key first, then fetch the models.');
        }

        require_once __DIR__ . '/../lib/AiClient.php';
        try {
            $res = AiClient::listModels($provider, $cfg);
        } catch (\Throwable $e) {
            jsonError($e->getMessage(), 502);
        }
        if (!count($res['models'])) {
            jsonError('That provider returned no models. Check the key and the base URL.', 502);
        }
        jsonResponse([
            'ok' => true,
            'provider' => $provider,
            'models' => $res['models'],
            'source' => $res['source'],
            'current' => $cfg['model'] ?: self::$providers[$provider]['default_model'],
        ]);
    }
    
    /**
     * Prove the connection actually works, rather than reporting "connected"
     * because a row was written.
     *
     * Two round trips, because they answer different questions. The first says
     * the key and model are real. The second renders a short code into an image
     * and asks the model to read it back — the only way to know whether this
     * model can do the job invoices and bills need. A model that answers
     * fluently and cannot read a picture fails at exactly the wrong moment.
     */
    public static function testConnection() {
        $data = input();
        $provider = trim((string)($data['provider'] ?? ''));
        if (!isset(self::$providers[$provider])) jsonError('Unknown provider');

        $cfg = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ?", [Auth::userId(), $provider]);
        if (!$cfg) jsonError('Nothing is saved for this provider yet.');

        // The form sends the masked placeholder when the key was left untouched;
        // that means "use the stored one", not "the key is a row of bullets".
        $typed = trim((string)($data['api_key'] ?? ''));
        if ($typed !== '' && strpos($typed, "\u{2022}") === false) $cfg['api_key'] = Crypto::encrypt($typed);
        if (!empty($data['base_url'])) $cfg['base_url'] = trim((string)$data['base_url']);
        if (!empty($data['model']))    $cfg['model']    = trim((string)$data['model']);

        require_once __DIR__ . '/../lib/AiClient.php';
        $model = $cfg['model'] ?: self::$providers[$provider]['default_model'];
        $out = ['ok' => true, 'provider' => $provider, 'model' => $model, 'text' => null, 'vision' => null];

        /* --- 1. can it answer at all? --- */
        $token = 'VG' . strtoupper(bin2hex(random_bytes(2)));
        $t0 = microtime(true);
        try {
            $reply = AiClient::complete(
                'Reply with exactly this and nothing else: ' . $token,
                'You are a connection test. Answer with the exact text asked for.',
                ['user_id' => Auth::userId(), 'provider' => $provider, 'config' => $cfg,
                 'max_tokens' => 64, 'timeout' => 45]
            );
            $hit = stripos(preg_replace('/[^A-Za-z0-9]/', '', (string)$reply), $token) !== false;
            $out['text'] = [
                'ok' => true,
                'echoed' => $hit,
                'ms' => (int)round((microtime(true) - $t0) * 1000),
                'reply' => mb_substr(trim((string)$reply), 0, 140),
            ];
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['text'] = ['ok' => false, 'error' => $e->getMessage()];
            jsonResponse($out);
        }

        /* --- 2. can it read a document? --- */
        $visionToken = '';
        $png = self::probeImage($visionToken);
        if ($png === null) {
            $out['vision'] = ['ok' => null, 'note' => 'This server cannot generate a test image, so reading was not checked.'];
            jsonResponse($out);
        }
        $t1 = microtime(true);
        try {
            $reply = AiClient::complete(
                'What characters are written in this image? Reply with those characters only.',
                'You read text out of images. Answer with only the characters you see.',
                ['user_id' => Auth::userId(), 'provider' => $provider, 'config' => $cfg,
                 'max_tokens' => 64, 'timeout' => 60,
                 'attachment' => ['mime' => 'image/png', 'data' => base64_encode($png), 'name' => 'test.png']]
            );
            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$reply));
            $out['vision'] = [
                'ok' => strpos($clean, $visionToken) !== false,
                'ms' => (int)round((microtime(true) - $t1) * 1000),
                'expected' => $visionToken,
                'reply' => mb_substr(trim((string)$reply), 0, 140),
            ];
        } catch (\Throwable $e) {
            $out['vision'] = ['ok' => false, 'error' => $e->getMessage()];
        }
        jsonResponse($out);
    }

    /** A small PNG containing a short code, for checking a model can read. */
    private static function probeImage(&$token) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no look-alikes
        $token = '';
        for ($i = 0; $i < 5; $i++) $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagescale')) return null;
        try {
            $w = 200; $h = 60;
            $im = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($im, 255, 255, 255);
            $black = imagecolorallocate($im, 17, 17, 17);
            imagefilledrectangle($im, 0, 0, $w, $h, $white);
            // Built-in font 5 is 9x15px; the image is scaled up afterwards so the
            // result is comfortably legible rather than a row of grey smudges.
            imagestring($im, 5, 58, 22, $token, $black);
            $big = imagescale($im, $w * 3, $h * 3, IMG_BICUBIC);
            imagedestroy($im);
            if (!$big) return null;
            ob_start();
            imagepng($big, null, 6);
            $bytes = ob_get_clean();
            imagedestroy($big);
            return $bytes ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function providers() {
        jsonResponse(['providers' => self::$providers]);
    }
    
    public static function ask() {
        $data = input();
        requireFields(['prompt'], $data);
        
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Find active provider (ollama first per Moe's request)
        $providers = ['ollama', 'ollama_cloud', 'anthropic', 'openai', 'gemini'];
        $config = null;
        $provider = null;
        
        foreach ($providers as $p) {
            if ($p === 'ollama') {
                $key = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1", [$userId, $p]);
            } else {
                $key = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1 AND api_key != ''", [$userId, $p]);
            }
            if ($key) { $config = $key; $provider = $p; break; }
        }
        
        if (!$config) {
            jsonResponse([
                'ok' => true,
                'answer_html' => '<p>I can\'t connect to an AI right now. Go to <b>Settings → AI Connections</b> to add your API key for Gemini, Anthropic, OpenAI, or Ollama.</p>',
                'actions' => [],
            ]);
        }
        
        $context = self::buildContext($wsId, $userId);
        $systemPrompt = "You are VGold, an AI assistant for the unified Victory Genomics Workflow and CRM app. Be concise and friendly. Format your response in clean HTML (use <p>, <ul>, <li>, <b>, <h3> tags). When mentioning a task or project, include clickable links using the NUMERIC ID: <a href=\"#task-{id}\" class=\"ai-link\" data-type=\"task\" data-id=\"{id}\">task title</a> or <a href=\"#project-{id}\" class=\"ai-link\" data-type=\"project\" data-id=\"{id}\">project name</a>. Keep responses short and actionable.";
        $fullPrompt = $context . "\n\nUser request: " . $data['prompt'];
        
        try {
            $response = match($provider) {
                'anthropic' => self::callAnthropic($config, $fullPrompt, $systemPrompt),
                'openai' => self::callOpenAI($config, $fullPrompt, $systemPrompt),
                'gemini' => self::callGemini($config, $fullPrompt, $systemPrompt),
                'ollama', 'ollama_cloud' => self::callOllama($config, $fullPrompt, $systemPrompt, $provider),
            };
            
            // Convert markdown to HTML if the response looks like markdown
            $html = self::toHtml($response);
            
            jsonResponse(['ok' => true, 'answer_html' => $html, 'provider' => $provider]);
        } catch (Exception $e) {
            $errMsg = APP_DEBUG ? esc($e->getMessage()) : 'An error occurred';
            jsonResponse(['ok' => true, 'answer_html' => '<p>' . $errMsg . '</p>', 'actions' => []]);
        }
    }
    
    // ===== PLAN MY DAY =====
    public static function planMyDay() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Check if plan already exists for today
        $today = date('Y-m-d');
        $existing = DB::fetch("SELECT * FROM day_plans WHERE user_id = ? AND plan_date = ?", [$userId, $today]);
        
        // Get tasks due today or within 2 days (not completed/canceled)
        $tasks = DB::fetchAll(
            "SELECT t.*, p.name as project_name, p.color as project_color, p.id as project_id,
                    u.name as assignee_name
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             WHERE t.assigned_to = ? AND p.workspace_id = ? 
             AND t.status NOT IN ('completed')
             AND (t.deadline_date IS NULL OR t.deadline_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY))
             ORDER BY 
                CASE WHEN t.deadline_date IS NOT NULL AND t.deadline_date <= CURDATE() THEN 0 ELSE 1 END,
                t.priority = 'urgent' DESC,
                t.deadline_date ASC",
            [$userId, $wsId]
        );
        
        // Find active provider
        $providers = ['ollama', 'ollama_cloud', 'anthropic', 'openai', 'gemini'];
        $config = null;
        $provider = null;
        foreach ($providers as $p) {
            if ($p === 'ollama') {
                $key = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1", [$userId, $p]);
            } else {
                $key = DB::fetch("SELECT * FROM user_api_keys WHERE user_id = ? AND provider = ? AND is_active = 1 AND api_key != ''", [$userId, $p]);
            }
            if ($key) { $config = $key; $provider = $p; break; }
        }
        
        if (!$config) {
            jsonError('No AI provider configured. Go to Settings to add an API key.');
        }
        
        // Build task summary for AI
        $taskList = "";
        $taskIds = [];
        foreach ($tasks as $t) {
            $due = $t['deadline_date'] ? formatDate($t['deadline_date']) : 'No deadline';
            $taskList .= "- [{$t['priority']}] {$t['title']} (Project: {$t['project_name']}, Due: {$due}, Status: {$t['status']})\n";
            $taskIds[] = $t['id'];
        }
        
        $userName = Auth::user()['name'] ?? 'User';
        $todayFormatted = date('l, F j, Y');
        
        $planPrompt = "Create a day plan for $userName for $todayFormatted. Here are their pending tasks (only those due today or within 2 days):\n\n$taskList\n\nCreate a structured, motivating day plan in HTML format. Use this structure:\n- A brief greeting and overview\n- A time-blocked schedule (Morning, Midday, Afternoon) grouping the tasks logically\n- For each task, include a clickable link using the NUMERIC ID: <a href=\"#task-{id}\" class=\"ai-link\" data-type=\"task\" data-id=\"{id}\">task title</a>\n- End with a brief motivational note\nKeep it concise and achievable. Only include tasks from the list above. Use <h3>, <p>, <ul>, <li> tags. Make it look clean and readable. IMPORTANT: Use the numeric task ID (e.g. 31, not 'q3-okr-finalization') in the href and data-id attributes.";
        
        try {
            $response = match($provider) {
                'anthropic' => self::callAnthropic($config, $planPrompt, 'You are VGold, a helpful Workflow and CRM AI. Create clean HTML output.'),
                'openai' => self::callOpenAI($config, $planPrompt, 'You are VGold, a helpful Workflow and CRM AI. Create clean HTML output.'),
                'gemini' => self::callGemini($config, $planPrompt, 'You are VGold, a helpful Workflow and CRM AI. Create clean HTML output.'),
                'ollama', 'ollama_cloud' => self::callOllama($config, $planPrompt, 'You are VGold, a helpful Workflow and CRM AI. Create clean HTML output.', $provider),
            };
            
            $html = self::toHtml($response);
            
            // Save to DB (replace existing for today)
            if ($existing) {
                DB::update('day_plans', [
                    'plan_html' => $html,
                    'task_ids' => implode(',', $taskIds),
                    'created_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                DB::insert('day_plans', [
                    'workspace_id' => $wsId,
                    'user_id' => $userId,
                    'plan_date' => $today,
                    'plan_html' => $html,
                    'task_ids' => implode(',', $taskIds),
                ]);
            }
            
            jsonResponse(['ok' => true, 'plan_html' => $html, 'task_count' => count($tasks)]);
        } catch (Exception $e) {
            $msg = APP_DEBUG ? $e->getMessage() : 'AI planning failed';
            jsonError($msg);
        }
    }
    
    public static function getDayPlan() {
        $userId = Auth::userId();
        $today = date('Y-m-d');
        
        $plan = DB::fetch("SELECT * FROM day_plans WHERE user_id = ? AND plan_date = ?", [$userId, $today]);
        
        if (!$plan) {
            // Auto-generate a code-based plan (no AI needed)
            $res = self::generateCodePlan($userId);
            
            DB::insert('day_plans', [
                'workspace_id' => Auth::workspaceId(),
                'user_id' => $userId,
                'plan_date' => $today,
                'plan_html' => $res['html'],
                'task_ids' => $res['task_ids'],
            ]);
            $plan = DB::fetch("SELECT * FROM day_plans WHERE user_id = ? AND plan_date = ?", [$userId, $today]);
        }
        
        $html = $plan['plan_html'];
        
        // Check which tasks are completed and cross them out
        $taskIds = array_filter(explode(',', $plan['task_ids']), fn($id) => $id && is_numeric($id));
        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $completed = DB::fetchAll(
                "SELECT id FROM tasks WHERE id IN ($placeholders) AND status = 'completed'",
                $taskIds
            );
            foreach ($completed as $c) {
                $id = $c['id'];
                $html = preg_replace(
                    '/<a href="#task-' . preg_quote($id) . '"([^>]*)>(.*?)<\/a>/is',
                    '<a href="#task-' . $id . '"$1 data-completed="1"><s class="completed-task">$2</s></a>',
                    $html
                );
                $taskRow = DB::fetch("SELECT title FROM tasks WHERE id = ?", [$id]);
                if ($taskRow) {
                    $title = preg_quote($taskRow['title'], '/');
                    $html = preg_replace(
                        '/<a href="#task-[^"]*"([^\)]*)>(' . $title . ')<\/a>/is',
                        '<a href="#task-' . $id . '"$1 data-completed="1"><s class="completed-task">$2</s></a>',
                        $html
                    );
                }
            }
        }
        
        jsonResponse(['plan' => [
            'id' => (int)$plan['id'],
            'date' => $plan['plan_date'],
            'html' => $html,
            'task_ids' => $plan['task_ids'],
            'created_at' => $plan['created_at'],
        ]]);
    }
    
    public static function deletePlan() {
        $userId = Auth::userId();
        $today = date('Y-m-d');
        DB::query("DELETE FROM day_plans WHERE user_id = ? AND plan_date = ?", [$userId, $today]);
        jsonResponse(['ok' => true]);
    }
    
    private static function generateCodePlan($userId) {
        $user = DB::fetch("SELECT name FROM users WHERE id = ?", [$userId]);
        $firstName = explode(' ', $user['name'])[0];
        $todayFormatted = date('l, F j, Y');
        
        // Single query with correct priority sorting and multi-assignee support
        $tasks = DB::fetchAll(
            "SELECT t.*, p.name as project_name, p.color as project_color
             FROM tasks t
             JOIN projects p ON t.project_id = p.id
             JOIN project_members pm ON pm.project_id = t.project_id AND pm.user_id = ?
             WHERE t.status = 'in_progress'
             AND (
                 t.assigned_to = ?
                 OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)
             )
             AND t.deadline_date IS NOT NULL
             AND t.deadline_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
             ORDER BY
                 CASE WHEN t.deadline_date IS NOT NULL AND t.deadline_date < CURDATE() THEN 0 ELSE 1 END,
                 FIELD(t.priority, 'urgent','normal'),
                 t.deadline_date IS NULL,
                 t.deadline_date ASC,
                 t.created_at ASC",
            [$userId, $userId, $userId]
        );
        
        // If no tasks with deadlines, get all open tasks
        if (count($tasks) === 0) {
            $tasks = DB::fetchAll(
                "SELECT t.*, p.name as project_name, p.color as project_color
                 FROM tasks t
                 JOIN projects p ON t.project_id = p.id
                 JOIN project_members pm ON pm.project_id = t.project_id AND pm.user_id = ?
                 WHERE t.status = 'in_progress'
                 AND (
                     t.assigned_to = ?
                     OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)
                 )
                 ORDER BY
                     CASE WHEN t.deadline_date IS NOT NULL AND t.deadline_date < CURDATE() THEN 0 ELSE 1 END,
                     FIELD(t.priority, 'urgent','normal'),
                     t.deadline_date IS NULL,
                     t.deadline_date ASC,
                     t.created_at ASC
                 LIMIT 10",
                [$userId, $userId, $userId]
            );
        }
        
        $usedIds = array_column($tasks, 'id');
        
        if (count($tasks) === 0) {
            return ['html' => '<h3>Good day, ' . htmlspecialchars($firstName) . '! ☀️</h3><p>You have no pending tasks. Enjoy the breathing room!</p>', 'task_ids' => ''];
        }
        
        // Bucket by date awareness
        $todayStr = date('Y-m-d');
        $overdue = $dueToday = $upcoming = [];
        foreach ($tasks as $t) {
            $d = $t['deadline_date'] ?? null;
            if ($d && $d < $todayStr) $overdue[] = $t;
            elseif ($d && $d === $todayStr) $dueToday[] = $t;
            else $upcoming[] = $t;
        }
        
        $total = count($tasks);
        $urgentCount = count(array_filter($tasks, fn($t) => $t['priority'] === 'urgent'));
        
        $html = '<h3>Good day, ' . htmlspecialchars($firstName) . '! ☀️</h3>';
        $overview = '<p>' . $todayFormatted . ' — You have <b>' . $total . ' task' . ($total != 1 ? 's' : '') . '</b> on deck';
        if ($urgentCount > 0) {
            $overview .= ', including <b>' . $urgentCount . ' urgent</b> item' . ($urgentCount != 1 ? 's' : '');
        }
        $overview .= ". Let's tackle what matters most first.</p>";
        $html .= $overview;
        
        $renderTask = function($t) {
            $badge = $t['priority'] === 'urgent' ? ' · urgent' : '';
            $status = $t['status'] === 'in_progress' ? ' · in progress' : '';
            $dueLabel = '';
            if ($t['deadline_date']) {
                $d = $t['deadline_date'];
                $today = date('Y-m-d');
                if ($d < $today) {
                    $diff = floor((time() - strtotime($d)) / 86400);
                    $dueLabel = ' · overdue ' . $diff . 'd';
                } elseif ($d === $today) {
                    $dueLabel = ' · due today';
                } else {
                    $dueLabel = ' · due ' . date('M j', strtotime($d));
                }
            }
            return '<li><a href="#task-' . $t['id'] . '" class="ai-link" data-type="task" data-id="' . $t['id'] . '">' . htmlspecialchars($t['title']) . '</a> <i>(' . htmlspecialchars($t['project_name']) . $badge . $status . $dueLabel . ')</i></li>';
        };
        
        // Overdue section first
        if (!empty($overdue)) {
            $html .= '<h3>⚠️ Overdue — Clear These First</h3><ul>';
            foreach ($overdue as $t) $html .= $renderTask($t);
            $html .= '</ul>';
        }
        
        // Due today
        if (!empty($dueToday)) {
            $html .= '<h3>🎯 Due Today</h3><ul>';
            foreach ($dueToday as $t) $html .= $renderTask($t);
            $html .= '</ul>';
        }
        
        // Upcoming
        if (!empty($upcoming)) {
            $html .= '<h3>📅 Coming Up — Next 2 Days</h3><ul>';
            foreach ($upcoming as $t) $html .= $renderTask($t);
            $html .= '</ul>';
        }
        
        $html .= '<h3>💪 End of Day</h3><p>';
        if ($urgentCount > 0) {
            $html .= 'Knock out the ' . $urgentCount . ' urgent item' . ($urgentCount != 1 ? 's' : '') . ' first and the rest falls into place. ';
        }
        $html .= 'You\'ve got this, ' . htmlspecialchars($firstName) . '. 🚀</p>';
        
        return ['html' => $html, 'task_ids' => implode(',', $usedIds)];
    }
    
        // ===== CONTEXT BUILDER =====

    private static function buildContext($wsId, $userId) {
        $projects = DB::fetchAll(
            "SELECT p.*, 
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status != 'completed' ) as open_tasks,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'completed') as done_tasks
             FROM projects p WHERE p.workspace_id = ?",
            [$wsId]
        );
        
        $myTasks = DB::fetchAll(
            "SELECT t.id, t.title, t.status, t.priority, t.deadline_date, p.name as project, p.id as project_id 
             FROM tasks t JOIN projects p ON t.project_id = p.id 
             WHERE t.assigned_to = ? AND t.status NOT IN ('completed') ORDER BY t.deadline_date ASC LIMIT 15",
            [$userId]
        );
        
        $ctx = "Current workspace context:\n\nProjects:\n";
        foreach ($projects as $p) {
            $progress = calculateProgress($p['id']);
            $ctx .= "- [Project ID:{$p['id']}] {$p['name']}: " . healthLabel($p['health']) . ", {$progress}% done, {$p['open_tasks']} open / {$p['done_tasks']} done";
            if ($p['due_date']) $ctx .= ", due {$p['due_date']}";
            $ctx .= "\n";
        }
        
        if (count($myTasks) > 0) {
            $ctx .= "\nMy open tasks (with task IDs for linking):\n";
            foreach ($myTasks as $t) {
                $ctx .= "- [Task ID:{$t['id']}] [{$t['status']}] [{$t['priority']}] {$t['title']} ({$t['project']}";
                if ($t['deadline_date']) $ctx .= ", due {$t['deadline_date']}";
                $ctx .= ")\n";
            }
        }
        
        return $ctx;
    }
    
    // ===== CONVERT MARKDOWN TO HTML =====
    private static function toHtml($text) {
        // Fix escaped closing tags — handle all levels of escaping
        // Pattern: <\/tag> or <\\/tag>
        $text = preg_replace('/<\\+\/(b|i|a|li|ul|ol|p|h[1-6]|strong|em|div|span|code|pre|blockquote)>/i', '</$1>', $text);
        
        // Fix any remaining escaped slashes in tags
        $text = str_replace('\/', '/', $text);
        
        // Fix literal \n (not actual newlines)
        $text = str_replace("\\n", "\n", $text);
        
        // Fix literal \t
        $text = str_replace("\\t", "\t", $text);
        
        // Strip markdown code fences
        $text = preg_replace('/^```html?\s*$/im', '', $text);
        $text = preg_replace('/^```\s*$/im', '', $text);
        
        // If it contains a full HTML document, extract the body
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $text, $m)) {
            $text = $m[1];
        }
        
        // Strip script/style tags
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
        $text = preg_replace('/<!DOCTYPE[^>]*>/i', '', $text);
        $text = preg_replace('/<html[^>]*>/i', '', $text);
        $text = preg_replace('/<head>.*?<\/head>/is', '', $text);
        $text = preg_replace('/<\/html>/i', '', $text);
        $text = preg_replace('/<body[^>]*>/i', '', $text);
        $text = preg_replace('/<\/body>/i', '', $text);
        $text = preg_replace('/<title>[^<]*<\/title>/i', '', $text);
        
        // Strip HTML comments
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        
        // Strip class attributes with tailwind/utility classes
        $text = preg_replace('/\sclass="[^"]*"/i', '', $text);
        $text = preg_replace("/\sclass='[^']*'/i", '', $text);
        
        // Strip inline styles that use tailwind-like patterns
        $text = preg_replace('/\sstyle="[^"]*"/i', '', $text);
        
        // Remove empty div/span wrappers
        $text = preg_replace('/<div>\s*<\/div>/i', '', $text);
        $text = preg_replace('/<span>\s*<\/span>/i', '', $text);
        
        // Unwrap divs that just contain content (convert to simple structure)
        $text = preg_replace('/<div[^>]*>(.*?)<\/div>/is', '$1', $text);
        $text = preg_replace('/<section[^>]*>(.*?)<\/section>/is', '$1', $text);
        $text = preg_replace('/<span[^>]*>(.*?)<\/span>/is', '$1', $text);
        
        // Convert strong to b
        $text = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '<b>$1</b>', $text);
        $text = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '<i>$1</i>', $text);
        
        // Add ai-link class to any <a href="#task- or #project->
        $text = preg_replace('/<a href="#(task|project)-([^"]+)"(?![^>]*class=)(?![^>]*data-type=)/', '<a href="#$1-$2" class="ai-link" data-type="$1" data-id="$2"', $text);
        
        // Second pass: add ai-link class to links with data-type but no class
        $text = preg_replace('/<a href="#(task|project)-([^"]+)"(?![^>]*class=)([^>]*data-type=)/', '<a href="#$1-$2" class="ai-link" $3', $text);

        // Clean up extra whitespace
        $text = preg_replace('/\n\s*\n/', "\n", $text);
        $text = trim($text);
        
        // If already contains HTML tags, return cleaned
        if (preg_match('/<[hpu][1-6]?[a-z]*[ >]/i', $text)) {
            return trim($text);
        }
        
        $html = $text;
        
        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h3>$1</h3>', $html);
        
        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $html);
        
        // Italic
        $html = preg_replace('/\*(.+?)\*/', '<i>$1</i>', $html);
        
        // Bullet lists
        $lines = explode("\n", $html);
        $inList = false;
        $result = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-*] (.+)$/', $line, $m)) {
                if (!$inList) { $result[] = '<ul>'; $inList = true; }
                $result[] = '<li>' . $m[1] . '</li>';
            } else {
                if ($inList) { $result[] = '</ul>'; $inList = false; }
                $result[] = $line;
            }
        }
        if ($inList) $result[] = '</ul>';
        $html = implode("\n", $result);
        
        // Paragraphs (wrap non-tag lines)
        $html = preg_replace('/^(?!<[hupoli])(.+)$/m', '<p>$1</p>', $html);
        
        // Clean up empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        
        return $html;
    }
    
    // ===== AI PROVIDERS =====
    private static function callAnthropic($config, $prompt, $systemPrompt) {
        $apiKey = Crypto::decrypt($config['api_key']); // decrypt secret at rest (H6)
        $baseUrl = $config['base_url'] ?: 'https://api.anthropic.com';
        $model = $config['model'] ?: 'claude-sonnet-4-20250514';
        
        $payload = json_encode([
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        
        $ch = curl_init("$baseUrl/v1/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("Anthropic API error ($httpCode)");
        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? 'No response';
    }
    
    private static function callOpenAI($config, $prompt, $systemPrompt) {
        $apiKey = Crypto::decrypt($config['api_key']); // decrypt secret at rest (H6)
        $baseUrl = $config['base_url'] ?: 'https://api.openai.com';
        $model = $config['model'] ?: 'gpt-4o';
        
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1024,
        ]);
        
        $ch = curl_init("$baseUrl/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("OpenAI API error ($httpCode)");
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? 'No response';
    }
    
    private static function callGemini($config, $prompt, $systemPrompt) {
        $apiKey = Crypto::decrypt($config['api_key']); // decrypt secret at rest (H6)
        $baseUrl = $config['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta';
        $model = $config['model'] ?: 'gemini-2.0-flash';
        
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $systemPrompt . "\n\n" . $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 1024],
        ]);
        
        $ch = curl_init("$baseUrl/models/" . rawurlencode($model) . ":generateContent?key=" . urlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("Gemini API error ($httpCode)");
        $data = json_decode($response, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';
    }
    
    /**
     * Ollama, local or cloud — one call for both, because the only difference
     * is the host and a Bearer token.
     */
    private static function callOllama($config, $prompt, $systemPrompt, $provider = 'ollama') {
        $isCloud = ($provider === 'ollama_cloud');
        $baseUrl = $config['base_url'] ?: ($isCloud ? 'https://ollama.com' : 'http://localhost:11434');
        $model   = $config['model'] ?: ($isCloud ? 'kimi-k2.5' : 'llama3.2');
        $label   = $isCloud ? 'Ollama Cloud' : 'Ollama';

        $messages = [];
        if ($systemPrompt !== '') $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $headers = ['Content-Type: application/json'];
        if (!empty($config['api_key'])) $headers[] = 'Authorization: Bearer ' . Crypto::decrypt($config['api_key']);
        elseif ($isCloud) throw new Exception('Ollama Cloud needs an API key. Add one in Settings → AI Connections.');

        $ch = curl_init(rtrim($baseUrl, '/') . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => $messages, 'stream' => false]),
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("$label API error ($httpCode)");
        $data = json_decode($response, true);
        return $data['message']['content'] ?? ($data['response'] ?? 'No response');
    }
}
