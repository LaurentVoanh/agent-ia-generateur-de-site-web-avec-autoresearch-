[Mistral AI](https://img.shields.io/badge/Mistral%20AI-API-orange)](https://mistral.ai)
[![License](https://img.shields.io/badge/Licence-MIT-green)](LICENSE)

Générez un site web complet (HTML, CSS, JS, PHP) à partir d’une simple **description textuelle** grâce à l’intelligence artificielle de Mistral.  
L’orchestrateur intègre un processus itératif de **planification**, **codage**, **test** et **amélioration automatique** avec mémoire de renforcement (RL).

> ⚠️ Projet technique de démonstration – nécessite un serveur PHP et des clés API Mistral valides.

---

## ✨ Fonctionnalités

- 🔍 **Compréhension du besoin** : un prompt utilisateur décrit le site souhaité (blog, e‑commerce, portfolio, etc.)
- 🧭 **Planification automatique** : génération d’un plan (sitemap, technologies, notes)
- 💻 **Codage complet** : production de tous les fichiers (HTML, CSS, JS, PHP) dans un dossier `apps/`
- ✅ **Tests intégrés** :
  - Validation syntaxique PHP (`php -l`)
  - Analyse de qualité par Mistral (score, erreurs, corrections)
- 🔁 **Boucle d’amélioration** : jusqu’à 3 itérations si le score est < 85/100
- 🧠 **Mémoire RL** : historique des erreurs et corrections pour guider les prochaines générations
- 🔑 **Rotation de clés API** & **gestion des limites (rate limit)** avec attente exponentielle
- 🖥️ **Console web temps réel** : affiche la progression (plan, code, tests, résultat final)

---

## 📋 Prérequis

- Serveur web (Apache / Nginx) avec **PHP 7.4+**
- Extension **SQLite3** activée
- Extension **cURL** activée
- Fonction `exec()` autorisée (pour `php -l`)
- **Compte Mistral AI** et au moins une clé API (deux ou trois recommandées)

---

## 🚀 Installation

1. Clonez ce dépôt ou copiez `index.php` dans la racine de votre serveur web.

2. Ouvrez le fichier `index.php` et **remplacez les clés API factices** par vos propres clés Mistral :

```php
$MISTRAL_KEYS = [
    'VOTRE_CLE_1',
    'VOTRE_CLE_2',
    'VOTRE_CLE_3'
];
Assurez-vous que le serveur PHP a les droits d’écriture dans le répertoire courant (création de dossiers 000000001/, database.db, state.json).

Accédez à index.php via votre navigateur.

🎮 Utilisation
Saisissez la description du site souhaité dans le champ de texte.
Exemple : « Un blog sur le jazz avec articles, commentaires et formulaire de contact PHP »

Cliquez sur Générer le projet.

Une nouvelle console s’ouvre (console.php) et affiche en direct :

Analyse du prompt

Planification (sitemap, tech)

Génération des fichiers

Tests (syntaxe + qualité IA)

Score et éventuelles corrections

Une fois terminé, un lien 🌐 Ouvrir le site apparaît. Cliquez pour voir le site généré.

Si le résultat ne vous convient pas, utilisez le bouton 🔄 Améliorer le site pour relancer une itération.

🧠 Comment ça marche ?
1. Création du projet (index.php)
Un dossier nommé 000000001, 000000002, etc. est créé.

Il contient :

database.db (SQLite) – stocke le prompt et la mémoire RL

data.json – prompt original

console.php – l’orchestrateur complet

2. Orchestrateur (console.php)
État géré via state.json (init, planning, coding, testing, done).

Appels Mistral avec rotation de clés, timeout 180s, fallback JSON.

Plan : un appel au modèle deep (mistral-large-2411) génère un sitemap.

Code : un appel au modèle default (magistral-small-2509) produit tous les fichiers.

Test :

Exécute php -l sur les fichiers .php.

Envoie le code à Mistral (modèle critique mistral-large-2512) qui renvoie un score et des corrections.

Mémoire RL : les erreurs et correctifs sont sauvegardés en base et réinjectés dans les prompts suivants.

3. Itérations
Si le score final < 85 et que le nombre de tentatives < 3, l’orchestrateur repasse en phase coding avec les corrections suggérées.

📁 Structure d’un projet généré
text
000000001/
├── apps/                     # Tous les fichiers du site (HTML, CSS, JS, PHP)
│   ├── index.html
│   ├── style.css
│   ├── script.js
│   └── contact.php
├── database.db               # SQLite (site_config, rl_memory)
├── data.json                 # Prompt original
├── console.php               # Orchestrateur
├── state.json                # État courant (plan, score, retry_count)
└── orchestrator_errors.log   # Log des erreurs PHP
⚙️ Personnalisation
Vous pouvez modifier dans console.php :

Modèles Mistral :

php
'default_model' => 'magistral-small-2509',
'deep_model'    => 'mistral-large-2411',
'critique_model'=> 'mistral-large-2512'
Seuil de qualité (ligne if ($score < 85 ...))

Nombre max de tentatives (variable $state['retry_count'] < 3)

Tokens max par appel (actuellement 16384 pour le code)

⚠️ Avertissements et limites
Sécurité : le code généré peut contenir des vulnérabilités. À utiliser uniquement en environnement de test.

Dépendance externe : nécessite une connexion internet et des crédits API Mistral.

Temps d’exécution : une génération complète peut prendre 1 à 3 minutes.

exec() : doit être autorisée (utilisée uniquement pour php -l).

Rate limit : la rotation des clés et les attentes sont gérées, mais un usage intensif peut quand même rencontrer des limites.

🧪 Exemple
Prompt :

Site vitrine pour une pâtisserie artisanale avec galerie photo et formulaire de réservation.

Résultat généré (extrait du plan) :

json
{
  "sitemap": ["index.html", "galerie.html", "reservation.php", "style.css"],
  "tech": "html/css/js/php",
  "notes": "Formulaire de réservation avec validation PHP et envoi par email."
}
La console produit ensuite les fichiers correspondants, les teste, propose des améliorations (accessibilité, responsive) et livre un site fonctionnel.

📄 Licence
MIT – libre d’utilisation et de modification.

🙏 Remerciements
Mistral AI pour ses modèles performants.

L’approche “plan → code → test → RL” inspirée des agents autonomes.

Construisez des sites web… sans écrire une ligne de code !
