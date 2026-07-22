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
        'ollama' => [
            'label' => 'Ollama (Local)',
            'default_url' => 'http://localhost:11434',
            'default_model' => 'glm-5.1:cloud',
            'docs' => 'Run Ollama locally — no API key needed',
        ],
    ];
    
    public static function providers() {
        jsonResponse(['providers' => self::$providers]);
    }
    
    public static function ask() {
        $data = input();
        requireFields(['prompt'], $data);
        
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Find active provider (ollama first per Moe's request)
        $providers = ['ollama', 'anthropic', 'openai', 'gemini'];
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
                'ollama' => self::callOllama($config, $fullPrompt, $systemPrompt),
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
        $providers = ['ollama', 'anthropic', 'openai', 'gemini'];
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
                'ollama' => self::callOllama($config, $planPrompt, 'You are VGold, a helpful Workflow and CRM AI. Create clean HTML output.'),
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
    
    private static function callOllama($config, $prompt, $systemPrompt) {
        $baseUrl = $config['base_url'] ?: 'http://localhost:11434';
        $model = $config['model'] ?: 'glm-5.1:cloud';
        
        $fullPrompt = $systemPrompt . "\n\n" . $prompt;
        $payload = json_encode([
            'model' => $model,
            'prompt' => $fullPrompt,
            'stream' => false,
        ]);
        
        $ch = curl_init("$baseUrl/api/generate");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("Ollama API error ($httpCode)");
        $data = json_decode($response, true);
        $text = $data['response'] ?? 'No response';
        
        return $text;
    }
}
