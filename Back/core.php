<?php
/* ============================================================
 * SolarKit — Back/core.php
 * Moteur commun : schema.json -> SQLite, sessions, ACL, CRUD.
 * Utilisé par api.php (SDK JS) et visualdata.php (explorateur).
 * Aucune dépendance externe. PHP 8+ avec pdo_sqlite.
 * ============================================================ */

declare(strict_types=1);

const SK_DIR        = __DIR__;
const SK_DB_FILE    = SK_DIR . '/data.db';
const SK_SCHEMA     = SK_DIR . '/schema.json';
const SK_SEED       = SK_DIR . '/seed.json';
const SK_USERS      = '_users';
const SK_RESERVED   = ['id', 'created_at', 'updated_at'];

/* ------------------------------------------------------------
 * Session
 * ---------------------------------------------------------- */
function sk_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('solarkit');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
    if (empty($_SESSION['sk_token'])) {
        $_SESSION['sk_token'] = bin2hex(random_bytes(16));
    }
}

function sk_token(): string
{
    sk_session();
    return $_SESSION['sk_token'];
}

function sk_user(): ?array
{
    sk_session();
    return $_SESSION['sk_user'] ?? null;
}

function sk_role(): string
{
    $u = sk_user();
    return $u['role'] ?? 'guest';
}

function sk_is_admin(): bool
{
    return sk_role() === 'admin';
}

/* ------------------------------------------------------------
 * Schéma
 * ---------------------------------------------------------- */
function sk_schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }
    if (!is_file(SK_SCHEMA)) {
        throw new RuntimeException('Back/schema.json introuvable. Lancez ./init.sh');
    }
    $raw = json_decode((string)file_get_contents(SK_SCHEMA), true);
    if (!is_array($raw)) {
        throw new RuntimeException('Back/schema.json invalide : ' . json_last_error_msg());
    }

    $schema = [
        'app'    => $raw['app'] ?? ['name' => 'SolarKit'],
        'tables' => [],
    ];

    foreach (($raw['tables'] ?? []) as $name => $def) {
        $schema['tables'][$name] = sk_normalize_table((string)$name, is_array($def) ? $def : []);
    }

    // La table des comptes est toujours présente et réservée aux admins.
    $schema['tables'][SK_USERS] = sk_normalize_table(SK_USERS, [
        'label'  => 'Comptes',
        'icon'   => 'U',
        'system' => true,
        'access' => ['read' => 'admin', 'write' => 'admin'],
        'fields' => [
            'username' => ['type' => 'text', 'label' => 'Identifiant', 'required' => true],
            'password' => ['type' => 'password', 'label' => 'Mot de passe', 'required' => true],
            'role'     => ['type' => 'select', 'label' => 'Rôle', 'options' => ['admin', 'user'], 'default' => 'user'],
        ],
    ]);

    return $schema;
}

function sk_normalize_table(string $name, array $def): array
{
    $fields = [];
    foreach (($def['fields'] ?? []) as $fname => $fdef) {
        if (in_array($fname, SK_RESERVED, true)) {
            continue; // colonnes gérées par le moteur
        }
        if (is_string($fdef)) {
            $fdef = ['type' => $fdef];
        }
        $type = strtolower((string)($fdef['type'] ?? 'text'));
        $fields[$fname] = [
            'name'     => $fname,
            'type'     => $type,
            'label'    => (string)($fdef['label'] ?? ucfirst(str_replace('_', ' ', $fname))),
            'required' => (bool)($fdef['required'] ?? false),
            'default'  => $fdef['default'] ?? null,
            'options'  => array_values($fdef['options'] ?? []),
            'ref'      => $fdef['ref'] ?? null,
            'hint'     => (string)($fdef['hint'] ?? ''),
        ];
    }

    $access = $def['access'] ?? [];

    return [
        'name'   => $name,
        'label'  => (string)($def['label'] ?? ucfirst($name)),
        'icon'   => (string)($def['icon'] ?? ''),
        'system' => (bool)($def['system'] ?? false),
        'order'  => (string)($def['order'] ?? 'id desc'),
        'access' => [
            'read'  => (string)($access['read'] ?? 'public'),
            'write' => (string)($access['write'] ?? 'user'),
        ],
        'fields' => $fields,
    ];
}

function sk_table(string $name): array
{
    $tables = sk_schema()['tables'];
    if (!isset($tables[$name])) {
        throw new RuntimeException("Table inconnue : $name", 404);
    }
    return $tables[$name];
}

function sk_sql_type(string $type): string
{
    return match ($type) {
        'int', 'integer', 'bool', 'boolean', 'ref'    => 'INTEGER',
        'float', 'real', 'number', 'money', 'decimal' => 'REAL',
        default                                       => 'TEXT',
    };
}

/* ------------------------------------------------------------
 * Base de données : création + auto-migration
 * ---------------------------------------------------------- */
function sk_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $fresh = !is_file(SK_DB_FILE);
    $pdo = new PDO('sqlite:' . SK_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    sk_migrate($pdo);
    sk_bootstrap($pdo, $fresh);

    return $pdo;
}

function sk_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS _meta (key TEXT PRIMARY KEY, value TEXT)');

    foreach (sk_schema()['tables'] as $name => $table) {
        $cols = [
            'id INTEGER PRIMARY KEY AUTOINCREMENT',
            'created_at TEXT',
            'updated_at TEXT',
        ];
        foreach ($table['fields'] as $f) {
            $cols[] = sprintf('"%s" %s', $f['name'], sk_sql_type($f['type']));
        }
        $pdo->exec(sprintf('CREATE TABLE IF NOT EXISTS "%s" (%s)', $name, implode(', ', $cols)));

        // Auto-migration : ajoute les colonnes apparues dans schema.json.
        $existing = [];
        foreach ($pdo->query(sprintf('PRAGMA table_info("%s")', $name)) as $row) {
            $existing[$row['name']] = true;
        }
        foreach ($table['fields'] as $f) {
            if (!isset($existing[$f['name']])) {
                $pdo->exec(sprintf(
                    'ALTER TABLE "%s" ADD COLUMN "%s" %s',
                    $name,
                    $f['name'],
                    sk_sql_type($f['type'])
                ));
            }
        }
    }

    $pdo->exec(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS idx_%s_username ON "%s" (username)', SK_USERS, SK_USERS));
}

function sk_meta(PDO $pdo, string $key, ?string $value = null): ?string
{
    if ($value === null) {
        $st = $pdo->prepare('SELECT value FROM _meta WHERE key = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : null;
    }
    $st = $pdo->prepare('INSERT INTO _meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
    return $value;
}

/** Compte admin par défaut + données de démonstration (une seule fois). */
function sk_bootstrap(PDO $pdo, bool $fresh): void
{
    $count = (int)$pdo->query(sprintf('SELECT COUNT(*) c FROM "%s"', SK_USERS))->fetch()['c'];
    if ($count === 0) {
        $st = $pdo->prepare(sprintf(
            'INSERT INTO "%s" (username, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            SK_USERS
        ));
        $now = sk_now();
        $st->execute(['admin', password_hash('admin', PASSWORD_DEFAULT), 'admin', $now, $now]);
    }

    if (sk_meta($pdo, 'seeded') === '1' || !is_file(SK_SEED)) {
        if (!is_file(SK_SEED)) {
            sk_meta($pdo, 'seeded', '1');
        }
        return;
    }

    $seed = json_decode((string)file_get_contents(SK_SEED), true);
    if (!is_array($seed)) {
        sk_meta($pdo, 'seeded', '1');
        return;
    }

    $tables = sk_schema()['tables'];
    $pdo->beginTransaction();
    try {
        foreach ($seed as $table => $rows) {
            if (!isset($tables[$table]) || !is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($table === SK_USERS && isset($row['password'])) {
                    $row['password'] = password_hash((string)$row['password'], PASSWORD_DEFAULT);
                    $exists = $pdo->prepare(sprintf('SELECT id FROM "%s" WHERE username = ?', SK_USERS));
                    $exists->execute([(string)($row['username'] ?? '')]);
                    if ($exists->fetch()) {
                        continue;
                    }
                    sk_insert_raw($pdo, $table, $row);
                    continue;
                }
                sk_insert_raw($pdo, $table, sk_cast_row($tables[$table], sk_seed_tokens($row), true));
            }
        }
        sk_meta($pdo, 'seeded', '1');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Jetons de date dans seed.json, pour que les démos restent d'actualité :
 *   "@today", "@today+3", "@today-2", "@monday", "@monday+4"
 */
function sk_seed_tokens(array $row): array
{
    foreach ($row as $key => $value) {
        if (!is_string($value) || $value === '' || $value[0] !== '@') {
            continue;
        }
        if (!preg_match('/^@(today|monday)([+-]\d+)?$/', $value, $m)) {
            continue;
        }
        $base   = $m[1] === 'monday' ? strtotime('monday this week') : strtotime('today');
        $offset = (int)($m[2] ?? 0);
        $row[$key] = date('Y-m-d', $base + $offset * 86400);
    }
    return $row;
}

function sk_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function sk_insert_raw(PDO $pdo, string $table, array $row): int
{
    $row['created_at'] = $row['created_at'] ?? sk_now();
    $row['updated_at'] = $row['updated_at'] ?? sk_now();
    $cols = array_keys($row);
    $sql  = sprintf(
        'INSERT INTO "%s" (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => '"' . $c . '"', $cols)),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    $pdo->prepare($sql)->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}

/* ------------------------------------------------------------
 * Validation / transtypage
 * ---------------------------------------------------------- */
function sk_cast_value(array $field, mixed $value): mixed
{
    if ($value === null || $value === '') {
        return $field['type'] === 'bool' && $value === '' ? 0 : null;
    }
    return match ($field['type']) {
        'int', 'integer', 'ref'                       => (int)$value,
        'bool', 'boolean'                             => sk_cast_bool($value),
        'float', 'real', 'number', 'money', 'decimal' => (float)str_replace(',', '.', (string)$value),
        default                                       => is_scalar($value) ? trim((string)$value) : json_encode($value, JSON_UNESCAPED_UNICODE),
    };
}

function sk_cast_bool(mixed $value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_numeric($value)) {
        return (float)$value != 0.0 ? 1 : 0;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === true ? 1 : 0;
}

/** Garde uniquement les champs connus, transtype, valide le requis. */
function sk_cast_row(array $table, array $data, bool $creating): array
{
    $out = [];
    foreach ($table['fields'] as $name => $field) {
        $present = array_key_exists($name, $data);
        if (!$present) {
            if ($creating && $field['default'] !== null) {
                $out[$name] = sk_cast_value($field, $field['default']);
            } elseif ($creating && $field['required']) {
                throw new RuntimeException("Champ requis manquant : {$field['label']}", 422);
            }
            continue;
        }
        $value = sk_cast_value($field, $data[$name]);
        if ($field['required'] && ($value === null || $value === '')) {
            throw new RuntimeException("Champ requis : {$field['label']}", 422);
        }
        if ($field['type'] === 'password') {
            if ($value === null) {
                continue; // mot de passe laissé vide = inchangé
            }
            $value = password_hash((string)$value, PASSWORD_DEFAULT);
        }
        if ($field['type'] === 'select' && $field['options'] && $value !== null && !in_array((string)$value, array_map('strval', $field['options']), true)) {
            throw new RuntimeException("Valeur invalide pour {$field['label']}", 422);
        }
        $out[$name] = $value;
    }
    if ($creating && $table['name'] === SK_USERS && empty($out['password'])) {
        throw new RuntimeException('Mot de passe requis', 422);
    }
    return $out;
}

function sk_hide_secrets(array $table, array $row): array
{
    foreach ($table['fields'] as $name => $field) {
        if ($field['type'] === 'password' && array_key_exists($name, $row)) {
            $row[$name] = $row[$name] === null || $row[$name] === '' ? '' : '********';
        }
    }
    return $row;
}

/* ------------------------------------------------------------
 * Contrôle d'accès
 * ---------------------------------------------------------- */
function sk_can(array $table, string $mode): bool
{
    $need = $table['access'][$mode] ?? ($mode === 'read' ? 'public' : 'user');
    $role = sk_role();
    return match ($need) {
        'public' => true,
        'user'   => in_array($role, ['user', 'admin'], true),
        'admin'  => $role === 'admin',
        'none'   => false,
        default  => $role === 'admin',
    };
}

function sk_guard(array $table, string $mode): void
{
    if (!sk_can($table, $mode)) {
        throw new RuntimeException(
            sk_user() ? 'Accès refusé pour votre rôle.' : 'Connexion requise.',
            sk_user() ? 403 : 401
        );
    }
}

/* ------------------------------------------------------------
 * CRUD
 * ---------------------------------------------------------- */
function sk_list(string $tableName, array $opts = []): array
{
    $table = sk_table($tableName);
    sk_guard($table, 'read');
    $db = sk_db();

    $sql = sprintf('SELECT * FROM "%s"', $tableName);
    [$whereSql, $params] = sk_build_where($table, $opts);
    $sql .= $whereSql;
    $sql .= ' ORDER BY ' . sk_build_order($table, (string)($opts['order'] ?? $table['order']));

    $limit  = isset($opts['limit']) ? max(1, min(1000, (int)$opts['limit'])) : 200;
    $offset = max(0, (int)($opts['offset'] ?? 0));
    $sql   .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    return array_map(fn($r) => sk_hide_secrets($table, $r), $rows);
}

function sk_count(string $tableName, array $opts = []): int
{
    $table = sk_table($tableName);
    sk_guard($table, 'read');
    [$whereSql, $params] = sk_build_where($table, $opts);
    $st = sk_db()->prepare(sprintf('SELECT COUNT(*) c FROM "%s"%s', $tableName, $whereSql));
    $st->execute($params);
    return (int)$st->fetch()['c'];
}

function sk_get(string $tableName, int $id): ?array
{
    $table = sk_table($tableName);
    sk_guard($table, 'read');
    $st = sk_db()->prepare(sprintf('SELECT * FROM "%s" WHERE id = ?', $tableName));
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? sk_hide_secrets($table, $row) : null;
}

function sk_create(string $tableName, array $data): array
{
    $table = sk_table($tableName);
    sk_guard($table, 'write');
    $row = sk_cast_row($table, $data, true);
    if (!$row) {
        throw new RuntimeException('Aucune donnée à enregistrer.', 422);
    }
    $id = sk_insert_raw(sk_db(), $tableName, $row);
    return sk_hide_secrets($table, sk_raw_get($tableName, $id));
}

function sk_update(string $tableName, int $id, array $data): array
{
    $table = sk_table($tableName);
    sk_guard($table, 'write');
    if (!sk_raw_get($tableName, $id)) {
        throw new RuntimeException('Enregistrement introuvable.', 404);
    }
    $row = sk_cast_row($table, $data, false);
    if (!$row) {
        throw new RuntimeException('Aucune donnée à modifier.', 422);
    }
    $row['updated_at'] = sk_now();
    $sets = implode(', ', array_map(fn($c) => sprintf('"%s" = ?', $c), array_keys($row)));
    $st = sk_db()->prepare(sprintf('UPDATE "%s" SET %s WHERE id = ?', $tableName, $sets));
    $st->execute([...array_values($row), $id]);
    return sk_hide_secrets($table, sk_raw_get($tableName, $id));
}

function sk_delete(string $tableName, int $id): bool
{
    $table = sk_table($tableName);
    sk_guard($table, 'write');
    if ($tableName === SK_USERS) {
        $me = sk_user();
        if ($me && (int)$me['id'] === $id) {
            throw new RuntimeException('Impossible de supprimer son propre compte.', 422);
        }
        $admins = (int)sk_db()->query(sprintf('SELECT COUNT(*) c FROM "%s" WHERE role = \'admin\'', SK_USERS))->fetch()['c'];
        $target = sk_raw_get($tableName, $id);
        if ($admins <= 1 && $target && $target['role'] === 'admin') {
            throw new RuntimeException('Au moins un administrateur doit subsister.', 422);
        }
    }
    $st = sk_db()->prepare(sprintf('DELETE FROM "%s" WHERE id = ?', $tableName));
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

function sk_raw_get(string $tableName, int $id): ?array
{
    $st = sk_db()->prepare(sprintf('SELECT * FROM "%s" WHERE id = ?', $tableName));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** WHERE construit uniquement à partir de champs déclarés (anti-injection). */
function sk_build_where(array $table, array $opts): array
{
    $clauses = [];
    $params  = [];
    $cols    = array_merge(array_keys($table['fields']), SK_RESERVED);

    foreach ((array)($opts['where'] ?? []) as $col => $value) {
        if (!in_array($col, $cols, true)) {
            continue;
        }
        if (is_array($value)) {
            $value = array_values(array_filter($value, 'is_scalar'));
            if (!$value) {
                continue;
            }
            $clauses[] = sprintf('"%s" IN (%s)', $col, implode(',', array_fill(0, count($value), '?')));
            $params    = [...$params, ...$value];
            continue;
        }
        if ($value === null) {
            $clauses[] = sprintf('"%s" IS NULL', $col);
            continue;
        }
        $clauses[] = sprintf('"%s" = ?', $col);
        $params[]  = $value;
    }

    $q = trim((string)($opts['search'] ?? ''));
    if ($q !== '') {
        $searchable = [];
        foreach ($table['fields'] as $name => $field) {
            if (in_array($field['type'], ['text', 'textarea', 'email', 'url', 'select', 'date', 'datetime'], true)) {
                $searchable[] = sprintf('"%s" LIKE ?', $name);
                $params[]     = '%' . $q . '%';
            }
        }
        if ($searchable) {
            $clauses[] = '(' . implode(' OR ', $searchable) . ')';
        }
    }

    return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $params];
}

function sk_build_order(array $table, string $order): string
{
    $parts = preg_split('/\s+/', trim($order)) ?: [];
    $col   = $parts[0] ?? 'id';
    $dir   = strtolower($parts[1] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $cols  = array_merge(array_keys($table['fields']), SK_RESERVED);
    if (!in_array($col, $cols, true)) {
        $col = 'id';
    }
    return sprintf('"%s" %s', $col, $dir);
}

/* ------------------------------------------------------------
 * Authentification
 * ---------------------------------------------------------- */
function sk_login(string $username, string $password): array
{
    sk_session();
    $st = sk_db()->prepare(sprintf('SELECT * FROM "%s" WHERE username = ?', SK_USERS));
    $st->execute([trim($username)]);
    $row = $st->fetch();

    if (!$row || !password_verify($password, (string)$row['password'])) {
        usleep(300000); // ralentit le bruteforce
        throw new RuntimeException('Identifiant ou mot de passe incorrect.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['sk_token'] = bin2hex(random_bytes(16));
    $_SESSION['sk_user']  = [
        'id'       => (int)$row['id'],
        'username' => (string)$row['username'],
        'role'     => (string)($row['role'] ?: 'user'),
    ];
    return $_SESSION['sk_user'];
}

function sk_logout(): void
{
    sk_session();
    unset($_SESSION['sk_user']);
    session_regenerate_id(true);
    $_SESSION['sk_token'] = bin2hex(random_bytes(16));
}

/** Vue publique du schéma, pour générer les écrans côté client. */
function sk_public_schema(): array
{
    $schema = sk_schema();
    $out    = ['app' => $schema['app'], 'tables' => []];
    foreach ($schema['tables'] as $name => $table) {
        if (!sk_can($table, 'read')) {
            continue;
        }
        $out['tables'][$name] = [
            'name'     => $name,
            'label'    => $table['label'],
            'icon'     => $table['icon'],
            'system'   => $table['system'],
            'order'    => $table['order'],
            'canWrite' => sk_can($table, 'write'),
            'fields'   => array_values($table['fields']),
        ];
    }
    return $out;
}
