<?php
// === CRÉATEUR DE PROJET INTELLIGENT (VERSION CORRIGÉE ET OPTIMISÉE) ===
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

// === VOS CLÉS API MISTRAL (ROTATION) ===
$MISTRAL_KEYS = [
     'gdfgfdgfdgfdgfdgfddEP5ZbH8Rake',
    'o3gfdgfdgfdgfdgfdgfdgHXRShytu',
    'vEzQMgfdgfdgfdgfdgfdENDjFruXkF'
];

// Configuration des modèles Mistral Free Tier
$MISTRAL_CONFIG = [
    'keys' => $MISTRAL_KEYS,
    'current_index' => 0,
    'default_model' => 'codestral-2508',     // Parfait pour la génération de code fichier par fichier
    'deep_model'    => 'mistral-large-2512', // Parfait pour l'architecture et la planification
    'critique_model'=> 'mistral-large-2512'  // Parfait pour l'analyse QA et les tests
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
function mistral($messages, $model, $maxTokens = 8192) {
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
                $content = trim($data['choices'][0]['message']['content']);
                
                // Extraction JSON robuste (Nettoyage des balises Markdown ```json générées par l'IA)
                $json = json_decode($content, true);
                
                if ($json === null) {
                    $stripped = preg_replace('/^```(?:json)?\s*/i', '', $content);
                    $stripped = preg_replace('/\s*```$/i', '', $stripped);
                    $json = json_decode(trim($stripped), true);
                }
                
                if ($json === null && preg_match('/\{.*\}/s', $content, $matches)) {
                    $json = json_decode($matches[0], true);
                }
                
                if ($json !== null) {
                    // Rotation vers la clé suivante après un succès
                    $MISTRAL_CONFIG['current_index'] = ($keyIndex + 1) % count($MISTRAL_KEYS);
                    return $json;
                }
                $lastError = "JSON invalide retourné par l'IA : " . substr($content, 0, 200);
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
    set_time_limit(300); // 5 minutes max par action
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
                // ÉTAPE DE PLANIFICATION (Architecture du projet)
                $system = "Tu es un architecte web expert. Réponds UNIQUEMENT en JSON valide avec cette structure : {\"sitemap\": [{\"path\":\"apps/index.html\", \"purpose\":\"Page d'accueil\"}, {\"path\":\"apps/style.css\", \"purpose\":\"Styles globaux\"}], \"tech\": \"html/css/js/php\", \"notes\": \"...\"}";
                $user = "Projet : $prompt\n" . getRL();
                $plan = mistral([['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]], $GLOBALS['MISTRAL_CONFIG']['deep_model'], 4096);
                
                if (!isset($plan['sitemap']) || !is_array($plan['sitemap'])) {
                    $plan['sitemap'] = [['path' => 'apps/index.html', 'purpose' => 'Accueil principal']];
                }
                
                $state['plan'] = $plan;
                $state['status'] = 'coding';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                $out['log'] = "📐 Plan reçu : " . count($plan['sitemap']) . " fichier(s) prévu(s). Passage au codage (Codestral)...";
                break;
                
            case 'code':
                // ÉTAPE DE CODAGE (Fichier par Fichier avec Codestral pour éviter la troncature)
                if (!isset($state['plan']['sitemap']) || !is_array($state['plan']['sitemap'])) {
                    throw new Exception("Plan introuvable, impossible de coder.");
                }
                
                $written = 0;
                $totalFiles = count($state['plan']['sitemap']);
                
                foreach ($state['plan']['sitemap'] as $fileDef) {
                    $path = $fileDef['path'] ?? '';
                    $purpose = $fileDef['purpose'] ?? 'Générique';
                    
                    if (empty($path)) continue;
                    
                    // Forcer le chemin dans le dossier apps/
                    $relPath = ltrim($path, '/');
                    if (strpos($relPath, 'apps/') !== 0) {
                        $relPath = 'apps/' . $relPath;
                    }
                    
                    $systemCode = "Tu es Codestral, un développeur full-stack d'élite. Tu dois générer LE CONTENU COMPLET ET FONCTIONNEL d'un seul fichier à la fois. Réponds UNIQUEMENT par un JSON : {\"path\":\"$relPath\", \"content\":\"LE CODE SOURCE COMPLET ICI\"}. Ne mets RIEN d'autre.";
                    $userCode = "Plan global du site : " . json_encode($state['plan']['notes'] ?? '') . "\n\nFICHIER À CODER MAINTENANT :\nChemin : $relPath\nRôle : $purpose\n\nÉcris le code de manière propre, sécurisée (si PHP) et design.\n" . getRL();
                    
                    try {
                        $codeRes = mistral([['role'=>'system','content'=>$systemCode], ['role'=>'user','content'=>$userCode]], $GLOBALS['MISTRAL_CONFIG']['default_model'], 8192);
                        
                        if (!empty($codeRes['content'])) {
                            $fullPath = $DIR . '/' . $relPath;
                            $dir = dirname($fullPath);
                            if (!is_dir($dir)) mkdir($dir, 0755, true);
                            
                            if (file_put_contents($fullPath, $codeRes['content'])) {
                                $written++;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Erreur de génération pour $relPath : " . $e->getMessage());
                        // On continue pour essayer de générer les autres fichiers
                    }
                }
                
                $state['files'] = $written;
                $state['status'] = 'testing';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
                
                if ($written > 0) {
                    $out['log'] = "💾 $written/$totalFiles fichiers écrits avec succès par Codestral. Démarrage des tests...";
                } else {
                    $out['log'] = "⚠️ Aucun fichier généré. Nouvel essai...";
                    if (($state['retry_count'] ?? 0) < 2) {
                        $state['retry_count'] = ($state['retry_count'] ?? 0) + 1;
                        $state['status'] = 'coding';
                        file_put_contents($stateFile, json_encode($state), LOCK_EX);
                    }
                }
                break;
                
            case 'test':
                // ÉTAPE DE QUALITÉ ET CORRECTION
                $score = 100;
                $errors = "";
                
                // Test syntaxique PHP natif
                foreach (glob($APPS_DIR . "/*.php") as $phpFile) {
                    exec("php -l " . escapeshellarg($phpFile) . " 2>&1", $output, $returnCode);
                    if ($returnCode !== 0) {
                        $score -= 20;
                        $errors .= "Erreur PHP Fatale dans " . basename($phpFile) . " : " . implode("\n", $output) . "\n";
                    }
                }
                
                // Lecture de tous les fichiers générés pour l'audit
                $allFilesContent = "";
                foreach (glob($APPS_DIR . "/*") as $f) {
                    if (is_file($f)) {
                        $allFilesContent .= "=== " . basename($f) . " ===\n" . file_get_contents($f) . "\n\n";
                    }
                }
                
                // Audit par Mistral Large
                $qaSystem = "Tu es un auditeur de code et testeur QA expert. Analyse le code suivant. Réponds STRICTEMENT en JSON : {\"score\":0-100,\"errors\":\"Liste des bugs critiques ou design manquant\",\"fixes\":\"Ce qu'il faut corriger pour le prochain essai\"}";
                $qaUser = "Voici tout le code généré :\n$allFilesContent\n" . getRL() . "\n\nErreurs Linter PHP : $errors";
                
                try {
                    $qa = mistral([['role'=>'system','content'=>$qaSystem], ['role'=>'user','content'=>$qaUser]], $GLOBALS['MISTRAL_CONFIG']['critique_model'], 4096);
                    $score = min(100, $score - (100 - ($qa['score'] ?? 100)));
                    $errors .= ($qa['errors'] ?? "") . "\n";
                    $fixes = $qa['fixes'] ?? "Optimisations globales nécessaires.";
                } catch (Exception $e) {
                    $fixes = "Corriger les erreurs syntaxiques.";
                }
                
                $state['score'] = $score;
                
                if ($score < 80 && ($state['retry_count'] ?? 0) < 3) {
                    // Boucle d'apprentissage (RL Memory)
                    $state['retry_count'] = ($state['retry_count'] ?? 0) + 1;
                    $state['status'] = 'coding';
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                    saveRL($prompt, $errors, $fixes, false);
                    $out['log'] = "⚠️ Score de qualité insuffisant : $score/100. Auto-Correction en cours (Essai {$state['retry_count']}/3)...";
                } else {
                    $state['status'] = 'done';
                    $state['done'] = true;
                    file_put_contents($stateFile, json_encode($state), LOCK_EX);
                    saveRL($prompt, $errors ?: "Aucune erreur détectée", $fixes ?? "Aucun correctif nécessaire", true);
                    $out['log'] = "✅ Site validé et testé (Score : $score/100). Le projet est prêt !";
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
        .header {display:flex; justify-content:space-between; margin-bottom: 15px;}
        #console{flex:1;background:#0a0a0a;border:1px solid var(--brd);padding:15px;overflow-y:auto;white-space:pre-wrap;font-size:14px;line-height:1.6}
        #controls{margin-top:15px;text-align:center;display:none}
        #controls a,#controls button{display:inline-block;padding:10px 20px;margin:0 8px;border:1px solid var(--txt);background:transparent;color:var(--txt);text-decoration:none;cursor:pointer;font-weight:bold; transition:0.3s;}
        #controls a:hover,#controls button:hover{background:var(--txt);color:var(--bg);}
        .cursor::after{content:'▋';animation:clignote 1s step-end infinite}
        @keyframes clignote{50%{opacity:0}}
        .error{color:var(--err);font-weight:bold}
    </style>
</head>
<body>
<div class="header">
    <h2>🛠️ Agent IA - Orchestrateur</h2>
    <span id="status">Statut: Initialisation...</span>
</div>
<div id="console" class="cursor"></div>
<div id="controls">
    <a href="apps/index.html" target="_blank">🌐 Ouvrir le site généré</a>
    <button onclick="location.reload()">🔄 Améliorer / Relancer le site</button>
</div>
<script>
    const consoleDiv = document.getElementById('console');
    const ctrlDiv = document.getElementById('controls');
    const statusSpan = document.getElementById('status');
    const steps = ['start', 'plan', 'code', 'test'];
    let stepIndex = 0, running = true;
    
    function log(msg, isErr=false) {
        const line = document.createElement('div');
        if (isErr) line.className = 'error';
        line.textContent = '> ' + msg;
        consoleDiv.appendChild(line);
        consoleDiv.scrollTop = consoleDiv.scrollHeight;
    }
    
    async function call(action, retries=3) {
        for (let attempt=1; attempt<=retries; attempt++) {
            try {
                const controller = new AbortController();
                // Limite JS augmentée à 300s (5min) pour correspondre à la génération fichier par fichier
                const timeout = setTimeout(() => controller.abort(), 300000);
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
                await new Promise(r => setTimeout(r, 3000 * attempt));
            }
        }
    }
    
    async function run() {
        if (!running || stepIndex >= steps.length) {
            running = false;
            ctrlDiv.style.display = 'block';
            consoleDiv.classList.remove('cursor');
            statusSpan.textContent = 'Statut: Terminé';
            log('🏁 Construction terminée. Vous pouvez vérifier le site ci-dessous.');
            return;
        }
        
        const step = steps[stepIndex];
        statusSpan.textContent = `Statut: ${step.toUpperCase()} en cours...`;
        log(`⏳ [${step.toUpperCase()}] Démarrage de l'étape...`);
        
        try {
            const result = await call(step);
            log(result.log || 'Étape exécutée avec succès.', result.error);
            
            if (result.done) {
                running = false;
                ctrlDiv.style.display = 'block';
                consoleDiv.classList.remove('cursor');
                statusSpan.textContent = 'Statut: Prêt';
                return;
            }
            
            // Gestion des reprises automatiques (Reinforcement Learning local)
            if (result.status === 'coding' && step === 'test') {
                log('🔄 Retour à la phase de développement pour corriger les erreurs...', true);
                stepIndex = steps.indexOf('code');
            } else {
                stepIndex++;
            }
        } catch(e) {
            log(`❌ Échec définitif sur l'étape ${step} : ${e.message}`, true);
            running = false;
            ctrlDiv.style.display = 'block';
            consoleDiv.classList.remove('cursor');
            statusSpan.textContent = 'Statut: Erreur fatale';
        }
        
        setTimeout(run, 1500);
    }
    
    window.addEventListener('DOMContentLoaded', () => {
        log('🖥️ Connexion à Mistral IA et Codestral établie.');
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
        :root{--bg:#1a1b26;--txt:#c0caf5;--accent:#7aa2f7;}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--txt);font-family:system-ui,monospace;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .container{width:90%;max-width:600px;text-align:center; background:#24283b; padding:40px; border-radius:15px; box-shadow:0 15px 35px rgba(0,0,0,0.5);}
        h1{font-weight:300;letter-spacing:4px;text-transform:uppercase;margin-bottom:1.5rem;font-size:1.8rem; color:var(--accent);}
        p {margin-bottom: 2rem; color:#9aa5ce; line-height: 1.5;}
        form{display:flex;flex-direction:column;gap:1.5rem}
        textarea{background:#1a1b26;color:#c0caf5;border:1px solid #414868;padding:1.5rem;font-size:1.1rem;border-radius:8px;outline:none;transition:0.3s;resize:vertical;min-height:120px;font-family:inherit;}
        textarea:focus{border-color:var(--accent); box-shadow:0 0 10px rgba(122, 162, 247, 0.2);}
        button{background:var(--accent);color:#1a1b26;border:none;padding:1.2rem;font-size:1.1rem;font-weight:bold;cursor:pointer;letter-spacing:2px;text-transform:uppercase;border-radius:8px;transition:0.3s}
        button:hover{opacity:0.9;transform:translateY(-2px); box-shadow:0 10px 20px rgba(122, 162, 247, 0.3);}
    </style>
</head>
<body>
    <div class="container">
        <h1>DevAgent IA</h1>
        <p>Décrivez le site que vous souhaitez. L'agent IA planifiera la structure, écrira le code avec <b>Codestral</b>, et se corrigera automatiquement.</p>
        <form method="POST" autocomplete="off">
            <textarea name="prompt" placeholder="Ex: Un site vitrine pour un cabinet d'avocats sombre et moderne, avec une page de contact fonctionnelle en PHP..." required></textarea>
            <button type="submit">Déployer le projet</button>
        </form>
    </div>
</body>
</html>
