<?php
// ============================================
// CONFIGURATION BASE DE DONNÉES - PostgreSQL
// ============================================
// Fonctionne en local (WampServer/PostgreSQL classique, valeurs par défaut
// ci-dessous) ET en production sur Vercel + Supabase (variables d'environnement
// définies dans le tableau de bord Vercel — voir DEPLOY.md).

define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT')     ?: '5432');
define('DB_NAME',     getenv('DB_NAME')     ?: 'gestion_cours');
define('DB_USER',     getenv('DB_USER')     ?: 'postgres');
define('DB_PASS',     getenv('DB_PASS')     ?: 'postgres');
define('DB_SSLMODE',  getenv('DB_SSLMODE')  ?: 'prefer'); // Supabase exige SSL ; 'prefer' fonctionne aussi en local sans SSL

// Mettre USE_DB_SESSIONS=1 UNIQUEMENT sur un hébergement serverless sans état
// (ex. Vercel), pour stocker les sessions en base (Supabase) au lieu de fichiers.
// Avec Render (conteneur persistant) ou en local, laisser cette variable absente :
// PHP utilise alors les sessions fichiers habituelles, qui fonctionnent normalement.
define('USE_DB_SESSIONS', getenv('USE_DB_SESSIONS') === '1');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=" . DB_SSLMODE;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            die('Connexion à la base de données impossible. Vérifiez vos identifiants (locaux ou variables d\'environnement Vercel/Supabase). Détail : ' . $e->getMessage());
        }
    }
    return $pdo;
}

// Démarrer la session (fichiers en local, base de données si USE_DB_SESSIONS=1)
if (session_status() === PHP_SESSION_NONE) {
    if (USE_DB_SESSIONS) {
        require_once __DIR__ . '/DbSessionHandler.php';
        session_set_save_handler(new DbSessionHandler(getDB()), true);
    }
    session_start();
}

// Fonctions utilitaires
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Génère un lien absolu vers une page du projet, quel que soit le sous-dossier
function baseUrl($path = '') {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $root   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $root . '/' . ltrim($path, '/');
}
