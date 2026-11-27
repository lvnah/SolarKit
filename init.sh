#!/usr/bin/env bash
# ============================================================
#  SolarKit — init.sh
#  Choisit un modèle (blueprint), installe le schéma + les vues,
#  puis démarre le serveur PHP intégré.
#
#  Usage :
#    ./init.sh                 menu interactif
#    ./init.sh 2               modèle 2 directement
#    ./init.sh 2 --port 8080   modèle 2 sur le port 8080
#    ./init.sh --reset         réinitialise data.db (données de démo rejouées)
#    ./init.sh --no-serve      installe sans démarrer le serveur
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

BACK="$ROOT/Back"
FRONT="$ROOT/Front"
BLUEPRINTS="$ROOT/blueprints"
DB_FILE="$BACK/data.db"

PORT="8000"
HOST="localhost"
CHOICE=""
RESET="no"
SERVE="yes"

bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
info()  { printf '\033[36m•\033[0m %s\n' "$*"; }
ok()    { printf '\033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '\033[33m!\033[0m %s\n' "$*"; }
die()   { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ------------------------------------------------------------
# Arguments
# ------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    1|2|3)        CHOICE="$1" ;;
    --port)       PORT="${2:-8000}"; shift ;;
    --host)       HOST="${2:-localhost}"; shift ;;
    --reset)      RESET="yes" ;;
    --no-serve)   SERVE="no" ;;
    -h|--help)
      sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "Argument inconnu : $1 (voir ./init.sh --help)" ;;
  esac
  shift
done

# ------------------------------------------------------------
# Prérequis : PHP + pdo_sqlite, rien d'autre
# ------------------------------------------------------------
command -v php >/dev/null 2>&1 || die "PHP est introuvable. Installez PHP 8.0 ou supérieur."

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.0.0", ">=") ? 0 : 1);' \
  || die "PHP $PHP_VERSION détecté ; SolarKit demande PHP 8.0 ou supérieur."
php -m | grep -qi '^pdo_sqlite$' \
  || die "L'extension PHP pdo_sqlite est absente (paquet php-sqlite3 sur la plupart des systèmes)."

echo
bold "  ☀  SolarKit — 0 Node.js, 0 compilation, 0 dépendance"
printf '     PHP %s · pdo_sqlite OK\n\n' "$PHP_VERSION"

# ------------------------------------------------------------
# Choix du modèle
# ------------------------------------------------------------
label_for() {
  case "$1" in
    1) echo "Base CRUD — deux tables, tous les types de champs" ;;
    2) echo "Gestion de Stock — produits, seuils d'alerte, mouvements" ;;
    3) echo "Planning d'équipe — créneaux, vue semaine, conflits" ;;
  esac
}

dir_for() {
  case "$1" in
    1) echo "$BLUEPRINTS/1-crud" ;;
    2) echo "$BLUEPRINTS/2-stock" ;;
    3) echo "$BLUEPRINTS/3-planning" ;;
  esac
}

if [ -z "$CHOICE" ]; then
  bold "  Choisissez un modèle de départ :"
  for n in 1 2 3; do
    printf '    \033[1m%s.\033[0m %s\n' "$n" "$(label_for "$n")"
  done
  if [ -f "$BACK/schema.json" ]; then
    printf '    \033[1m0.\033[0m Garder l'"'"'installation actuelle (ne rien écraser)\n'
  fi
  echo
  printf '  Votre choix [1-3] : '
  read -r CHOICE || CHOICE=""
  CHOICE="${CHOICE:-1}"
fi

if [ "$CHOICE" = "0" ]; then
  [ -f "$BACK/schema.json" ] || die "Aucune installation existante à conserver."
  info "Installation existante conservée."
else
  case "$CHOICE" in
    1|2|3) ;;
    *) die "Choix invalide : $CHOICE (attendu 1, 2 ou 3)" ;;
  esac

  SRC="$(dir_for "$CHOICE")"
  [ -d "$SRC" ] || die "Modèle introuvable : $SRC"

  bold "  → Modèle $CHOICE : $(label_for "$CHOICE")"

  # Une base existante contient peut-être vos données : on ne l'écrase jamais
  # sans accord explicite.
  if [ -f "$DB_FILE" ] && [ "$RESET" != "yes" ]; then
    warn "Back/data.db existe déjà : il sera conservé (schéma migré, pas de nouvelles données de démo)."
    printf '  Réinitialiser la base et rejouer les données de démonstration ? [o/N] : '
    read -r answer || answer="n"
    case "$answer" in [oOyY]*) RESET="yes" ;; esac
  fi

  if [ "$RESET" = "yes" ] && [ -f "$DB_FILE" ]; then
    STAMP="$(date +%Y%m%d-%H%M%S)"
    mv -f "$DB_FILE" "$DB_FILE.bak-$STAMP"
    [ -f "$DB_FILE-wal" ] && mv -f "$DB_FILE-wal" "$DB_FILE-wal.bak-$STAMP" || true
    [ -f "$DB_FILE-shm" ] && mv -f "$DB_FILE-shm" "$DB_FILE-shm.bak-$STAMP" || true
    ok "Ancienne base sauvegardée sous Back/data.db.bak-$STAMP"
  fi

  # Backend : schéma + données de démonstration
  mkdir -p "$BACK"
  cp -f "$SRC/schema.json" "$BACK/schema.json"
  ok "Back/schema.json installé"
  if [ -f "$SRC/seed.json" ]; then
    cp -f "$SRC/seed.json" "$BACK/seed.json"
    ok "Back/seed.json installé (inséré au premier démarrage)"
  fi

  # Frontend : vues communes puis vues du modèle (qui les remplacent)
  mkdir -p "$FRONT/Index" "$FRONT/Login" "$FRONT/Admin"
  for page in Index Login Admin; do
    if [ -f "$BLUEPRINTS/_common/$page/view.html" ]; then
      cp -f "$BLUEPRINTS/_common/$page/view.html" "$FRONT/$page/view.html"
    fi
  done
  for page in Index Login Admin; do
    if [ -f "$SRC/Front/$page/view.html" ]; then
      cp -f "$SRC/Front/$page/view.html" "$FRONT/$page/view.html"
    fi
  done
  ok "Front/Index, Front/Login, Front/Admin installés"
fi

# ------------------------------------------------------------
# Création / migration de la base avant le premier appel HTTP
# ------------------------------------------------------------
php -r '
require "Back/core.php";
sk_db();
$tables = array_keys(sk_schema()["tables"]);
echo "   tables : " . implode(", ", $tables) . PHP_EOL;
' || die "Échec de la création de Back/data.db (voir le message ci-dessus)."
ok "Back/data.db prêt"

echo
bold "  Comptes"
printf '    admin / admin  (rôle admin — à changer depuis l'"'"'écran Administration)\n'
php -r '
require "Back/core.php";
$rows = sk_db()->query("SELECT username, role FROM \"_users\" WHERE username <> \x27admin\x27 ORDER BY username")->fetchAll();
foreach ($rows as $r) {
    printf("    %s / %s  (rôle %s — compte de démonstration)%s", $r["username"], $r["username"], $r["role"], PHP_EOL);
}
' 2>/dev/null || true

if [ "$SERVE" != "yes" ]; then
  echo
  info "Installation terminée. Démarrez le serveur avec :  php -S $HOST:$PORT"
  exit 0
fi

echo
bold "  Serveur"
printf '    Application   http://%s:%s/\n' "$HOST" "$PORT"
printf '    Explorateur   http://%s:%s/Back/visualdata.php\n' "$HOST" "$PORT"
printf '    API           http://%s:%s/Back/api.php\n\n' "$HOST" "$PORT"
info "Ctrl+C pour arrêter."
echo

exec php -S "$HOST:$PORT" -t "$ROOT" "$ROOT/router.php"
