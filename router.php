<?php
/* ============================================================
 * SolarKit — router.php
 * Utilisé par le serveur PHP intégré (init.sh) :
 *     php -S localhost:8000 router.php
 *
 * Rôle : empêcher le téléchargement direct des fichiers internes.
 * Sans lui, http://localhost:8000/Back/data.db livrerait toute la
 * base de données. Les fichiers PHP et les ressources publiques
 * (index.html, core.js, Front/*.html) sont servis normalement.
 *
 * En production derrière Apache, c'est Back/.htaccess qui joue ce rôle ;
 * derrière Nginx, voir l'extrait fourni dans le README.
 * ============================================================ */

declare(strict_types=1);

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode($path);

/* Fichiers jamais servis en direct, où qu'ils se trouvent. */
$denied = [
    '/\.(db|db-wal|db-shm|sqlite|sqlite3)$/i',   // base SQLite et ses journaux
    '/\.bak(-[0-9-]+)?$/i',                      // sauvegardes créées par init.sh
    '/(^|\/)\.(git|env|htaccess|htpasswd)/i',    // fichiers cachés / de config
    '/(^|\/)Back\/.*\.(json|log)$/i',            // schema.json, seed.json, logs
    '/(^|\/)(init\.sh|router\.php)$/i',          // scripts d'installation
];

foreach ($denied as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 — Fichier interne non servi par SolarKit.\n";
        return;
    }
}

/* Tout le reste : comportement normal du serveur intégré
 * (index.html à la racine, PHP exécuté, fichiers statiques servis tels quels). */
return false;
