# ☀ SolarKit

**Micro-framework full-stack ultra-léger : 0 Node.js, 0 compilation, 0 dépendance.**

HTML/JS vanilla + Tailwind CSS (CDN) côté client, PHP + SQLite auto-CRUD côté serveur.
Vous déclarez vos tables dans un fichier JSON, le reste suit : base de données, API REST,
SDK JavaScript, sessions, rôles et explorateur de base.

```bash
git clone <ce-dépôt> && cd SolarKit
./init.sh
```

Il vous reste à choisir un modèle (1, 2 ou 3) et à ouvrir <http://localhost:8000>.
Compte par défaut : **`admin` / `admin`**.

**Prérequis : PHP 8.0+ avec `pdo_sqlite`. C'est tout.** Pas de `npm install`, pas de
`composer install`, pas d'étape de build, pas de `node_modules`.

---

## Sommaire

1. [Architecture](#architecture)
2. [Le mode SPA](#1--le-mode-spa)
3. [Le backend : schema.json → SQLite](#2--le-backend--schemajson--sqlite)
4. [Le SDK JavaScript : `$db`, `$auth`, `$router`, `$ui`](#3--le-sdk-javascript)
5. [L'explorateur de base `visualdata.php`](#4--lexplorateur-de-base-visualdataphp)
6. [Les modèles prêts à l'emploi (`blueprints/`)](#5--les-modèles-prêts-à-lemploi)
7. [Ajouter une page](#ajouter-une-page)
8. [Ajouter une table](#ajouter-une-table)
9. [Référence `schema.json`](#référence-schemajson)
10. [Référence de l'API HTTP](#référence-de-lapi-http)
11. [Sécurité](#sécurité)
12. [Mise en production](#mise-en-production)

---

## Architecture

```
SolarKit/
├── index.html              Coque SPA unique : Tailwind (CDN) + core.js
├── core.js                 $router, $db, $auth, $ui — tout le framework client
├── router.php              Garde-fou du serveur intégré (bloque data.db, etc.)
├── init.sh                 Choix du modèle + installation + démarrage
│
├── Front/                  Fragments HTML injectés dans #app (jamais servis seuls)
│   ├── Index/view.html
│   ├── Login/view.html
│   └── Admin/view.html
│
├── Back/
│   ├── schema.json         ← LA source de vérité : vos tables et vos champs
│   ├── seed.json           Données de démonstration (insérées une seule fois)
│   ├── core.php            Moteur : schéma → SQLite, sessions, ACL, CRUD
│   ├── api.php             Point d'entrée unique du SDK JS
│   ├── visualdata.php      Explorateur de base (rôle admin uniquement)
│   ├── .htaccess           Protection des fichiers internes (Apache)
│   └── data.db             SQLite — créée automatiquement, jamais versionnée
│
└── blueprints/             Modèles complets prêts à l'emploi
    ├── _common/            Vues partagées (Login, Admin génériques)
    ├── 1-crud/             Base CRUD
    ├── 2-stock/            Gestion de Stock
    └── 3-planning/         Planning d'équipe
```

Le flux complet, sans build :

```
schema.json ──► Back/core.php ──► data.db (CREATE TABLE / ALTER TABLE automatiques)
                     │
                     ▼
                Back/api.php  ◄──── fetch POST JSON ──── core.js ($db / $auth)
                                                              │
                                                              ▼
                                            Front/*/view.html injecté dans #app
```

---

## 1 · Le mode SPA

Il n'y a **qu'un seul document HTML** : `index.html`. Il charge Tailwind, `core.js`,
et expose trois points d'ancrage :

| Élément | Rôle |
|---|---|
| `#app` | conteneur où les fragments sont injectés |
| `#nav` | barre de navigation, reconstruite à chaque changement de rôle ou de page |
| `#toasts` | pile de notifications utilisée par `$ui.toast()` |

**L'URL ne change jamais.** Elle reste `localhost:8000` quelle que soit la page : pas de
hash, pas d'History API, pas de réécriture serveur. La navigation se fait uniquement par :

```js
$router.go('index');   // charge Front/Index/view.html
$router.go('login');   // charge Front/Login/view.html
$router.go('admin');   // charge Front/Admin/view.html — réservé au rôle admin
```

…ou, en HTML déclaratif, avec un simple attribut :

```html
<button data-go="admin">Administration</button>
```

### Anatomie d'un fragment

Un fragment est du HTML brut, **sans `<html>`, `<head>` ni `<body>`**, suivi
facultativement d'un `<script>` :

```html
<div class="sk-card">
  <h1 class="text-xl font-bold">Ma page</h1>
  <p id="cible"></p>
</div>

<script>
  // $page.root  = le conteneur #app
  // $page.name  = 'index' | 'login' | 'admin' | …
  $page.root.querySelector('#cible').textContent = 'Bonjour ' + $auth.role;

  // Nettoyage éventuel quand on quitte la page (timers, listeners globaux…)
  $page.onLeave(() => console.log('au revoir'));
</script>
```

`innerHTML` n'exécute pas les `<script>` : `core.js` les extrait et les exécute lui-même,
dans une fonction isolée recevant `$page`. Les erreurs d'une vue sont attrapées et
affichées en toast au lieu de casser l'application.

---

## 2 · Le backend : schema.json → SQLite

`Back/schema.json` est la seule chose à écrire pour obtenir une base complète :

```json
{
  "app": { "name": "Mon application" },
  "tables": {
    "notes": {
      "label": "Notes",
      "order": "id desc",
      "access": { "read": "public", "write": "user" },
      "fields": {
        "title":    { "type": "text",     "label": "Titre", "required": true },
        "content":  { "type": "textarea", "label": "Contenu" },
        "category": { "type": "select",   "label": "Catégorie", "options": ["Idée", "Tâche"] },
        "done":     { "type": "bool",     "label": "Terminé", "default": 0 }
      }
    }
  }
}
```

À la première requête, `Back/core.php` :

1. crée `Back/data.db` ;
2. crée une table par entrée de `tables`, avec `id`, `created_at` et `updated_at` ajoutés
   automatiquement ;
3. crée la table système `_users` et le compte **`admin` / `admin`** (mot de passe haché
   avec `password_hash()`) ;
4. insère `Back/seed.json` **une seule fois** (un drapeau est posé dans la table `_meta`) ;
5. **auto-migre** : à chaque démarrage, les champs ajoutés dans `schema.json` sont créés
   par `ALTER TABLE ADD COLUMN`. Aucune perte de données, aucune commande à lancer.

> Ajoutez un champ dans `schema.json`, rechargez la page : la colonne existe, le
> formulaire la propose, l'explorateur l'affiche. C'est tout le propos de SolarKit.

Supprimer un champ de `schema.json` ne supprime **pas** la colonne (SQLite et prudence
obligent) : elle est simplement ignorée par l'API et les écrans.

---

## 3 · Le SDK JavaScript

`core.js` expose quatre objets globaux. Aucun import, aucun bundler.

### `$db` — CRUD complet

```js
await $db.list('notes');                              // 200 lignes max par défaut
await $db.list('notes', { search: 'solar' });          // LIKE sur les champs texte
await $db.list('notes', { where: { done: 0 }, order: 'priority asc', limit: 50 });
await $db.list('notes', { where: { category: ['Idée', 'Tâche'] } });  // IN (…)
await $db.count('notes', { where: { done: 1 } });

await $db.get('notes', 3);
await $db.create('notes', { title: 'Nouvelle note', done: false });
await $db.update('notes', 3, { done: true });          // mise à jour partielle
await $db.delete('notes', 3);

// Variante liée à une table
const notes = $db.table('notes');
await notes.list();
await notes.create({ title: 'x' });

// Métadonnées (le schéma est chargé au démarrage)
$db.schema();          // { app, tables }
$db.schema('notes');   // { label, order, canWrite, fields: [...] }
$db.fields('notes');   // [ { name, type, label, required, options, … }, … ]
```

`where` et `order` n'acceptent que des champs déclarés dans le schéma, et tout passe par
des requêtes préparées : une valeur inattendue est ignorée, pas interpolée.

### `$auth` — sessions PHP

```js
await $auth.login('admin', 'admin');
await $auth.logout();
await $auth.me();
await $auth.changePassword('ancien', 'nouveau');

$auth.user        // { id, username, role } ou null
$auth.role        // 'admin' | 'user' | 'guest'
$auth.isLogged()
$auth.isAdmin()
```

### `$router` — navigation sans URL

```js
$router.go('admin');                               // avec garde de rôle
$router.reload();                                  // rejoue la page courante
$router.add('stats', 'Front/Stats/view.html', { title: 'Stats', role: 'admin' });
$router.current                                    // { name, params }
```

### `$ui` — rendu, sans moteur de template

```js
$ui.toast('Enregistré.', 'ok');                    // ok | error | warn | info
$ui.esc(valeurUtilisateur);                        // échappement HTML
$ui.$('#id'); $ui.$$('.classe');
$ui.format(valeur, champ);                         // respecte le type déclaré
$ui.field(champ, valeur);                          // <label> + <input> depuis le schéma
$ui.read(formulaire, champs);                      // lit un <form> en respectant les types

// Et le gros morceau : un panneau CRUD complet en une ligne
const panneau = $ui.crud('#zone', 'notes', {
  title: 'Mes notes',
  columns: ['title', 'category', 'done'],          // colonnes affichées
  rowClass: (row) => Number(row.done) ? 'opacity-60' : '',
  onChange: (rows) => console.log(rows.length),
  search: true, create: true, edit: true, remove: true
});
await panneau.refresh();
```

`$ui.crud` génère le tableau, la recherche, le formulaire d'ajout/édition (typé depuis
le schéma), la suppression avec confirmation et les notifications. Il masque
automatiquement les boutons d'écriture si le rôle courant n'a pas le droit d'écrire.

### Et aussi

```js
SolarKit.on('auth', () => {});     // auth | schema | route
SolarKit.call('db.list', { table: 'notes' });   // appel brut à l'API
SolarKit.refreshSchema();
```

---

## 4 · L'explorateur de base `visualdata.php`

<http://localhost:8000/Back/visualdata.php> — **accessible uniquement connecté avec le
rôle `admin`** (sinon : HTTP 403, aucune donnée rendue).

* liste toutes les tables du schéma avec leur nombre de lignes, **`_users` incluse** ;
* affiche les lignes avec pagination (25/page) et recherche plein texte ;
* permet d'**ajouter**, **éditer** et **supprimer** n'importe quelle ligne de n'importe
  quelle table ;
* les champs sont typés d'après `schema.json` (date, nombre, liste déroulante, booléen…) ;
* les mots de passe ne sont jamais affichés : laisser le champ vide conserve l'existant,
  le remplir le re-hache avec `password_hash()`.

C'est une page PHP classique (formulaires POST, jeton de session anti-CSRF), volontairement
indépendante de la SPA : elle reste utilisable même si votre JavaScript est cassé.

---

## 5 · Les modèles prêts à l'emploi

`./init.sh` propose trois applications complètes, **déjà remplies de données de
démonstration** insérées au premier démarrage :

### 1. Base CRUD

Point de départ minimal et pédagogique. Deux tables (`notes`, `contacts`) qui couvrent
tous les types de champs, un accueil à onglets, l'écran d'administration générique.
Comptes : `admin`/`admin`, `demo`/`demo`.

### 2. Gestion de Stock

`products` + `movements`. Seuils d'alerte par produit, bandeau d'alertes
(stock bas / rupture), valorisation du stock, boutons `+1`/`-1` qui mettent à jour le
stock **et** écrivent une ligne dans le journal, écran de réapprovisionnement, top des
produits les plus sortis. 10 produits et 9 mouvements de démonstration, dont 3 alertes
actives. Comptes : `admin`/`admin`, `magasin`/`magasin`.

### 3. Planning d'équipe

`employees` + `shifts`. Vue semaine sur 7 colonnes avec code couleur par personne,
navigation semaine précédente/suivante, **détection des chevauchements**, barres de charge
hebdomadaire par rapport au temps de travail contractuel, affectation rapide d'un créneau.
Les dates de démonstration sont relatives à la semaine en cours (jetons `@monday`,
`@today+2` dans `seed.json`), le planning est donc toujours d'actualité.
Comptes : `admin`/`admin`, `planning`/`planning`.

### Options d'`init.sh`

```bash
./init.sh                 # menu interactif
./init.sh 2               # modèle 2 directement
./init.sh 3 --reset       # réinstalle et rejoue les données de démonstration
./init.sh 1 --port 8080   # autre port
./init.sh 2 --no-serve    # installe sans démarrer le serveur
./init.sh --help
```

`init.sh` ne détruit jamais une base existante sans confirmation : `--reset` la renomme en
`Back/data.db.bak-<horodatage>`.

### Créer son propre modèle

```
blueprints/4-le-mien/
├── schema.json              obligatoire
├── seed.json                facultatif
└── Front/
    ├── Index/view.html      facultatif (sinon la vue commune est utilisée)
    └── Admin/view.html      facultatif
```

Puis ajoutez une ligne dans les fonctions `label_for()` et `dir_for()` d'`init.sh`.
À l'installation, les vues de `blueprints/_common/` sont copiées d'abord, puis celles du
modèle écrasent celles qu'il fournit.

---

## Ajouter une page

1. créez `Front/Stats/view.html` (fragment HTML + `<script>` facultatif) ;
2. déclarez la route — soit en dur dans `ROUTES` au début de `core.js`, soit à chaud :

```js
$router.add('stats', 'Front/Stats/view.html', { title: 'Statistiques', role: 'admin' });
$router.go('stats');
```

`role` est facultatif : s'il est présent et que le rôle ne correspond pas, l'utilisateur
est redirigé vers `login` puis ramené vers la page demandée après connexion.

---

## Ajouter une table

Une seule étape — ajoutez-la dans `Back/schema.json` :

```json
"invoices": {
  "label": "Factures",
  "access": { "read": "user", "write": "admin" },
  "fields": {
    "number":   { "type": "text",  "label": "Numéro", "required": true },
    "client_id":{ "type": "ref",   "label": "Client", "ref": "contacts" },
    "amount":   { "type": "money", "label": "Montant" },
    "paid":     { "type": "bool",  "label": "Payée", "default": 0 }
  }
}
```

Rechargez la page : la table est créée, `$db.list('invoices')` répond, l'explorateur
l'affiche, et `$ui.crud('#zone', 'invoices')` vous donne un écran de gestion complet.

---

## Référence `schema.json`

### Types de champs

| Type | Colonne SQLite | Formulaire | Remarques |
|---|---|---|---|
| `text` | TEXT | `input[text]` | |
| `textarea` | TEXT | `textarea` | exclu des colonnes de tableau par défaut |
| `email`, `url` | TEXT | `input[email]` / `input[url]` | |
| `password` | TEXT | `input[password]` | haché en écriture, jamais renvoyé en lecture |
| `select` | TEXT | `select` | `options` obligatoire, valeur hors liste refusée |
| `int`, `integer` | INTEGER | `input[number]` | |
| `float`, `real`, `number` | REAL | `input[number]` | virgule acceptée |
| `money` | REAL | `input[number]` | affiché `0.00 €` |
| `bool` | INTEGER | case à cocher | stocké 0/1 |
| `date`, `datetime`, `time` | TEXT | `input[date]`, `[datetime-local]`, `[time]` | |
| `ref` | INTEGER | `input[number]` | `ref: "autre_table"` à titre documentaire |

Attributs d'un champ : `type`, `label`, `required`, `default`, `options`, `ref`, `hint`.
Les noms `id`, `created_at` et `updated_at` sont réservés au moteur.

### Droits d'accès par table

```json
"access": { "read": "public", "write": "admin" }
```

| Valeur | Qui ? |
|---|---|
| `public` | tout le monde, même non connecté |
| `user` | tout compte connecté (`user` ou `admin`) |
| `admin` | rôle `admin` uniquement |
| `none` | personne via l'API |

Par défaut : `read: "public"`, `write: "user"`. La table `_users` est toujours forcée en
`admin` / `admin`, quoi qu'il y ait dans le fichier.

### Jetons de date dans `seed.json`

`@today`, `@today+3`, `@today-2`, `@monday`, `@monday+4` sont remplacés par une date
réelle au moment de l'insertion : vos démos restent cohérentes dans le temps.

---

## Référence de l'API HTTP

Tout passe par `POST /Back/api.php` avec un corps JSON. Réponse :
`{"ok":true,"data":…}` ou `{"ok":false,"error":"…"}`.

| `action` | Paramètres | Jeton requis |
|---|---|---|
| `meta.schema` | — | non |
| `auth.login` | `username`, `password` | non |
| `auth.logout` | — | non |
| `auth.me` | — | non |
| `auth.password` | `current`, `password` | non |
| `db.list` | `table`, `where?`, `search?`, `order?`, `limit?`, `offset?` | non |
| `db.count` | `table`, `where?`, `search?` | non |
| `db.get` | `table`, `id` | non |
| `db.create` | `table`, `data` | **oui** |
| `db.update` | `table`, `id`, `data` | **oui** |
| `db.delete` | `table`, `id` | **oui** |

Les écritures exigent l'en-tête `X-SolarKit-Token` (jeton de session anti-CSRF renvoyé par
`meta.schema`, `auth.me` et `auth.login`). `$db` et `$auth` s'en occupent pour vous.

```bash
# Exemple en ligne de commande
curl -s -c jar -X POST localhost:8000/Back/api.php \
  -H 'Content-Type: application/json' \
  -d '{"action":"db.list","table":"notes","limit":3}'
```

Codes HTTP utilisés : `401` connexion requise, `403` rôle insuffisant, `404` introuvable,
`405` mauvaise méthode, `419` jeton invalide, `422` validation, `500` erreur serveur.

---

## Sécurité

Ce qui est en place :

* mots de passe hachés (`password_hash` / `password_verify`), jamais renvoyés par l'API ;
* sessions PHP avec cookie `HttpOnly` + `SameSite=Lax`, identifiant régénéré à la connexion ;
* jeton anti-CSRF sur toutes les écritures (API JSON et formulaires de `visualdata.php`) ;
* requêtes 100 % préparées ; `where`, `order` et les noms de colonnes sont filtrés contre
  le schéma, donc jamais interpolés depuis l'entrée utilisateur ;
* droits par table et par opération, vérifiés **côté serveur** à chaque appel — le client
  masque les boutons, le serveur refuse l'action ;
* `visualdata.php` renvoie 403 sans rien afficher hors rôle `admin` ;
* le dernier compte `admin` ne peut pas être supprimé, et personne ne peut supprimer son
  propre compte ;
* `router.php` (serveur intégré) et `Back/.htaccess` (Apache) empêchent le téléchargement
  direct de `data.db`, `schema.json`, `seed.json` et des sauvegardes.

À faire avant d'exposer le projet sur Internet :

1. **changer le mot de passe `admin`** (écran Administration → Mon mot de passe) et
   supprimer le compte de démonstration ;
2. servir le site en **HTTPS** et ajouter `'secure' => true` aux options de cookie dans
   `sk_session()` (`Back/core.php`) ;
3. vérifier que `Back/` n'est pas téléchargeable (voir ci-dessous) ;
4. remplacer `php -S` — serveur de développement mono-processus — par Apache ou Nginx.

---

## Mise en production

Le dossier se dépose tel quel sur n'importe quel hébergement PHP mutualisé.
`Back/data.db` doit être accessible en écriture par le serveur web, ainsi que `Back/`
lui-même (SQLite y crée ses journaux `-wal` / `-shm`).

**Apache** : `Back/.htaccess` est déjà fourni ; vérifiez qu'`AllowOverride` l'autorise.

**Nginx** : les `.htaccess` sont ignorés, ajoutez dans le `server` :

```nginx
location ~ ^/Back/.*\.(db|db-wal|db-shm|json|log)$ { deny all; }
location ~ \.bak                                   { deny all; }
location ~ ^/(init\.sh|router\.php)$               { deny all; }
```

**Idéal** : déplacez la base hors de la racine web en changeant une seule ligne de
`Back/core.php` :

```php
const SK_DB_FILE = '/var/lib/solarkit/data.db';
```

---

## Dépannage

| Symptôme | Cause probable |
|---|---|
| « SolarKit n'a pas pu démarrer » | serveur PHP arrêté, ou `Back/schema.json` absent → `./init.sh` |
| Page sans aucun style | CDN Tailwind inaccessible (hors ligne, proxy, pare-feu) |
| « Jeton de session invalide » | session expirée → rechargez la page |
| « Connexion requise » sur une lecture | la table est en `access.read: "user"` ou `"admin"` |
| `unable to open database file` | `Back/` n'est pas accessible en écriture par le serveur |
| Une colonne ajoutée n'apparaît pas | JSON invalide dans `schema.json` — l'erreur exacte s'affiche dans le bandeau rouge |

---

## Licence

MIT — faites-en ce que vous voulez.
