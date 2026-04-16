# 🧠 Créateur de site intelligent (AI Web Architect)

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)](https://php.net)
[![Mistral AI](https://img.shields.io/badge/Mistral%20AI-API-orange)](https://mistral.ai)
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
