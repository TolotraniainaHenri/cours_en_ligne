'use strict';

/*
 * Frontend minimal (JavaScript pur, aucune dépendance) : il ne fait qu'appeler l'API /api.
 * Sécurité : tout texte venant de l'API est inséré avec textContent / createTextNode
 * (jamais innerHTML), ce qui bloque les injections XSS.
 */

const state = { user: null, csrf: null };
const app = document.getElementById('app');
const nav = document.getElementById('nav');
const toastEl = document.getElementById('toast');

// ---------- Utilitaires ----------
function h(tag, attrs = {}, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const kid of kids.flat()) {
    el.append(kid instanceof Node ? kid : document.createTextNode(String(kid ?? '')));
  }
  return el;
}

function toast(message, isError = false) {
  toastEl.textContent = message;
  toastEl.className = isError ? 'show error' : 'show';
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { toastEl.className = ''; }, 3500);
}

function show(...nodes) {
  app.replaceChildren(...nodes);
}

async function loadCsrf() {
  const data = await api('/csrf');
  state.csrf = data.csrf_token;
}

async function api(path, { method = 'GET', body } = {}, retried = false) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET') {
    if (!state.csrf) await loadCsrf();
    headers['X-CSRF-Token'] = state.csrf;
  }
  const res = await fetch('/api' + path, {
    method,
    headers,
    credentials: 'same-origin',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    // Session renouvelée côté serveur : on récupère un nouveau jeton CSRF et on réessaie une fois.
    if (res.status === 403 && !retried && /CSRF/i.test(data?.error?.message || '')) {
      state.csrf = null;
      await loadCsrf();
      return api(path, { method, body }, true);
    }
    const err = new Error(data?.error?.message || 'Erreur inattendue.');
    err.status = res.status;
    err.details = data?.error?.details || {};
    throw err;
  }
  return data;
}

function errorText(err) {
  const details = Object.values(err.details || {});
  return details.length ? `${err.message} ${details.join(' ')}` : err.message;
}

function badge(statut) {
  const labels = { en_attente: 'En attente', validee: 'Validée', refusee: 'Refusée', annulee: 'Annulée' };
  return h('span', { class: `badge ${statut}` }, labels[statut] || statut);
}

// ---------- Navigation ----------
function renderNav() {
  const items = [h('a', { href: '#/' }, 'Cours')];
  if (state.user) {
    if (state.user.role === 'admin') items.push(h('a', { href: '#/admin' }, 'Administration'));
    items.push(h('span', { class: 'who' }, state.user.nom));
    items.push(h('button', { type: 'button', onclick: logout }, 'Déconnexion'));
  } else {
    items.push(h('a', { href: '#/login' }, 'Connexion'));
  }
  nav.replaceChildren(...items);
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' });
  } catch (e) { /* session déjà expirée */ }
  state.user = null;
  state.csrf = null;
  await loadCsrf();
  renderNav();
  toast('Vous êtes déconnecté.');
  location.hash = '#/login';
}

// ---------- Pages ----------
async function pageHome() {
  const { cours } = await api('/courses');
  const nodes = [h('h1', {}, 'Cours de grammaire (gratuits)')];

  if (cours.length === 0) nodes.push(h('p', { class: 'muted' }, 'Aucun cours pour le moment.'));
  nodes.push(h('div', { class: 'grid' }, cours.map((c) =>
    h('article', { class: 'card' },
      h('div', {}, h('span', { class: 'badge' }, c.langue === 'fr' ? 'Français' : 'English'),
        h('span', { class: 'muted' }, `${c.nb_modules} module(s)`)),
      h('h3', {}, c.titre),
      h('p', { class: 'muted' }, c.description || ''),
      h('a', { class: 'btn', href: `#/course/${c.id}` }, 'Voir le cours'),
    ))));

  if (state.user) {
    const { inscriptions } = await api('/enrollments/me');
    nodes.push(h('h2', {}, 'Mes inscriptions'));
    nodes.push(h('div', { class: 'card' }, inscriptions.length === 0
      ? h('p', { class: 'muted' }, "Vous n'avez encore demandé aucune inscription.")
      : h('ul', { class: 'clean' }, inscriptions.map((i) =>
        h('li', { class: 'row' }, badge(i.statut), h('a', { href: `#/course/${i.id_cours}` }, i.cours_titre))))));
  }
  show(...nodes);
}

function pageLogin() {
  const loginForm = h('form', { onsubmit: async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    try {
      const data = await api('/auth/login', { method: 'POST', body: { email: f.get('email'), mot_de_passe: f.get('mot_de_passe') } });
      state.user = data.user;
      state.csrf = data.csrf_token;
      renderNav();
      toast(`Bienvenue ${data.user.nom}`);
      location.hash = '#/';
    } catch (err) { toast(errorText(err), true); }
  } },
    h('h2', {}, 'Connexion'),
    h('label', {}, 'Email', h('input', { type: 'email', name: 'email', required: true, autocomplete: 'username' })),
    h('label', {}, 'Mot de passe', h('input', { type: 'password', name: 'mot_de_passe', required: true, autocomplete: 'current-password' })),
    h('button', { type: 'submit' }, 'Se connecter'));

  const registerForm = h('form', { onsubmit: async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    try {
      await api('/auth/register', { method: 'POST', body: { nom: f.get('nom'), email: f.get('email'), mot_de_passe: f.get('mot_de_passe') } });
      toast('Compte créé. Connectez-vous.');
      e.target.reset();
    } catch (err) { toast(errorText(err), true); }
  } },
    h('h2', {}, 'Créer un compte'),
    h('label', {}, 'Nom', h('input', { type: 'text', name: 'nom', required: true, minlength: 2, maxlength: 100, autocomplete: 'name' })),
    h('label', {}, 'Email', h('input', { type: 'email', name: 'email', required: true, autocomplete: 'email' })),
    h('label', {}, 'Mot de passe (10 caractères min., lettres et chiffres)',
      h('input', { type: 'password', name: 'mot_de_passe', required: true, minlength: 10, maxlength: 72, autocomplete: 'new-password' })),
    h('button', { type: 'submit', class: 'secondary' }, "S'inscrire"));

  show(h('div', { class: 'two-cols' }, h('div', { class: 'card' }, loginForm), h('div', { class: 'card' }, registerForm)));
}

async function pageCourse(id) {
  const data = await api(`/courses/${id}`);
  const { cours, modules, acces } = data;
  const statut = data.inscription_statut;
  const nodes = [
    h('p', {}, h('a', { href: '#/' }, '← Tous les cours')),
    h('h1', {}, cours.titre),
    h('p', { class: 'muted' }, cours.description || ''),
  ];

  const status = h('div', { class: 'card row' });
  if (!state.user) {
    status.append('Connectez-vous pour demander votre inscription. ', h('a', { href: '#/login' }, 'Connexion'));
  } else if (statut === 'en_attente' || statut === 'validee') {
    status.append(badge(statut), statut === 'validee' ? 'Vous avez accès au contenu.' : "Un administrateur doit valider votre demande.");
  } else {
    if (statut) status.append(badge(statut));
    status.append(h('button', { type: 'button', onclick: async () => {
      try {
        await api('/enrollments', { method: 'POST', body: { id_cours: cours.id } });
        toast('Demande envoyée.');
        pageCourse(id);
      } catch (err) { toast(errorText(err), true); }
    } }, "Demander l'inscription"));
  }
  nodes.push(status);

  for (const m of modules) {
    const lessons = m.lecons.map((l) => {
      const exoBox = h('div');
      const row = h('li', {},
        h('div', { class: 'row' },
          h('strong', {}, l.titre),
          h('span', { class: 'muted' }, l.duree ? `${l.duree} min` : ''),
          acces ? h('button', { type: 'button', class: 'secondary', onclick: () => loadExercises(l.id, exoBox) }, 'Exercices') : '',
          acces ? h('button', { type: 'button', class: 'secondary', onclick: () => markDone(l.id) }, 'Marquer terminée') : ''),
        l.url_contenu ? h('a', { href: l.url_contenu, target: '_blank', rel: 'noopener noreferrer' }, 'Ouvrir le contenu') : '',
        exoBox);
      return row;
    });
    nodes.push(h('section', { class: 'card' },
      h('h3', {}, `Module ${m.ordre} : ${m.titre}`),
      lessons.length ? h('ul', { class: 'clean' }, lessons) : h('p', { class: 'muted' }, 'Aucune leçon.')));
  }
  show(...nodes);
}

async function markDone(lessonId) {
  try {
    await api(`/progress/${lessonId}`, { method: 'PUT', body: { pourcentage: 100 } });
    toast('Leçon marquée comme terminée.');
  } catch (err) { toast(errorText(err), true); }
}

async function loadExercises(lessonId, box) {
  try {
    const { exercices } = await api(`/lessons/${lessonId}/exercises`);
    if (exercices.length === 0) { box.replaceChildren(h('p', { class: 'muted' }, 'Aucun exercice.')); return; }
    box.replaceChildren(...exercices.map(renderExercise));
  } catch (err) { toast(errorText(err), true); }
}

function renderExercise(exo) {
  const result = h('p', { class: 'result' });
  const answer = (form) => {
    const f = new FormData(form);
    return String(f.get('reponse') || '');
  };
  const input = exo.type === 'qcm' && Array.isArray(exo.options)
    ? h('div', {}, exo.options.map((o) => h('label', { class: 'row' }, h('input', { type: 'radio', name: 'reponse', value: o, required: true }), o)))
    : h('input', { type: 'text', name: 'reponse', required: true, maxlength: 255, autocomplete: 'off' });

  return h('form', { class: 'card', onsubmit: async (e) => {
    e.preventDefault();
    try {
      const r = await api(`/exercises/${exo.id}/attempts`, { method: 'POST', body: { reponse: answer(e.target) } });
      result.className = 'result ' + (r.correct ? 'ok' : 'ko');
      result.textContent = (r.correct ? 'Correct ! ' : `Incorrect. Réponse attendue : ${r.bonne_reponse}. `) + (r.explication || '');
    } catch (err) { toast(errorText(err), true); }
  } }, h('strong', {}, exo.question), input, h('button', { type: 'submit' }, 'Vérifier'), result);
}

async function pageAdmin() {
  if (!state.user || state.user.role !== 'admin') { location.hash = '#/'; return; }
  const { inscriptions } = await api('/admin/enrollments?statut=en_attente');

  const decide = async (i, statut) => {
    try {
      await api(`/admin/enrollments/${i.id_user}/${i.id_cours}`, { method: 'PATCH', body: { statut } });
      toast('Inscription mise à jour.');
      pageAdmin();
    } catch (err) { toast(errorText(err), true); }
  };

  const pending = h('div', { class: 'card' },
    inscriptions.length === 0
      ? h('p', { class: 'muted' }, 'Aucune demande en attente.')
      : h('ul', { class: 'clean' }, inscriptions.map((i) => h('li', { class: 'row' },
        h('span', {}, `${i.etudiant_nom} (${i.etudiant_email}) → ${i.cours_titre}`),
        h('button', { type: 'button', onclick: () => decide(i, 'validee') }, 'Valider'),
        h('button', { type: 'button', class: 'danger', onclick: () => decide(i, 'refusee') }, 'Refuser')))));

  const createForm = h('form', { class: 'card', onsubmit: async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    try {
      await api('/courses', { method: 'POST', body: { titre: f.get('titre'), description: f.get('description'), langue: f.get('langue') } });
      toast('Cours créé.');
      e.target.reset();
    } catch (err) { toast(errorText(err), true); }
  } },
    h('h3', {}, 'Créer un cours'),
    h('label', {}, 'Titre', h('input', { name: 'titre', required: true, minlength: 2, maxlength: 200 })),
    h('label', {}, 'Description', h('textarea', { name: 'description', rows: 3, maxlength: 5000 })),
    h('label', {}, 'Langue', h('select', { name: 'langue' }, h('option', { value: 'fr' }, 'Français'), h('option', { value: 'en' }, 'English'))),
    h('button', { type: 'submit' }, 'Créer'));

  show(h('h1', {}, 'Administration'), h('h2', {}, "Demandes d'inscription en attente"), pending, createForm);
}

// ---------- Routage ----------
async function route() {
  const [, page = '', arg = ''] = (location.hash || '#/').split('/');
  try {
    if (page === 'login') pageLogin();
    else if (page === 'course' && /^\d+$/.test(arg)) await pageCourse(arg);
    else if (page === 'admin') await pageAdmin();
    else await pageHome();
  } catch (err) {
    if (err.status === 401) { state.user = null; renderNav(); location.hash = '#/login'; return; }
    show(h('div', { class: 'card' }, h('p', { class: 'result ko' }, errorText(err))));
  }
}

async function init() {
  try {
    const me = await api('/auth/me');
    state.user = me.user;
    state.csrf = me.csrf_token;
  } catch (err) {
    state.user = null;
    try { await loadCsrf(); } catch (e) { /* API injoignable */ }
  }
  renderNav();
  window.addEventListener('hashchange', route);
  route();
}

init();
