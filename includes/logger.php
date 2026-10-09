<?php
// ============================================================
// LOGGER APPLICATIF - collecte des requêtes pour la détection DDoS
// Mémoire L3 RSI - Partie 2, Chapitre 1, section 1.5
// ============================================================
// Enregistre un événement BRUT par requête HTTP (IP, horodatage, taille,
// durée, code HTTP). L'agrégation en "flows" façon CIC-IDS2017
// (Flow Duration, Flow Bytes/s, etc. par IP/fenêtre glissante) est faite
// a posteriori par un script Python séparé, pas ici.
//
// Volontairement minimaliste : pendant une attaque DDoS, le logger
// lui-même ne doit pas devenir un goulot d'étranglement.

function logRequest() {
    // Ne pas logger deux fois sur le même appel (sécurité en cas de double include)
    static $deja_enregistre = false;
    if ($deja_enregistre) return;
    $deja_enregistre = true;

    $debut = microtime(true);

    // Taille de la requête entrante (corps + chaîne de requête)
    $tailleRequete = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($tailleRequete === 0 && !empty($_SERVER['QUERY_STRING'])) {
        $tailleRequete = strlen($_SERVER['QUERY_STRING']);
    }

    $ip       = getClientIp();
    $methode  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $endpoint = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // L'enregistrement final (avec durée et code HTTP) se fait à la toute
    // fin du traitement PHP, une fois la réponse prête -> register_shutdown_function
    register_shutdown_function(function () use ($debut, $ip, $methode, $endpoint, $tailleRequete, $userAgent) {
        $dureeMs      = (int) round((microtime(true) - $debut) * 1000);
        $codeHttp     = http_response_code() ?: 200;
        $tailleReponse = ob_get_length() ?: 0; // approximation : taille du buffer de sortie

        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "INSERT INTO request_logs
                    (ip_source, horodatage, methode, endpoint, taille_requete, taille_reponse, temps_reponse_ms, code_http, user_agent)
                 VALUES (:ip, :ts, :methode, :endpoint, :taille_req, :taille_rep, :duree, :code, :ua)"
            );
            $stmt->execute([
                ':ip'         => $ip,
                ':ts'         => date('Y-m-d H:i:s.v'),
                ':methode'    => $methode,
                ':endpoint'   => $endpoint,
                ':taille_req' => $tailleRequete,
                ':taille_rep' => $tailleReponse,
                ':duree'      => $dureeMs,
                ':code'       => $codeHttp,
                ':ua'         => $userAgent,
            ]);
        } catch (Throwable $e) {
            // Le logger ne doit JAMAIS faire planter l'application :
            // en cas d'erreur (ex. table absente, DB surchargée pendant un DDoS),
            // on ignore silencieusement plutôt que de bloquer la réponse à l'utilisateur.
            error_log('logRequest() : échec insertion log - ' . $e->getMessage());
        }
    });
}

// Récupère l'IP réelle du client, y compris derrière un proxy/reverse-proxy
// comme celui de Render (important : X-Forwarded-For n'est fiable que parce
// que Render est l'unique proxy en amont ; sur une autre infra, il faudrait
// vérifier la provenance de cet en-tête avant de lui faire confiance).
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
