<?php
// === CRÉATEUR DE PROJET INTELLIGENT (VERSION CORRIGÉE) ===
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('UTC');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['prompt'])) {
    $prompt = trim($_POST['prompt']);
    
    // Création d'un ID unique pour le projet
    $dirs = glob(__DIR__ . '/[0-9]*', GLOB_ONLYDIR);
    $max = 0;
    foreach ($dirs as $d) {
        $n = (int) basename($d);
        if ($n > $max) $max = $n;
    }
    $id = str_pad($max + 1, 9, '0', STR_PAD_LEFT);
    $projectPath = __DIR__ . '/' . $id;
    $appsPath = $projectPath . '/apps';
    
    if (!mkdir($appsPath, 0755, true)) {
        die("Erreur : impossible de créer le dossier du projet.");
    }
    
    // Sauvegarde du prompt utilisateur
    file_put_contents($projectPath . '/data.json', json_encode(['prompt' => $prompt, 'created' => date('c')], JSON_PRETTY_PRINT));
    
    // Initialisation SQLite
    try {
        $db = new SQLite3($projectPath . '/database.db');
        $db->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
        $db->exec("CREATE TABLE IF NOT EXISTS rl_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
$APPS_DIR = $DIR . '/apps';

// === VOS CLÉS API MISTRAL (À REMPLACER PAR LES VRAIES) ===
$MISTRAL_KEYS = [
    '5qaRTjWhdhtudXcdEP5ZbH8Rake',
    'hgfo3rG1zvhgf3J3eHXRShytu',
    'vEhzQMKN74EhfhJ30ENDhfjFruXkF'
];

$MISTRAL_CONFIG = [
    'keys' => $MISTRAL_KEYS,
    'current_index' => 0,
    'default_model' => 'mistral-small-2503',
    'deep_model'    => 'mistral-large-2411',
    'critique_model'=> 'mistral-large-2411'
];

$stateFile = $DIR . '/state.json';
if (!file_exists($stateFile)) {
    file_put_contents($stateFile, json_encode([
        'status' => 'init',
        'prompt' => '',
        'retry_count' => 0,
        'score' => 0,
        'done' => false,
        'plan' => null,
        'files' => 0
    ], JSON_PRETTY_PRINT), LOCK_EX);
}
$state = json_decode(file_get_contents($stateFile), true);

// === FONCTIONS DB ===
function initDB() {
    $db = new SQLite3(__DIR__ . '/database.db');
    $db->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS rl_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, task TEXT, errors TEXT, fixes TEXT, success INTEGER, ts TEXT)");
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
    $result = getDB()->query("SELECT task, errors, fixes, success FROM rl_memory ORDER BY id DESC LIMIT 5");
    if (!$result) return "Aucun historique.";
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = "- Tâche: {$row['task']} | Erreurs: {$row['errors']} | Fix: {$row['fixes']} | Succès: " . ($row['success'] ? 'Oui' : 'Non');
    }
    return empty($rows) ? "Aucun historique." : "HISTORIQUE RL :\n" . implode("\n", $rows);
}
function saveRL($task, $err, $fix, $suc) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO rl_memory (task, errors, fixes, success, ts) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bindValue(1, $task);
    $stmt->bindValue(2, $err);
    $stmt->bindValue(3, $fix);
    $stmt->bindValue(4, (int)$suc);
    $stmt->bindValue(5, date('Y-m-d H:i:s'));
    $stmt->execute();
}

// === APPEL MISTRAL AVEC ROTATION ET GESTION 429 ===
function mistral($messages, $model, $maxTokens = 4096) {
    global $MISTRAL_KEYS, $MISTRAL_CONFIG;
    $maxAttempts = count($MISTRAL_KEYS) * 2;
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
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $content = $data['choices'][0]['message']['content'];
                // Extraction du JSON (certains modèles ajoutent du texte avant)
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $json = json_decode($matches[0], true);
                    if ($json !== null) {
                        // Rotation vers la clé suivante
                        $MISTRAL_CONFIG['current_index'] = ($keyIndex + 1) % count($MISTRAL_KEYS);
                        return $json;
                    }
                }
                $lastError = "JSON invalide : " . substr($content, 0, 200);
            } else {
                $lastError = "Structure de réponse inattendue";
            }
        } elseif ($httpCode === 429) {
            $wait = min(30, pow(2, $attempt % 4));
            error_log("Rate limit sur clé $keyIndex, attente {$wait}s (tentative $attempt)");
            sleep($wait);
            continue;
        } else {
            $lastError = "HTTP $httpCode, réponse: " . substr($response, 0, 200);
        }
        error_log($lastError);
    }
    throw new Exception("Échec après $maxAttempts tentatives. Dernière erreur : $lastError");
}

// === ROUTEUR AJAX ===
if (isset($_REQUEST['action'])) {
    set_time_limit(300);
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
                $out['log'] = "🔍 Analyse de la demande : \"$prompt\"";
                break;
                
            case 'plan':
                $system = "Tu es un architecte web expert. Réponds UNIQUEMENT en JSON valide avec cette structure : {\"sitemap\": [\"index.html\", ...], \"tech\": \"html/css/js/php\", \"notes\": \"...\"}";
                $user = "Projet : $prompt\n" . getRL();
                $plan = mistral([['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]], $GLOBALS['MISTRAL_CONFIG']['deep_model'], 2048);
                if (!isset($plan['sitemap']) || !is_array($plan['sitemap'])) {
                    $plan['sitemap'] = ['index.html'];
                }
                if (!isset($plan['tech'])) $plan['tech'] = 'html/css/js/php';
                if (!isset($plan['notes'])) $plan['notes'] = 'Site généré automatiquement.';
                $state['plan'] = $plan;
                $state['status'] = 'coding';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = "📐 Plan reçu : " . count($plan['sitemap']) . " page(s). Passage au codage...";
                break;
                
            case 'code':
                $system = "Tu es un développeur full-stack. Génére TOUS les fichiers nécessaires pour le site. Réponse JSON : {\"files\": [{\"path\":\"apps/index.html\",\"content\":\"...\"}, ...]} IMPORTANT : tous les chemins doivent commencer par 'apps/' (ex: apps/style.css). Contenu complet et fonctionnel.";
                $user = "Plan du site :\n" . json_encode($state['plan'], JSON_PRETTY_PRINT) . "\n\n" . getRL();
                $code = mistral([['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]], $GLOBALS['MISTRAL_CONFIG']['default_model'], 16384);
                $written = 0;
                if (!empty($code['files']) && is_array($code['files'])) {
                    foreach ($code['files'] as $file) {
                        if (!isset($file['path']) || !isset($file['content'])) continue;
                        // Forcer le chemin à commencer par apps/ si ce n'est pas déjà le cas
                        $relPath = ltrim($file['path'], '/');
                        if (strpos($relPath, 'apps/') !== 0) {
                            $relPath = 'apps/' . $relPath;
                        }
                        $fullPath = $DIR . '/' . $relPath;
                        $dir = dirname($fullPath);
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        if (file_put_contents($fullPath, $file['content'])) {
                            $written++;
                        } else {
                            error_log("Échec écriture : $fullPath");
                        }
                    }
                }
                $state['files'] = $written;
                $state['status'] = 'testing';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = $written ? "💾 $written fichiers écrits. Démarrage des tests..." : "⚠️ Aucun fichier généré. Nouvel essai...";
                if (!$written && ($state['retry_count'] ?? 0) < 2) {
                    $state['retry_count'] = ($state['retry_count'] ?? 0) + 1;
                    $state['status'] = 'coding';
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                }
                break;
                
            case 'test':
                $score = 100;
                $errors = "";
                // Test syntaxique PHP
                foreach (glob($APPS_DIR . "/*.php") as $phpFile) {
                    exec("php -l " . escapeshellarg($phpFile) . " 2>&1", $output, $returnCode);
                    if ($returnCode !== 0) {
                        $score -= 15;
                        $errors .= "Erreur PHP dans " . basename($phpFile) . " : " . implode("\n", $output) . "\n";
                    }
                }
                // Lecture de tous les fichiers générés pour QA
                $allFilesContent = "";
                foreach (glob($APPS_DIR . "/*") as $f) {
                    if (is_file($f)) {
                        $allFilesContent .= "=== " . basename($f) . " ===\n" . file_get_contents($f) . "\n\n";
                    }
                }
                // Appel QA à Mistral
                $qaSystem = "QA expert. Réponds JSON : {\"score\":0-100,\"errors\":\"...\",\"fixes\":\"...\"}";
                $qaUser = "Voici le code généré :\n$allFilesContent\n" . getRL();
                $qa = mistral([['role'=>'system','content'=>$qaSystem], ['role'=>'user','content'=>$qaUser]], $GLOBALS['MISTRAL_CONFIG']['critique_model'], 4096);
                $score = min(100, $score + ($qa['score'] ?? 0));
                $errors .= ($qa['errors'] ?? "") . "\n";
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
                    saveRL($prompt, $errors ?: "Aucune erreur détectée", $fixes, true);
                    $out['log'] = "✅ Site validé (score $score/100). Prêt à être consulté !";
                    $out['done'] = true;
                }
                break;
                
            default:
                $out['error'] = true;
                $out['log'] = "Action inconnue.";
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
    <title>Console du projet - Génération IA</title>
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
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 200000);
                const resp = await fetch(`?action=${action}`, { signal: controller.signal });
                clearTimeout(timeout);
                if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                const text = await resp.text();
                let data;
                try { data = JSON.parse(text); } catch(e) { throw new Error('Réponse JSON invalide'); }
                return data;
            } catch(e) {
                log(`⚠️ Tentative ${attempt}/${retries} échouée : ${e.message}`, true);
                if (attempt === retries) throw e;
                await new Promise(r => setTimeout(r, 2000 * attempt));
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
            // Gestion des reprises automatiques
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
    
    // Écriture de l'orchestrateur dans le projet
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
