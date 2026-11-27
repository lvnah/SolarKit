/* ============================================================
 * SolarKit — core.js
 * Micro-framework SPA : $router, $db, $auth, $ui.
 * 0 dépendance, 0 build. Chargé par index.html.
 * ============================================================ */
(function (window, document) {
  'use strict';

  const API = 'Back/api.php';
  const ROUTES = {
    index: { file: 'Front/Index/view.html', title: 'Accueil' },
    login: { file: 'Front/Login/view.html', title: 'Connexion' },
    admin: { file: 'Front/Admin/view.html', title: 'Administration', role: 'admin' }
  };

  /* ----------------------------------------------------------
   * État global
   * -------------------------------------------------------- */
  const state = {
    user: null,
    token: '',
    schema: { app: { name: 'SolarKit' }, tables: {} },
    route: null,
    ready: false
  };

  /* ----------------------------------------------------------
   * Transport
   * -------------------------------------------------------- */
  async function call(action, payload) {
    const body = Object.assign({ action: action }, payload || {});
    let res, json;
    try {
      res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-SolarKit-Token': state.token
        },
        body: JSON.stringify(body)
      });
    } catch (err) {
      throw new Error('Serveur injoignable. Le serveur PHP tourne-t-il ?');
    }
    try {
      json = await res.json();
    } catch (err) {
      throw new Error('Réponse serveur illisible (HTTP ' + res.status + ').');
    }
    if (!json || json.ok !== true) {
      const error = new Error((json && json.error) || 'Erreur inconnue.');
      error.status = res.status;
      throw error;
    }
    return json.data;
  }

  /* ----------------------------------------------------------
   * $db — SDK CRUD auto-généré depuis schema.json
   * -------------------------------------------------------- */
  const $db = {
    list: (table, opts) => call('db.list', Object.assign({ table }, opts || {})),
    count: (table, opts) => call('db.count', Object.assign({ table }, opts || {})),
    get: (table, id) => call('db.get', { table, id }),
    create: (table, data) => call('db.create', { table, data }),
    update: (table, id, data) => call('db.update', { table, id, data }),
    delete: (table, id) => call('db.delete', { table, id }),
    /** Raccourci : $db.table('notes').list() */
    table(name) {
      return {
        list: (opts) => $db.list(name, opts),
        count: (opts) => $db.count(name, opts),
        get: (id) => $db.get(name, id),
        create: (data) => $db.create(name, data),
        update: (id, data) => $db.update(name, id, data),
        delete: (id) => $db.delete(name, id),
        schema: () => state.schema.tables[name] || null
      };
    },
    schema: (name) => (name ? state.schema.tables[name] || null : state.schema),
    fields: (name) => ((state.schema.tables[name] || {}).fields || [])
  };

  /* ----------------------------------------------------------
   * $auth — sessions PHP
   * -------------------------------------------------------- */
  const $auth = {
    get user() { return state.user; },
    get role() { return state.user ? state.user.role : 'guest'; },
    isLogged: () => !!state.user,
    isAdmin: () => !!state.user && state.user.role === 'admin',
    async login(username, password) {
      const data = await call('auth.login', { username, password });
      state.user = data.user;
      state.token = data.token;
      await refreshSchema();
      emit('auth');
      return state.user;
    },
    async logout() {
      const data = await call('auth.logout', {});
      state.user = null;
      state.token = data.token;
      await refreshSchema();
      emit('auth');
    },
    async me() {
      const data = await call('auth.me', {});
      state.user = data.user;
      state.token = data.token;
      emit('auth');
      return state.user;
    },
    changePassword: (current, password) => call('auth.password', { current, password })
  };

  async function refreshSchema() {
    const data = await call('meta.schema', {});
    state.schema = data.schema;
    state.user = data.user;
    state.token = data.token;
    emit('schema');
    return state.schema;
  }

  /* ----------------------------------------------------------
   * Bus d'évènements minimal
   * -------------------------------------------------------- */
  const listeners = {};
  function on(event, fn) {
    (listeners[event] = listeners[event] || []).push(fn);
    return () => off(event, fn);
  }
  function off(event, fn) {
    listeners[event] = (listeners[event] || []).filter((f) => f !== fn);
  }
  function emit(event, detail) {
    (listeners[event] || []).slice().forEach((fn) => {
      try { fn(detail); } catch (err) { console.error('[SolarKit]', event, err); }
    });
    document.dispatchEvent(new CustomEvent('solarkit:' + event, { detail }));
  }

  /* ----------------------------------------------------------
   * $router — l'URL ne change pas, on injecte les fragments
   * -------------------------------------------------------- */
  const cache = {};
  let cleanups = [];

  async function fragment(file) {
    if (cache[file]) return cache[file];
    const res = await fetch(file + '?v=' + Date.now(), { credentials: 'same-origin' });
    if (!res.ok) throw new Error('Fragment introuvable : ' + file);
    cache[file] = await res.text();
    return cache[file];
  }

  /** Exécute les <script> d'un fragment (innerHTML ne les lance pas). */
  function runScripts(container, name) {
    container.querySelectorAll('script').forEach((old) => {
      const fn = new Function('$page', '//# sourceURL=solarkit/' + name + '.js\n' + old.textContent);
      const api = {
        name,
        root: container,
        onLeave(cb) { if (typeof cb === 'function') cleanups.push(cb); }
      };
      try {
        fn(api);
      } catch (err) {
        console.error('[SolarKit] erreur dans la vue "' + name + '"', err);
        $ui.toast('Erreur dans la vue ' + name + ' : ' + err.message, 'error');
      }
      old.remove();
    });
  }

  const $router = {
    get current() { return state.route; },
    routes: ROUTES,
    /** Ajoute une page : $router.add('stats', 'Front/Stats/view.html') */
    add(name, file, opts) {
      ROUTES[name] = Object.assign({ file, title: name }, opts || {});
      return $router;
    },
    async go(name, params) {
      const app = document.getElementById('app');
      if (!app) throw new Error('#app absent de index.html');

      let route = ROUTES[name];
      if (!route) {
        $ui.toast('Page inconnue : ' + name, 'error');
        name = 'index';
        route = ROUTES.index;
      }

      // Garde de rôle : redirige vers la connexion si besoin.
      if (route.role && $auth.role !== route.role && !(route.role === 'user' && $auth.isAdmin())) {
        const target = name;
        if (name !== 'login') {
          $ui.toast(
            $auth.isLogged() ? 'Page réservée au rôle « ' + route.role + ' ».' : 'Connectez-vous pour continuer.',
            'warn'
          );
          state.afterLogin = target;
          return $router.go('login');
        }
      }

      cleanups.splice(0).forEach((cb) => { try { cb(); } catch (e) { console.error(e); } });

      app.setAttribute('aria-busy', 'true');
      let html;
      try {
        html = await fragment(route.file);
      } catch (err) {
        app.innerHTML = '<div class="p-8 text-center text-rose-600">' + esc(err.message) + '</div>';
        app.removeAttribute('aria-busy');
        return;
      }

      state.route = { name, params: params || {} };
      app.innerHTML = html;
      app.removeAttribute('aria-busy');
      document.title = (route.title ? route.title + ' · ' : '') + (state.schema.app.name || 'SolarKit');
      window.scrollTo({ top: 0, behavior: 'smooth' });

      runScripts(app, name);
      emit('route', state.route);
      return state.route;
    },
    /** Rejoue la page courante. */
    reload() {
      return $router.go(state.route ? state.route.name : 'index', state.route ? state.route.params : {});
    },
    /** Page demandée avant une redirection vers /login. */
    consumeRedirect() {
      const target = state.afterLogin;
      state.afterLogin = null;
      return target || 'index';
    }
  };

  /* ----------------------------------------------------------
   * $ui — helpers d'affichage (pas de moteur de template)
   * -------------------------------------------------------- */
  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  const $ui = {
    esc,
    /** $ui.$('#id') / $ui.$$('.cls') */
    $: (sel, root) => (root || document).querySelector(sel),
    $$: (sel, root) => Array.from((root || document).querySelectorAll(sel)),
    toast(message, kind) {
      const host = document.getElementById('toasts');
      if (!host) return console.log('[SolarKit]', message);
      const tones = {
        ok: 'bg-emerald-600',
        error: 'bg-rose-600',
        warn: 'bg-amber-600',
        info: 'bg-slate-800'
      };
      const el = document.createElement('div');
      el.className = 'pointer-events-auto flex items-start gap-3 rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ' +
        (tones[kind] || tones.info) + ' transition duration-300 translate-y-2 opacity-0';
      el.innerHTML = '<span class="flex-1">' + esc(message) + '</span>';
      host.appendChild(el);
      requestAnimationFrame(() => el.classList.remove('translate-y-2', 'opacity-0'));
      setTimeout(() => {
        el.classList.add('opacity-0');
        setTimeout(() => el.remove(), 300);
      }, 3600);
    },
    confirm(message) {
      return window.confirm(message);
    },
    /** Rend une valeur selon le type déclaré dans schema.json. */
    format(value, field) {
      const type = field && field.type;
      if (value === null || value === undefined || value === '') {
        return type === 'bool' ? '—' : '—';
      }
      if (type === 'bool') return Number(value) ? 'Oui' : 'Non';
      if (type === 'money') return Number(value).toFixed(2) + ' €';
      if (type === 'float' || type === 'real' || type === 'number') return String(Number(value));
      if (type === 'password') return '••••••••';
      return String(value);
    },
    /** Champ de formulaire généré depuis un field de schema.json. */
    field(field, value) {
      const id = 'f_' + field.name;
      const base = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 ' +
        'focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200';
      const val = value === null || value === undefined ? (field.default !== null && field.default !== undefined ? field.default : '') : value;
      let input;

      if (field.type === 'textarea') {
        input = '<textarea id="' + id + '" name="' + esc(field.name) + '" rows="3" class="' + base + '"' +
          (field.required ? ' required' : '') + '>' + esc(val) + '</textarea>';
      } else if (field.type === 'select') {
        const opts = ['<option value="">—</option>'].concat(
          (field.options || []).map((o) =>
            '<option value="' + esc(o) + '"' + (String(o) === String(val) ? ' selected' : '') + '>' + esc(o) + '</option>')
        );
        input = '<select id="' + id + '" name="' + esc(field.name) + '" class="' + base + '"' +
          (field.required ? ' required' : '') + '>' + opts.join('') + '</select>';
      } else if (field.type === 'bool') {
        input = '<label class="flex items-center gap-2 text-sm text-slate-700">' +
          '<input id="' + id + '" name="' + esc(field.name) + '" type="checkbox" value="1"' +
          (Number(val) ? ' checked' : '') +
          ' class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-400"> Activé</label>';
      } else {
        const types = {
          int: 'number', integer: 'number', ref: 'number',
          float: 'number', real: 'number', number: 'number', money: 'number',
          date: 'date', datetime: 'datetime-local', time: 'time',
          email: 'email', url: 'url', password: 'password'
        };
        const step = ['float', 'real', 'number', 'money'].indexOf(field.type) >= 0 ? ' step="0.01"' : '';
        input = '<input id="' + id + '" name="' + esc(field.name) + '" type="' + (types[field.type] || 'text') + '"' +
          step + ' value="' + esc(field.type === 'password' ? '' : val) + '" class="' + base + '"' +
          (field.required ? ' required' : '') + '>';
      }

      return '<div class="space-y-1">' +
        '<label for="' + id + '" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">' +
        esc(field.label) + (field.required ? ' <span class="text-rose-500">*</span>' : '') + '</label>' +
        input +
        (field.hint ? '<p class="text-xs text-slate-400">' + esc(field.hint) + '</p>' : '') +
        '</div>';
    },
    /** Lit un <form> en respectant les types du schéma. */
    read(form, fields) {
      const data = {};
      (fields || []).forEach((field) => {
        const el = form.elements[field.name];
        if (!el) return;
        if (field.type === 'bool') {
          data[field.name] = el.checked ? 1 : 0;
          return;
        }
        const raw = el.value;
        if (field.type === 'password' && raw === '') return; // inchangé
        data[field.name] = raw;
      });
      return data;
    }
  };

  /* ----------------------------------------------------------
   * $ui.crud — panneau CRUD complet généré depuis schema.json
   * Usage : $ui.crud('#zone', 'notes', { title: 'Mes notes' })
   * -------------------------------------------------------- */
  $ui.crud = function (target, tableName, options) {
    const host = typeof target === 'string' ? document.querySelector(target) : target;
    if (!host) throw new Error('$ui.crud : cible introuvable ' + target);

    const opts = Object.assign({
      title: null,
      search: true,
      create: true,
      edit: true,
      remove: true,
      order: null,
      limit: 200,
      where: null,
      columns: null,
      empty: 'Aucun enregistrement pour le moment.',
      onChange: null,
      rowClass: null
    }, options || {});

    const meta = $db.schema(tableName);
    if (!meta) {
      host.innerHTML = '<div class="sk-card text-sm text-rose-600">Table &laquo; ' + esc(tableName) +
        ' &raquo; absente du schéma, ou non lisible avec votre rôle.</div>';
      return { refresh() {} };
    }

    const fields = meta.fields || [];
    const writable = meta.canWrite === true;
    const cols = opts.columns
      ? opts.columns.map((n) => fields.filter((f) => f.name === n)[0]).filter(Boolean)
      : fields.filter((f) => f.type !== 'textarea').slice(0, 7);

    let rows = [];
    let editing = null;   // null = formulaire fermé, 0 = création, n = édition de #n
    let query = '';

    host.innerHTML =
      '<section class="space-y-4">' +
        '<div class="flex flex-wrap items-center gap-3">' +
          '<h2 class="text-lg font-bold">' + esc(opts.title || meta.label) + '</h2>' +
          '<span data-sk="count" class="sk-badge bg-slate-100 text-slate-600">0</span>' +
          '<div class="ml-auto flex items-center gap-2">' +
            (opts.search ? '<input data-sk="search" type="search" placeholder="Rechercher…" class="sk-input w-44">' : '') +
            (opts.create && writable ? '<button data-sk="new" class="sk-btn">+ Nouveau</button>' : '') +
          '</div>' +
        '</div>' +
        '<div data-sk="form"></div>' +
        '<div data-sk="list" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm"></div>' +
      '</section>';

    const el = {
      count: host.querySelector('[data-sk="count"]'),
      search: host.querySelector('[data-sk="search"]'),
      newBtn: host.querySelector('[data-sk="new"]'),
      form: host.querySelector('[data-sk="form"]'),
      list: host.querySelector('[data-sk="list"]')
    };

    function renderList() {
      el.count.textContent = rows.length;
      if (!rows.length) {
        el.list.innerHTML = '<p class="px-4 py-10 text-center text-sm text-slate-400">' + esc(opts.empty) + '</p>';
        return;
      }

      const head = '<tr class="border-b border-slate-200 bg-slate-50">' +
        '<th class="sk-th">#</th>' +
        cols.map((f) => '<th class="sk-th">' + esc(f.label) + '</th>').join('') +
        (writable && (opts.edit || opts.remove) ? '<th class="sk-th text-right">Actions</th>' : '') +
        '</tr>';

      const body = rows.map((row) => {
        const extra = typeof opts.rowClass === 'function' ? (opts.rowClass(row) || '') : '';
        const cells = cols.map((f) => {
          const raw = row[f.name];
          if (f.type === 'bool') {
            return '<td class="sk-td">' + (Number(raw)
              ? '<span class="sk-badge bg-emerald-100 text-emerald-700">Oui</span>'
              : '<span class="sk-badge bg-slate-100 text-slate-500">Non</span>') + '</td>';
          }
          const text = $ui.format(raw, f);
          return '<td class="sk-td max-w-xs truncate" title="' + esc(text) + '">' + esc(text) + '</td>';
        }).join('');

        const actions = writable && (opts.edit || opts.remove)
          ? '<td class="sk-td text-right">' +
              (opts.edit ? '<button data-edit="' + row.id + '" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Éditer</button> ' : '') +
              (opts.remove ? '<button data-del="' + row.id + '" class="sk-btn-danger">Suppr.</button>' : '') +
            '</td>'
          : '';

        return '<tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70 ' + extra + '">' +
          '<td class="sk-td text-slate-400">' + row.id + '</td>' + cells + actions + '</tr>';
      }).join('');

      el.list.innerHTML = '<table class="min-w-full divide-y divide-slate-200"><thead>' + head +
        '</thead><tbody>' + body + '</tbody></table>';

      $ui.$$('[data-edit]', el.list).forEach((b) => {
        b.onclick = () => openForm(Number(b.getAttribute('data-edit')));
      });
      $ui.$$('[data-del]', el.list).forEach((b) => {
        b.onclick = () => destroy(Number(b.getAttribute('data-del')));
      });
    }

    function openForm(id) {
      if (!writable) return;
      editing = id || 0;
      const row = id ? rows.filter((r) => r.id === id)[0] || {} : {};
      el.form.innerHTML =
        '<form class="sk-card space-y-4">' +
          '<p class="text-sm font-bold">' + (id ? 'Modifier #' + id : 'Nouvel enregistrement') + '</p>' +
          '<div class="grid gap-4 sm:grid-cols-2">' +
            fields.map((f) => $ui.field(f, row[f.name])).join('') +
          '</div>' +
          '<div class="flex items-center gap-2">' +
            '<button type="submit" class="sk-btn">Enregistrer</button>' +
            '<button type="button" data-sk="cancel" class="sk-btn-ghost">Annuler</button>' +
            '<span data-sk="err" class="text-sm font-medium text-rose-600"></span>' +
          '</div>' +
        '</form>';

      const form = el.form.querySelector('form');
      form.querySelector('[data-sk="cancel"]').onclick = closeForm;
      form.onsubmit = async (ev) => {
        ev.preventDefault();
        const err = form.querySelector('[data-sk="err"]');
        err.textContent = '';
        const data = $ui.read(form, fields);
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
          if (editing) {
            await $db.update(tableName, editing, data);
            $ui.toast('Enregistrement #' + editing + ' modifié.', 'ok');
          } else {
            const created = await $db.create(tableName, data);
            $ui.toast('Enregistrement #' + created.id + ' créé.', 'ok');
          }
          closeForm();
          await api.refresh();
        } catch (e) {
          err.textContent = e.message;
        } finally {
          submit.disabled = false;
        }
      };
      const first = form.querySelector('input, select, textarea');
      if (first) first.focus();
    }

    function closeForm() {
      editing = null;
      el.form.innerHTML = '';
    }

    async function destroy(id) {
      if (!$ui.confirm('Supprimer définitivement l\'enregistrement #' + id + ' ?')) return;
      try {
        await $db.delete(tableName, id);
        $ui.toast('Enregistrement #' + id + ' supprimé.', 'ok');
        if (editing === id) closeForm();
        await api.refresh();
      } catch (e) {
        $ui.toast(e.message, 'error');
      }
    }

    const api = {
      get rows() { return rows; },
      async refresh() {
        try {
          rows = await $db.list(tableName, {
            search: query,
            order: opts.order || meta.order,
            limit: opts.limit,
            where: opts.where || undefined
          });
        } catch (e) {
          el.list.innerHTML = '<p class="px-4 py-10 text-center text-sm text-rose-600">' + esc(e.message) + '</p>';
          return;
        }
        renderList();
        if (typeof opts.onChange === 'function') opts.onChange(rows);
      },
      openForm,
      closeForm
    };

    if (el.newBtn) el.newBtn.onclick = () => openForm(0);
    if (el.search) {
      let timer = null;
      el.search.oninput = () => {
        query = el.search.value.trim();
        clearTimeout(timer);
        timer = setTimeout(api.refresh, 220);
      };
    }

    api.refresh();
    return api;
  };

  /* ----------------------------------------------------------
   * Démarrage
   * -------------------------------------------------------- */
  async function boot() {
    try {
      await refreshSchema();
      state.ready = true;
    } catch (err) {
      const app = document.getElementById('app');
      if (app) {
        app.innerHTML =
          '<div class="mx-auto mt-16 max-w-xl rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800">' +
          '<p class="mb-2 text-base font-bold">SolarKit n\'a pas pu démarrer</p>' +
          '<p class="mb-3">' + esc(err.message) + '</p>' +
          '<p class="text-rose-700">Vérifiez que le serveur PHP tourne (<code>./init.sh</code> ou ' +
          '<code>php -S localhost:8000</code>) et que <code>Back/schema.json</code> existe.</p></div>';
      }
      return;
    }

    // Liens déclaratifs : <a data-go="admin">
    document.addEventListener('click', (ev) => {
      const link = ev.target.closest('[data-go]');
      if (!link) return;
      ev.preventDefault();
      $router.go(link.getAttribute('data-go'));
    });

    on('auth', renderChrome);
    on('schema', renderChrome);
    on('route', renderChrome);
    renderChrome();

    await $router.go('index');
  }

  /** Barre de navigation : dépend du rôle courant. */
  function renderChrome() {
    const brand = document.getElementById('brand');
    if (brand) brand.textContent = state.schema.app.name || 'SolarKit';

    const nav = document.getElementById('nav');
    if (!nav) return;
    const here = state.route ? state.route.name : '';
    const btn = (name, label) =>
      '<button data-go="' + name + '" class="rounded-lg px-3 py-1.5 text-sm font-medium transition ' +
      (here === name ? 'bg-amber-500 text-white shadow' : 'text-slate-600 hover:bg-slate-100') + '">' +
      esc(label) + '</button>';

    let html = btn('index', 'Accueil');
    if ($auth.isAdmin()) {
      html += btn('admin', 'Administration');
      html += '<a href="Back/visualdata.php" target="_blank" rel="noopener" ' +
        'class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Base de données ↗</a>';
    }
    if ($auth.isLogged()) {
      html += '<span class="ml-2 hidden items-center gap-1 text-xs text-slate-500 sm:inline-flex">' +
        esc(state.user.username) + ' · <span class="font-semibold text-amber-600">' + esc(state.user.role) + '</span></span>';
      html += '<button id="btn-logout" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Déconnexion</button>';
    } else {
      html += btn('login', 'Connexion');
    }
    nav.innerHTML = html;

    const out = document.getElementById('btn-logout');
    if (out) {
      out.onclick = async () => {
        await $auth.logout();
        $ui.toast('Déconnecté.', 'ok');
        $router.go('index');
      };
    }
  }

  /* ----------------------------------------------------------
   * Exposition globale
   * -------------------------------------------------------- */
  window.$db = $db;
  window.$auth = $auth;
  window.$router = $router;
  window.$ui = $ui;
  window.SolarKit = {
    version: '1.0.0',
    state,
    call,
    on,
    off,
    emit,
    refreshSchema,
    boot
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
