<?php
/* ============================================================
 * SolarKit — Back/visualdata.php
 * Explorateur de base de données, réservé au rôle « admin ».
 * Toutes les tables (y compris _users) : lecture, ajout,
 * édition et suppression ligne par ligne. Rendu PHP + Tailwind CDN.
 * ============================================================ */

declare(strict_types=1);

require __DIR__ . '/core.php';

sk_session();

/* ------------------------------------------------------------
 * Porte d'entrée : admin uniquement
 * ---------------------------------------------------------- */
if (!sk_is_admin()) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="fr"><head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Accès refusé · SolarKit</title>
      <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    </head>
    <body class="grid min-h-screen place-items-center bg-slate-100 p-6 text-slate-900">
      <div class="w-full max-w-md rounded-xl border border-rose-200 bg-white p-6 shadow-sm">
        <p class="text-lg font-bold text-rose-700">Accès refusé</p>
        <p class="mt-2 text-sm text-slate-600">
          L'explorateur de base de données est réservé aux comptes dont le rôle est
          <code class="rounded bg-slate-100 px-1 font-bold">admin</code>.
          <?= sk_user() ? 'Votre rôle actuel est « ' . htmlspecialchars(sk_role(), ENT_QUOTES) . ' ».' : 'Vous n\'êtes pas connecté.' ?>
        </p>
        <a href="../index.html" class="mt-4 inline-flex rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
          Retour à SolarKit
        </a>
      </div>
    </body></html>
    <?php
    exit;
}

/* ------------------------------------------------------------
 * Helpers de vue
 * ---------------------------------------------------------- */
function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function sk_url(array $params = []): string
{
    $base = ['table' => $_GET['table'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1];
    $all  = array_filter(array_merge($base, $params), fn($v) => $v !== '' && $v !== null);
    return 'visualdata.php' . ($all ? '?' . http_build_query($all) : '');
}

const SK_PER_PAGE = 25;

$schema  = sk_schema();
$tables  = $schema['tables'];
$db      = sk_db();
$current = (string)($_GET['table'] ?? '');
$notices = [];
$errors  = [];

if ($current !== '' && !isset($tables[$current])) {
    $errors[] = "Table inconnue : $current";
    $current  = '';
}

/* ------------------------------------------------------------
 * Écritures (POST, protégées par le jeton de session)
 * ---------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op    = (string)($_POST['op'] ?? '');
    $table = (string)($_POST['table'] ?? '');
    $id    = (int)($_POST['id'] ?? 0);

    if (!hash_equals(sk_token(), (string)($_POST['token'] ?? ''))) {
        $errors[] = 'Jeton de session invalide. Rechargez la page et réessayez.';
    } elseif (!isset($tables[$table])) {
        $errors[] = "Table inconnue : $table";
    } else {
        try {
            switch ($op) {
                case 'create':
                    $row = sk_create($table, (array)($_POST['data'] ?? []));
                    $notices[] = "Ligne #{$row['id']} ajoutée dans « $table ».";
                    break;
                case 'update':
                    sk_update($table, $id, (array)($_POST['data'] ?? []));
                    $notices[] = "Ligne #$id modifiée dans « $table ».";
                    break;
                case 'delete':
                    sk_delete($table, $id);
                    $notices[] = "Ligne #$id supprimée de « $table ».";
                    break;
                default:
                    $errors[] = "Opération inconnue : $op";
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $current = isset($tables[$table]) ? $table : $current;
}

/* ------------------------------------------------------------
 * Lecture de la table courante
 * ---------------------------------------------------------- */
$rows    = [];
$total   = 0;
$page    = max(1, (int)($_GET['page'] ?? 1));
$search  = trim((string)($_GET['q'] ?? ''));
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
$meta    = $current !== '' ? $tables[$current] : null;

if ($meta) {
    $total = sk_count($current, ['search' => $search]);
    $rows  = sk_list($current, [
        'search' => $search,
        'order'  => 'id desc',
        'limit'  => SK_PER_PAGE,
        'offset' => ($page - 1) * SK_PER_PAGE,
    ]);
    if ($editId) {
        $editRow = sk_get($current, $editId);
        if (!$editRow) {
            $errors[] = "Ligne #$editId introuvable.";
        }
    }
}

$pages = $meta ? max(1, (int)ceil($total / SK_PER_PAGE)) : 1;

/** Champ de formulaire pour un field du schéma. */
function sk_input_html(array $field, mixed $value, string $prefix = 'data'): string
{
    $name  = $prefix . '[' . $field['name'] . ']';
    $class = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100';
    $req   = $field['required'] ? ' required' : '';

    if ($field['type'] === 'textarea') {
        return '<textarea name="' . h($name) . '" rows="2" class="' . $class . '"' . $req . '>' . h($value) . '</textarea>';
    }
    if ($field['type'] === 'password') {
        return '<input type="password" name="' . h($name) . '" placeholder="(inchangé)" class="' . $class . '" autocomplete="new-password">';
    }
    if ($field['type'] === 'select') {
        $html = '<select name="' . h($name) . '" class="' . $class . '"' . $req . '><option value="">—</option>';
        foreach ($field['options'] as $opt) {
            $sel = (string)$opt === (string)$value ? ' selected' : '';
            $html .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
        }
        return $html . '</select>';
    }
    if ($field['type'] === 'bool') {
        return '<select name="' . h($name) . '" class="' . $class . '">' .
            '<option value="0"' . (!(int)$value ? ' selected' : '') . '>Non</option>' .
            '<option value="1"' . ((int)$value ? ' selected' : '') . '>Oui</option></select>';
    }

    $types = [
        'int' => 'number', 'integer' => 'number', 'ref' => 'number',
        'float' => 'number', 'real' => 'number', 'number' => 'number', 'money' => 'number',
        'date' => 'date', 'datetime' => 'datetime-local', 'time' => 'time',
        'email' => 'email', 'url' => 'url',
    ];
    $step = in_array($field['type'], ['float', 'real', 'number', 'money'], true) ? ' step="0.01"' : '';
    return '<input type="' . ($types[$field['type']] ?? 'text') . '"' . $step .
        ' name="' . h($name) . '" value="' . h($value) . '" class="' . $class . '"' . $req . '>';
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Explorateur · <?= h($schema['app']['name'] ?? 'SolarKit') ?></title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body class="min-h-full bg-slate-100 text-slate-900 antialiased">

<header class="border-b border-slate-200 bg-white">
  <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-3">
    <a href="../index.html" class="flex items-center gap-2">
      <span class="grid h-9 w-9 place-items-center rounded-lg bg-gradient-to-br from-amber-400 to-amber-600 text-lg font-black text-white">S</span>
      <span>
        <span class="block text-sm font-bold leading-tight">Explorateur de base</span>
        <span class="block text-[11px] leading-tight text-slate-400"><?= h(basename(SK_DB_FILE)) ?> · SQLite</span>
      </span>
    </a>
    <div class="ml-auto flex items-center gap-3 text-xs text-slate-500">
      <span><?= h(sk_user()['username']) ?> · <span class="font-semibold text-amber-600">admin</span></span>
      <a href="../index.html" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">
        Retour à l'application
      </a>
    </div>
  </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6">

  <?php foreach ($notices as $note): ?>
    <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><?= h($note) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $err): ?>
    <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800"><?= h($err) ?></div>
  <?php endforeach; ?>

  <!-- Liste des tables -->
  <nav class="mb-6 flex flex-wrap gap-2">
    <?php foreach ($tables as $name => $t):
        $count = (int)$db->query(sprintf('SELECT COUNT(*) c FROM "%s"', $name))->fetch()['c'];
        $on    = $name === $current; ?>
      <a href="<?= h('visualdata.php?table=' . urlencode($name)) ?>"
         class="rounded-lg border px-3 py-1.5 text-sm font-semibold transition <?= $on
            ? 'border-amber-500 bg-amber-500 text-white shadow'
            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' ?>">
        <?= h($t['label']) ?>
        <span class="ml-1 rounded-full <?= $on ? 'bg-white/25' : 'bg-slate-100' ?> px-1.5 py-0.5 text-[11px]"><?= $count ?></span>
        <?php if ($t['system']): ?><span class="ml-1 text-[10px] uppercase opacity-70">système</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$meta): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
      <p class="text-lg font-bold">Choisissez une table</p>
      <p class="mt-1 text-sm text-slate-500">
        <?= count($tables) ?> table(s) déclarée(s) dans <code class="rounded bg-slate-100 px-1">Back/schema.json</code>,
        matérialisée(s) dans <code class="rounded bg-slate-100 px-1">Back/<?= h(basename(SK_DB_FILE)) ?></code>.
      </p>
    </div>
  <?php else: ?>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-bold"><?= h($meta['label']) ?>
        <code class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-xs font-normal text-slate-600"><?= h($current) ?></code>
      </h1>
      <span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700"><?= $total ?> ligne(s)</span>
      <form method="get" class="ml-auto flex items-center gap-2">
        <input type="hidden" name="table" value="<?= h($current) ?>">
        <input name="q" value="<?= h($search) ?>" type="search" placeholder="Rechercher…"
               class="w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100">
        <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">Filtrer</button>
        <?php if ($search !== ''): ?>
          <a href="<?= h('visualdata.php?table=' . urlencode($current)) ?>" class="text-sm text-slate-500 underline">Réinitialiser</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Formulaire : ajout ou édition -->
    <section class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <p class="mb-4 text-sm font-bold">
        <?= $editRow ? 'Modifier la ligne #' . (int)$editRow['id'] : 'Ajouter une ligne' ?>
      </p>
      <form method="post" class="space-y-4">
        <input type="hidden" name="token" value="<?= h(sk_token()) ?>">
        <input type="hidden" name="table" value="<?= h($current) ?>">
        <input type="hidden" name="op" value="<?= $editRow ? 'update' : 'create' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($meta['fields'] as $field): ?>
            <label class="space-y-1">
              <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                <?= h($field['label']) ?>
                <?php if ($field['required']): ?><span class="text-rose-500">*</span><?php endif; ?>
                <code class="ml-1 font-normal normal-case text-slate-400"><?= h($field['type']) ?></code>
              </span>
              <?= sk_input_html($field, $editRow[$field['name']] ?? $field['default']) ?>
              <?php if ($field['hint']): ?><span class="block text-xs text-slate-400"><?= h($field['hint']) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="flex items-center gap-2">
          <button class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-600">
            <?= $editRow ? 'Enregistrer les modifications' : 'Ajouter la ligne' ?>
          </button>
          <?php if ($editRow): ?>
            <a href="<?= h(sk_url(['edit' => null])) ?>" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Annuler</a>
          <?php endif; ?>
          <?php if ($current === SK_USERS): ?>
            <span class="text-xs text-slate-400">Le mot de passe est haché à l'enregistrement ; laissez vide pour le conserver.</span>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- Tableau des lignes -->
    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
      <?php if (!$rows): ?>
        <p class="px-4 py-12 text-center text-sm text-slate-400">
          <?= $search !== '' ? 'Aucune ligne ne correspond à la recherche.' : 'Table vide.' ?>
        </p>
      <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">id</th>
              <?php foreach ($meta['fields'] as $field): ?>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><?= h($field['name']) ?></th>
              <?php endforeach; ?>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">created_at</th>
              <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">updated_at</th>
              <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr class="border-b border-slate-100 last:border-0 <?= $editId === (int)$row['id'] ? 'bg-amber-50' : 'hover:bg-slate-50/70' ?>">
                <td class="px-3 py-2 font-mono text-xs text-slate-400"><?= (int)$row['id'] ?></td>
                <?php foreach ($meta['fields'] as $field):
                    $value = $row[$field['name']] ?? null;
                    if ($field['type'] === 'bool') {
                        $cell = (int)$value
                            ? '<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Oui</span>'
                            : '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Non</span>';
                    } elseif ($value === null || $value === '') {
                        $cell = '<span class="text-slate-300">null</span>';
                    } else {
                        $text = (string)$value;
                        $cut  = mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '…' : $text;
                        $cell = '<span title="' . h($text) . '">' . h($cut) . '</span>';
                    } ?>
                  <td class="px-3 py-2 align-top"><?= $cell ?></td>
                <?php endforeach; ?>
                <td class="px-3 py-2 font-mono text-[11px] text-slate-400"><?= h($row['created_at'] ?? '') ?></td>
                <td class="px-3 py-2 font-mono text-[11px] text-slate-400"><?= h($row['updated_at'] ?? '') ?></td>
                <td class="whitespace-nowrap px-3 py-2 text-right">
                  <a href="<?= h(sk_url(['edit' => (int)$row['id']])) ?>"
                     class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Éditer</a>
                  <form method="post" class="inline"
                        onsubmit="return confirm('Supprimer définitivement la ligne #<?= (int)$row['id'] ?> ?');">
                    <input type="hidden" name="token" value="<?= h(sk_token()) ?>">
                    <input type="hidden" name="table" value="<?= h($current) ?>">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">Suppr.</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <?php if ($pages > 1): ?>
      <nav class="mt-4 flex flex-wrap items-center gap-1">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a href="<?= h(sk_url(['page' => $p, 'edit' => null])) ?>"
             class="rounded-lg border px-3 py-1.5 text-sm font-semibold <?= $p === $page
                ? 'border-amber-500 bg-amber-500 text-white'
                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <span class="ml-2 text-xs text-slate-400"><?= SK_PER_PAGE ?> lignes par page</span>
      </nav>
    <?php endif; ?>

  <?php endif; ?>

  <p class="mt-8 text-center text-xs text-slate-400">
    SolarKit · explorateur de <code><?= h(basename(SK_DB_FILE)) ?></code> — les tables sont pilotées par
    <code>Back/schema.json</code>.
  </p>
</main>
</body>
</html>
