<?php
/* ============================================================
 * SolarKit — Back/api.php
 * Point d'entrée unique du SDK JS ($db, $auth).
 * Appel : POST /Back/api.php  {"action":"db.list","table":"notes"}
 * Réponse : {"ok":true,"data":...}  |  {"ok":false,"error":"..."}
 * ============================================================ */

declare(strict_types=1);

require __DIR__ . '/core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Les actions d'écriture exigent le jeton de session (anti-CSRF). */
const SK_WRITE_ACTIONS = ['db.create', 'db.update', 'db.delete'];

function sk_send(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sk_fail(string $message, int $status = 400): never
{
    sk_send(['ok' => false, 'error' => $message], $status);
}

function sk_input(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sk_fail('Méthode non autorisée : utilisez POST.', 405);
    }
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        sk_fail('Corps de requête JSON invalide.', 400);
    }
    return $data;
}

sk_session();
$in     = sk_input();
$action = (string)($in['action'] ?? '');
$table  = (string)($in['table'] ?? '');
$data   = is_array($in['data'] ?? null) ? $in['data'] : [];
$id     = (int)($in['id'] ?? 0);

if (in_array($action, SK_WRITE_ACTIONS, true)) {
    $sent = (string)($_SERVER['HTTP_X_SOLARKIT_TOKEN'] ?? ($in['token'] ?? ''));
    if (!hash_equals(sk_token(), $sent)) {
        sk_fail('Jeton de session invalide. Rechargez la page.', 419);
    }
}

try {
    switch ($action) {

        /* ---- Authentification --------------------------------- */
        case 'auth.login':
            $user = sk_login((string)($in['username'] ?? ''), (string)($in['password'] ?? ''));
            sk_send(['ok' => true, 'data' => ['user' => $user, 'token' => sk_token()]]);

        case 'auth.logout':
            sk_logout();
            sk_send(['ok' => true, 'data' => ['user' => null, 'token' => sk_token()]]);

        case 'auth.me':
            sk_send(['ok' => true, 'data' => ['user' => sk_user(), 'token' => sk_token()]]);

        case 'auth.password':
            $me = sk_user();
            if (!$me) {
                sk_fail('Connexion requise.', 401);
            }
            $current = (string)($in['current'] ?? '');
            $next    = (string)($in['password'] ?? '');
            if (strlen($next) < 3) {
                sk_fail('Le nouveau mot de passe doit faire au moins 3 caractères.', 422);
            }
            $row = sk_raw_get(SK_USERS, (int)$me['id']);
            if (!$row || !password_verify($current, (string)$row['password'])) {
                sk_fail('Mot de passe actuel incorrect.', 401);
            }
            $st = sk_db()->prepare(sprintf('UPDATE "%s" SET password = ?, updated_at = ? WHERE id = ?', SK_USERS));
            $st->execute([password_hash($next, PASSWORD_DEFAULT), sk_now(), (int)$me['id']]);
            sk_send(['ok' => true, 'data' => true]);

        /* ---- Métadonnées -------------------------------------- */
        case 'meta.schema':
            sk_db(); // garantit la création de data.db au premier appel
            sk_send(['ok' => true, 'data' => [
                'schema' => sk_public_schema(),
                'user'   => sk_user(),
                'token'  => sk_token(),
            ]]);

        /* ---- CRUD --------------------------------------------- */
        case 'db.list':
            sk_send(['ok' => true, 'data' => sk_list($table, $in)]);

        case 'db.count':
            sk_send(['ok' => true, 'data' => sk_count($table, $in)]);

        case 'db.get':
            $row = sk_get($table, $id);
            if (!$row) {
                sk_fail('Enregistrement introuvable.', 404);
            }
            sk_send(['ok' => true, 'data' => $row]);

        case 'db.create':
            sk_send(['ok' => true, 'data' => sk_create($table, $data)]);

        case 'db.update':
            sk_send(['ok' => true, 'data' => sk_update($table, $id, $data)]);

        case 'db.delete':
            sk_send(['ok' => true, 'data' => sk_delete($table, $id)]);

        default:
            sk_fail($action === '' ? 'Action manquante.' : "Action inconnue : $action", 400);
    }
} catch (RuntimeException $e) {
    $code = $e->getCode();
    sk_fail($e->getMessage(), $code >= 400 && $code < 600 ? (int)$code : 400);
} catch (Throwable $e) {
    error_log('[SolarKit] ' . $e->getMessage());
    sk_fail('Erreur serveur : ' . $e->getMessage(), 500);
}
