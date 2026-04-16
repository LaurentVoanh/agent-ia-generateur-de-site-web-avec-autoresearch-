<?php
// === CRÉATEUR DE PROJET (root index.php) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['prompt'])) {
    $prompt = trim($_POST['prompt']);
    
    // Trouver le prochain ID
    $dirs = glob(__DIR__ . '/[0-9]*', GLOB_ONLYDIR);
    $max = 0;
    foreach ($dirs as $d) {
        $n = (int) basename($d);
        if ($n > $max) $max = $n;
    }
    $id = str_pad($max + 1, 9, '0', STR_PAD_LEFT);
    $projectPath = __DIR__ . '/' . $id;
    mkdir($projectPath . '/apps', 0755, true);
    
    // Sauvegarde du prompt
    file_put_contents($projectPath . '/data.json', json_encode(['prompt' => $prompt], JSON_PRETTY_PRINT));
    
    // Initialisation de la base de données SQLite
    try {
        $db = new SQLite3($projectPath . '/database.db');
        $db->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
        $db->exec("CREATE TABLE IF NOT EXISTS rl_memory (
            task TEXT,
            errors TEXT,
            fixes TEXT,
            success INTEGER,
            ts TEXT
        )");
        $stmt = $db->prepare("INSERT OR REPLACE INTO site_config (key, value) VALUES ('prompt', :prompt)");
        $stmt->bindValue(':prompt', $prompt);
        $stmt->execute();
        $db->close();
    } catch (Exception $e) {
        die("Erreur SQLite : " . $e->getMessage());
    }
    
    // Template de l'orchestrateur (console.php)
    $orchestrator = <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/orchestrator_errors.log');

$DIR = __DIR__;

// === VOS 3 CLÉS API MISTRAL (remplacer par les vraies) ===
$MISTRAL_KEYS = ['hgfUjGJpAk5z35XhgfZbH8Rake','o3rhgfzvdhgfhgf4J3J3eHXRShytu','vEzQMKNhgfhgf8J30ENDjFruXkF'];



$MISTRAL_CONFIG = [
    'keys' => $MISTRAL_KEYS,
    'current_index' => 0,
    'default_model' => 'magistral-small-2509',
    'deep_model' => 'mistral-large-2411',
    'critique_model' => 'mistral-large-2512'
];

$stateFile = $DIR . '/state.json';
if (!file_exists($stateFile)) {
    file_put_contents($stateFile, json_encode([
        'status' => 'init',
        'prompt' => '',
        'retry_count' => 0,
        'score' => 0,
        'done' => false,
        'plan' => null
    ], JSON_PRETTY_PRINT), LOCK_EX);
}
$state = json_decode(file_get_contents($stateFile), true);

// === INITIALISATION DB ===
function initDB() {
    $db = new SQLite3(__DIR__ . '/database.db');
    $db->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS rl_memory (task TEXT, errors TEXT, fixes TEXT, success INTEGER, ts TEXT)");
    return $db;
}
function getDB() {
    static $db = null;
    if (!$db) $db = initDB();
    return $db;
}
function getPrompt() {
    $res = getDB()->querySingle("SELECT value FROM site_config WHERE key='prompt'");
    return ($res !== false && $res !== null) ? $res : 'Site générique';
}
function getRL() {
    $result = getDB()->query("SELECT task, errors, fixes, success FROM rl_memory ORDER BY rowid DESC LIMIT 5");
    if (!$result) return "Aucun historique.";
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return empty($rows) ? "Aucun historique." : "HISTORIQUE RL :\n" . implode("\n", array_map(fn($d) => 
        "- Tâche: {$d['task']} | Erreurs: {$d['errors']} | Fix: {$d['fixes']} | Succès: " . ($d['success'] ? 'Oui' : 'Non'), $rows));
}
function saveRL($task, $err, $fix, $suc) {
    $stmt = getDB()->prepare("INSERT INTO rl_memory (task, errors, fixes, success, ts) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bindValue(1, $task);
    $stmt->bindValue(2, $err);
    $stmt->bindValue(3, $fix);
    $stmt->bindValue(4, (int)$suc);
    $stmt->bindValue(5, date('Y-m-d H:i:s'));
    $stmt->execute();
}

// === APPEL MISTRAL AVEC ROTATION DES CLÉS ET GESTION 429 ===
function mistral($messages, $model = 'mistral-small-2506', $maxTokens = 4096, $retry = true) {
    global $MISTRAL_KEYS, $MISTRAL_CONFIG;
    $maxAttempts = count($MISTRAL_KEYS) * 3; // 3 essais max par clé
    $lastError = null;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $keyIndex = ($MISTRAL_CONFIG['current_index'] + $attempt) % count($MISTRAL_KEYS);
        $key = $MISTRAL_KEYS[$keyIndex];
        
        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object']
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $content = $data['choices'][0]['message']['content'];
                $finishReason = $data['choices'][0]['finish_reason'] ?? '';
                if ($finishReason === 'length' && $retry && $maxTokens < 32000) {
                    error_log("Troncature, réessai avec plus de tokens");
                    return mistral($messages, $model, $maxTokens * 2, false);
                }
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $json = json_decode($matches[0], true);
                    if ($json !== null) {
                        $MISTRAL_CONFIG['current_index'] = ($keyIndex + 1) % count($MISTRAL_KEYS);
                        return $json;
                    }
                }
                $lastError = "JSON invalide : " . substr($content, 0, 200);
            } else {
                $lastError = "Structure inattendue";
            }
        } elseif ($httpCode === 429) {
            $wait = min(30, pow(2, $attempt % 5));
            error_log("Rate limit sur clé $keyIndex, attente {$wait}s avant réessai (tentative $attempt)");
            sleep($wait);
            continue; // réessai avec la même clé ou la suivante
        } else {
            $lastError = "HTTP $httpCode, réponse: " . substr($response, 0, 200);
        }
        error_log($lastError);
    }
    throw new Exception('Échec après plusieurs tentatives. Dernière erreur : ' . $lastError);
}

// === ROUTEUR AJAX ===
if (isset($_REQUEST['action'])) {
    set_time_limit(0);
    ob_clean();
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    $out = ['log' => '', 'status' => $state['status'], 'done' => false, 'error' => false];
    try {
        $prompt = getPrompt();
        switch ($action) {
            case 'start':
                $state['status'] = 'planning';
                $state['prompt'] = $prompt;
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = "🔗 Analyse de la demande : \"$prompt\"";
                break;
            case 'plan':
                $system = "Architecte web. Réponds JSON : {\"sitemap\": [\"index.html\",...], \"tech\": \"html/css/js/php\", \"notes\": \"...\"}";
                $user = "Projet : $prompt\n" . getRL();
                $plan = mistral([['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]], $MISTRAL_CONFIG['deep_model'], 2048);
                if (!isset($plan['sitemap'])) $plan['sitemap'] = ['index.html'];
                $state['plan'] = $plan;
                $state['status'] = 'coding';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = "📐 Plan reçu : " . count($plan['sitemap']) . " page(s). Codage...";
                break;
            case 'code':
                $system = "Développeur full-stack. Générez TOUS les fichiers nécessaires. Réponse JSON : {\"files\": [{\"path\":\"apps/index.html\",\"content\":\"...\"}]}";
                $user = "Plan :\n" . json_encode($state['plan'], JSON_PRETTY_PRINT) . "\n" . getRL();
                $code = mistral([['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]], $MISTRAL_CONFIG['default_model'], 16384);
                $written = 0;
                if (!empty($code['files']) && is_array($code['files'])) {
                    foreach ($code['files'] as $f) {
                        $path = $DIR . '/' . ltrim($f['path'], '/');
                        @mkdir(dirname($path), 0755, true);
                        if (file_put_contents($path, $f['content'])) $written++;
                        else error_log("Impossible d'écrire : $path");
                    }
                }
                $state['files'] = $written;
                $state['status'] = 'testing';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = $written ? "💾 $written fichiers écrits. Tests..." : "⚠️ Aucun fichier généré. Nouvel essai...";
                if (!$written && ($state['retry_count'] ?? 0) < 3) {
                    $state['retry_count'] = ($state['retry_count'] ?? 0) + 1;
                    $state['status'] = 'coding';
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                }
                break;
            case 'test':
                $score = 100;
                $errors = '';
                // Vérification syntaxique PHP
                foreach (glob($DIR."/apps/*.php") as $f) {
                    exec("php -l ".escapeshellarg($f)." 2>&1", $outp, $ret);
                    if ($ret !== 0) {
                        $score -= 15;
                        $errors .= "Syntaxe PHP dans ".basename($f)."\n".implode("\n",$outp)."\n";
                    }
                }
                // QA par Mistral
                $filesContent = "";
                foreach (glob($DIR."/apps/*") as $f) {
                    if (is_file($f)) $filesContent .= basename($f)." :\n".file_get_contents($f)."\n---\n";
                }
                $qa = mistral([['role'=>'system','content'=>'QA expert. Réponds JSON : {"score":0-100,"errors":"...","fixes":"..."}'],
                               ['role'=>'user','content'=>"Code :\n$filesContent\n".getRL()]], $MISTRAL_CONFIG['critique_model'], 4096);
                $score = min(100, $score + ($qa['score'] ?? 0));
                $errors .= ($qa['errors'] ?? "")."\n";
                $fixes = $qa['fixes'] ?? "Optimisations mineures.";
                $state['score'] = $score;
                if ($score < 85 && ($state['retry_count'] ?? 0) < 3) {
                    $state['retry_count'] = ($state['retry_count'] ?? 0) + 1;
                    $state['status'] = 'coding';
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                    saveRL($prompt, $errors, $fixes, false);
                    $out['log'] = "⚠️ Score : $score/100. Correction {$state['retry_count']}/3...";
                } else {
                    $state['status'] = 'done';
                    $state['done'] = true;
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                    saveRL($prompt, $errors ?: "Aucune", $fixes, true);
                    $out['log'] = "✅ Site validé ($score/100). Prêt !";
                    $out['done'] = true;
                }
                break;
            default: $out['error'] = true; $out['log'] = "Action inconnue.";
        }
    } catch (Exception $e) {
        $out['log'] = "❌ " . $e->getMessage();
        $out['error'] = true;
        $out['status'] = 'error';
        error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    }
    echo json_encode($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Console du projet</title>
    <style>
        :root{--bg:#050505;--txt:#00ff9d;--err:#ff4444;--brd:#222}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--txt);font-family:monospace;padding:20px;min-height:100vh;display:flex;flex-direction:column}
        #console{flex:1;background:#0a0a0a;border:1px solid var(--brd);padding:15px;overflow-y:auto;white-space:pre-wrap;font-size:13px;line-height:1.6}
        #controls{margin-top:15px;text-align:center;display:none}
        #controls a,#controls button{display:inline-block;padding:10px 20px;margin:0 8px;border:1px solid var(--txt);background:transparent;color:var(--txt);text-decoration:none;cursor:pointer;font-weight:bold}
        .cursor::after{content:'▋';animation:clignote 1s step-end infinite}
        @keyframes clignote{50%{opacity:0}}
        .error{color:var(--err);font-weight:bold}
    </style>
</head>
<body>
<div id="console" class="cursor"></div>
<div id="controls">
    <a href="apps/index.html" target="_blank">🌐 Ouvrir le site</a>
    <button onclick="location.reload()">🔄 Améliorer le site</button>
</div>
<script>
    const consoleDiv = document.getElementById('console');
    const ctrlDiv = document.getElementById('controls');
    const steps = ['start', 'plan', 'code', 'test'];
    let stepIndex = 0, running = true;
    function log(msg, isErr=false) {
        const line = document.createElement('div');
        if (isErr) line.className = 'error';
        line.textContent = msg;
        consoleDiv.appendChild(line);
        consoleDiv.scrollTop = consoleDiv.scrollHeight;
    }
    async function call(action, retries=3) {
        for (let attempt=1; attempt<=retries; attempt++) {
            try {
                const ctrl = new AbortController();
                const timer = setTimeout(() => ctrl.abort(), 180000);
                const resp = await fetch(`?action=${action}`, { signal: ctrl.signal });
                clearTimeout(timer);
                if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                const txt = await resp.text();
                let data;
                try { data = JSON.parse(txt); } catch(e) { throw new Error('JSON invalide'); }
                return data;
            } catch(e) {
                log(`⚠️ Tentative ${attempt}/${retries} échouée : ${e.message}`, true);
                if (attempt === retries) throw e;
                await new Promise(r => setTimeout(r, 2000*attempt));
            }
        }
    }
    async function run() {
        if (!running || stepIndex >= steps.length) {
            running = false;
            ctrlDiv.style.display = 'block';
            consoleDiv.classList.remove('cursor');
            log('🏁 Construction terminée. Vous pouvez améliorer le site avec le bouton ci-dessus.');
            return;
        }
        const step = steps[stepIndex];
        log(`⏳ [${step.toUpperCase()}] ...`);
        try {
            const result = await call(step);
            log(result.log || 'Étape exécutée.', result.error);
            if (result.done) {
                running = false;
                ctrlDiv.style.display = 'block';
                consoleDiv.classList.remove('cursor');
                return;
            }
            if (result.status === 'coding' && step === 'test') {
                stepIndex = steps.indexOf('code');
            } else {
                stepIndex++;
            }
        } catch(e) {
            log(`❌ Échec définitif sur ${step} : ${e.message}`, true);
            running = false;
            ctrlDiv.style.display = 'block';
            consoleDiv.classList.remove('cursor');
        }
        setTimeout(run, 800);
    }
    window.addEventListener('DOMContentLoaded', () => {
        log('🖥️ Console active. Démarrage de la génération...');
        run();
    });
</script>
</body>
</html>
PHP;

    file_put_contents($projectPath . '/console.php', $orchestrator);
    
    // Redirection vers la console du projet
    header("Location: $id/console.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Créateur de site intelligent</title>
    <style>
        :root{--bg:#fff;--txt:#000}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--txt);font-family:system-ui,monospace;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .container{width:90%;max-width:500px;text-align:center}
        h1{font-weight:200;letter-spacing:6px;text-transform:uppercase;margin-bottom:3rem;font-size:1.4rem}
        form{display:flex;flex-direction:column;gap:1.2rem}
        input{background:transparent;border:1px solid var(--txt);padding:1rem;font-size:1rem;outline:none;transition:0.3s}
        input:focus{box-shadow:0 0 0 2px var(--txt)}
        button{background:var(--txt);color:var(--bg);border:none;padding:1rem;font-size:1rem;cursor:pointer;letter-spacing:3px;text-transform:uppercase;transition:0.3s}
        button:hover{opacity:0.85;transform:translateY(-2px)}
    </style>
</head>
<body>
    <div class="container">
        <h1>Architecte Web</h1>
        <form method="POST" autocomplete="off">
            <input type="text" name="prompt" placeholder="Décrivez le site que vous voulez (ex: un blog sur le jazz, un e-commerce pour plantes, un portfolio avec formulaire PHP...)" required>
            <button type="submit">Générer le projet</button>
        </form>
    </div>
</body>
</html>
