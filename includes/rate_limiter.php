<?php
// ============================================================
// RATE LIMITING APPLICATIF - première ligne de défense contre
// les attaques DDoS de couche application (Mémoire L3 RSI,
// Partie 2 Ch2). La vérification se fait en mémoire partagée
// (APCu), AVANT toute connexion PDO : on évite ainsi de rejouer
// le problème observé au Scénario A (conflit PDO/PgBouncer sous
// forte concurrence), puisqu'une requête bloquée ici n'atteint
// jamais la base de données.
// ============================================================

define('RATE_LIMIT_MAX_REQUESTS', 30);   // nombre max de requêtes autorisées
define('RATE_LIMIT_WINDOW_SECONDS', 10); // par fenêtre glissante de X secondes

function getClientIp(): string {
    // Render se place en reverse proxy devant l'application : l'IP réelle
    // du client arrive dans X-Forwarded-For, pas dans REMOTE_ADDR (qui
    // correspondrait alors à l'IP interne du proxy, identique pour tous).
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit(): void {
    // Si APCu n'est pas chargé (configuration manquante), on laisse passer
    // plutôt que de rendre l'application indisponible pour tout le monde.
    if (!function_exists('apcu_fetch')) {
        return;
    }

    $ip   = getClientIp();
    $key  = 'rl_' . md5($ip);
    $now  = time();
    $data = apcu_fetch($key);

    // Pas d'entrée, ou fenêtre précédente expirée : on en ouvre une nouvelle.
    if ($data === false || ($now - $data['start']) >= RATE_LIMIT_WINDOW_SECONDS) {
        apcu_store($key, ['count' => 1, 'start' => $now], RATE_LIMIT_WINDOW_SECONDS);
        return;
    }

    $data['count']++;

    if ($data['count'] > RATE_LIMIT_MAX_REQUESTS) {
        $retryAfter = max(1, RATE_LIMIT_WINDOW_SECONDS - ($now - $data['start']));
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: text/plain; charset=utf-8');
        echo "429 Too Many Requests - Trop de requêtes depuis votre adresse IP. Veuillez réessayer dans quelques secondes.";
        exit;
    }

    // On réenregistre le compteur mis à jour, avec le TTL restant de la fenêtre.
    $ttlRestant = max(1, RATE_LIMIT_WINDOW_SECONDS - ($now - $data['start']));
    apcu_store($key, $data, $ttlRestant);
}
