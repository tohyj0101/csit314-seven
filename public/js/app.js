// =============================================================================
// CSIT314 T04: Seven - Frontend Application Logic (Boundary Layer)
// =============================================================================
// Report §BCE Architecture (p.189):
//   "Boundary-Control-Entity (BCE) architecture... frontend treats the backend
//    as a REST API."
// This file NEVER talks to the database directly. It calls /api/* endpoints,
// receives JSON, and renders the DOM. 
//
// DEMO TIP:
//   - grep for "// [SECTION:" to jump between major features.
//   - grep for "async function" to find page renderers.
//   - The `route()` function at the bottom acts as the SPA router.
// =============================================================================

// =============================================================================
// [SECTION: HTTP CLIENT & ERROR HANDLING]
// =============================================================================
// All API calls funnel through `api()`. On failure, it throws an Error object
// containing the HTTP status and the server's rich error payload (displayData).
// The helpers below (`errorDisplay`, `errorHtml`, etc.) parse that payload to
// render consistent, severity-styled alerts/toasts across the app.
// =============================================================================

/**
 * Central fetch wrapper.
 * @param {string} u - The endpoint URL (e.g., '/api/users').
 * @param {object} o - Fetch options (method, body, headers).
 * @returns {Promise<object>} The parsed JSON response.
 * @throws {Error} Throws an error with `.status` and `.displayData` on failure.
 */
async function api(u, o = {}) {
  try {
    const r = await fetch(u, {
      credentials: 'include', // Crucial: sends the PHPSESSID cookie
      headers: { 'Content-Type': 'application/json', ...(o.headers || {}) },
      method: o.method || 'GET',
      body: o.body
    });

    let b = null;
    try { b = await r.json(); } catch (_) { } // Handle empty responses safely

    if (!r.ok) {
      // Extract the error message from the backend's rich payload
      const msg =
        (b && (b.error || b.message)) ||
        (r.status === 401 ? 'Your session has expired. Please log in again.' :
         r.status === 403 ? 'You do not have permission to perform this action.' :
         r.status === 404 ? 'The requested item could not be found.' :
         r.status === 409 ? 'A conflict occurred. The record might already exist.' :
         r.status === 500 ? 'The server encountered an issue. Please try again.' :
                            'An unknown network error occurred.');

      const err = new Error(msg);
      err.status = r.status;
      if (b && b.display) err.displayData = b; // Attach server's rich error payload
      throw err;
    }
    return b || {};
  } catch (err) {
    // Network failures (e.g., server is down)
    if (err.name === 'TypeError' && err.message.includes('Failed to fetch')) {
      const netErr = new Error('Unable to connect to the server. Please check your internet connection.');
      netErr.status = 0;
      throw netErr;
    }
    throw err;
  }
}

/**
 * Maps HTTP status codes to UI severity levels (info, warning, error, critical).
 */
function statusSeverity(s) {
  if (s >= 500) return 'critical';
  if (s === 401 || s === 403 || s === 404) return 'info';
  if (s === 400 || s === 409 || s === 422) return 'warning';
  return 'error';
}

/**
 * Normalises the error object for display.
 */
function errorDisplay(ex) {
  const d = ex.displayData || {};
  const sev = d.severity || statusSeverity(ex.status);
  const fallbackTitle = { info: 'Notice', warning: 'Invalid Input', error: 'Error', critical: 'Server Error' }[sev] || 'Error';
  const fallbackIcon  = { info: 'ℹ️', warning: '⚠️', error: '❌', critical: '💥' }[sev] || '❌';
  return {
    title:    d.title    || fallbackTitle,
    message:  d.message  || ex.message || 'An unexpected error occurred.',
    severity: sev,
    icon:     d.icon     || fallbackIcon,
    status:   d.status   || ex.status || 0,
    html:     d.html     || null,
  };
}

/**
 * Returns an HTML string for a page-level error alert.
 * If the backend provided pre-rendered HTML, it uses that directly.
 */
function errorHtml(ex) {
  const d = errorDisplay(ex);
  if (d.html) return d.html;
  return `<div class="error-alert" data-severity="${d.severity}" data-status="${d.status}" role="alert">
    <span class="error-alert__icon" aria-hidden="true">${d.icon}</span>
    <div class="error-alert__body">
      <strong class="error-alert__title">${esc(d.title)}</strong>
      <p class="error-alert__message">${esc(d.message)}</p>
    </div>
  </div>`;
}

/**
 * Renders a floating toast notification for errors.
 */
function showDisplayToast(d) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = 'toast display-toast';
  toast.setAttribute('data-severity', d.severity);
  toast.setAttribute('role', 'alert');
  toast.innerHTML = `
    <span class="toast__icon" aria-hidden="true">${d.icon}</span>
    <div class="toast__body">
      <strong class="toast__title">${esc(d.title)}</strong>
      <span class="toast__message">${esc(d.message)}</span>
    </div>
    <button class="toast__close" type="button" aria-label="Dismiss">×</button>
  `;
  const remove = () => {
    toast.style.animation = 'fadeOut 0.5s ease-in forwards';
    setTimeout(() => toast.remove(), 500);
  };
  toast.querySelector('.toast__close').onclick = remove;
  const ttl = { info: 3500, warning: 4500, error: 5000, critical: 7000 }[d.severity] || 5000;
  setTimeout(remove, ttl);
  container.appendChild(toast);
}

/**
 * Convenience wrapper to immediately show an error toast from a caught exception.
 */
function notifyError(ex) {
  showDisplayToast(errorDisplay(ex));
}

// =============================================================================
// [SECTION: UTILS & PERMISSIONS]
// =============================================================================

/** Escapes HTML characters to prevent XSS attacks during DOM injection. */
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** Shortcut for document.querySelector. */
const $ = s => document.querySelector(s);

/** Shortcut for document.getElementById('app'). */
const app = () => $('#app');

/**
 * Maps coarse permission keys (stored in user_profile.permissions in the DB)
 * to fine-grained UI capabilities. This map is primarily used by `parsePermissions()`
 * to translate raw DB permission strings into human-readable labels.
 */
const PERMISSION_MAP = {
  'user:manage': ['Create User Account', 'Update User Account', 'View User Account', 'Suspend User Account', 'Search User Account'],
  'profile:manage': ['Create User Profile', 'Update User Profile', 'View User Profile', 'Suspend User Profile', 'Search User Profile'],
  'company:manage': ['Create Company Information', 'View Company Information', 'Update Company Information', 'Delete Company Information', 'Search Company Information'],
  'project:manage': ['Create Ongoing Project', 'Create Completed Project', 'View Project', 'Update Project', 'Delete Project', 'Search Project'],
  'interest:view': ['View Number of Views', 'View Number of Shortlists'],
  'project:view': ['Search Public Projects', 'View Public Projects'],
  'favourite:manage': ['Save IDP to Favourites', 'Search Favourites List', 'View Favourites List'],
  'category:manage': ['Create IDP Category', 'View IDP Category', 'Update IDP Category', 'Delete IDP Category', 'Search IDP Category'],
  'report:generate': ['Generate Daily Reports', 'Generate Weekly Reports', 'Generate Monthly Reports']
};

/**
 * Provides a human-readable label for a coarse permission key (e.g. 'user:manage').
 */
function getPermissionLabel(p) {
  const labels = {
    'user:manage': 'Manage User Accounts',
    'profile:manage': 'Manage User Profiles',
    'company:manage': 'Manage Company Info',
    'project:manage': 'Manage Projects',
    'interest:view': 'View Interest Metrics',
    'project:view': 'View Public Projects',
    'favourite:manage': 'Manage Favourites',
    'category:manage': 'Manage IDP Categories',
    'report:generate': 'Generate Reports'
  };
  return labels[p] || p;
}

/**
 * Translates raw DB permission strings (e.g. "{user:manage,profile:manage}")
 * into an array of granular UI capabilities using PERMISSION_MAP.
 */
function parsePermissions(perms) {
  let arr = [];
  if (Array.isArray(perms)) {
    arr = perms;
  } else if (typeof perms === 'string') {
    if (perms.startsWith('{') && perms.endsWith('}')) { 
      arr = perms.slice(1, -1).split(',').filter(Boolean); 
    } else { 
      arr = perms.split(',').map(s => s.trim()).filter(Boolean); 
    }
  }
  let result = [];
  arr.forEach(p => {
    if (PERMISSION_MAP[p]) {
      result.push(...PERMISSION_MAP[p]);
    } else {
      result.push(p);
    }
  });
  return result;
}

/**
 * Simple debounce function to prevent spamming the search API on every keystroke.
 */
function debounce(func, delay) {
  let timeout;
  return function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), delay);
  };
}

/**
 * Renders a success/error toast for simple actions (non-server errors).
 */
function showToast(msg, isError = false) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = `toast ${isError ? 'error' : ''}`;
  toast.innerHTML = `${isError ? '❌' : '✅'} ${msg}`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.animation = 'fadeOut 0.5s ease-in forwards';
    setTimeout(() => toast.remove(), 500);
  }, 3500);
}

/**
 * Sets a flash message in sessionStorage to be shown on the next page load.
 */
function flash(msg) { sessionStorage.setItem('flashMsg', msg); }

/**
 * Checks for and displays a flash message.
 */
function checkFlash() {
  const msg = sessionStorage.getItem('flashMsg');
  if (msg) { showToast(msg); sessionStorage.removeItem('flashMsg'); }
}

/**
 * Renders a simple text message in a form (e.g., "Saving...").
 */
function showMsg(id, text, isError) {
  const el = $('#' + id);
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#dc2626' : '#16a34a';
  el.style.fontWeight = 'bold';
  el.style.padding = '';
  el.style.textAlign = '';
  el.style.display = '';
}

/**
 * Renders a structured inline error alert (used inside forms).
 */
function showMsgError(id, ex) {
  const el = $('#' + id);
  if (!el) return;
  const d = errorDisplay(ex);
  el.innerHTML = `
    <span class="error-alert error-alert--inline" data-severity="${d.severity}" data-status="${d.status}" role="alert">
      <span class="error-alert__icon" aria-hidden="true">${d.icon}</span>
      <span class="error-alert__body">
        <strong class="error-alert__title">${esc(d.title)}</strong>
        <span class="error-alert__message">${esc(d.message)}</span>
      </span>
    </span>`;
  el.style.padding = '0';
  el.style.textAlign = 'left';
  el.style.display = 'block';
  el.style.color = '';
  el.style.fontWeight = '';
}

/**
 * Generates the standard page header markup.
 */
const pageHeader = (title, desc) => `
  <div class="page-header">
    <h1>${title}</h1>
    <p>${desc}</p>
  </div>
`;

/**
 * The SPA Shell. Renders the navigation bar based on user permissions,
 * and wraps the provided page content in a <main> container.
 * @param {string} h - The inner HTML of the page.
 */
const shell = h => {
  const u = window.__user, links = [['#/', 'Dashboard']];
  const perms = u.permissions || [];

  // Dynamically build navigation links based on permissions (RBAC)
  if (perms.includes('user:manage') || perms.includes('profile:manage')) {
    if (!links.find(l => l[0] === '#/admin')) links.push(['#/admin', 'User Accounts']);
    if (!links.find(l => l[0] === '#/profiles')) links.push(['#/profiles', 'User Profiles']);
  }
  if (perms.includes('company:manage') || perms.includes('project:manage')) {
    if (!links.find(l => l[0] === '#/companies')) links.push(['#/companies', 'Companies']);
    if (!links.find(l => l[0] === '#/projects')) links.push(['#/projects', 'Projects']);
  }
  if (perms.includes('project:view') || perms.includes('favourite:manage')) {
    if (!links.find(l => l[0] === '#/browse')) links.push(['#/browse', 'Browse']);
    if (!links.find(l => l[0] === '#/favourites')) links.push(['#/favourites', 'Favourites']);
  }
  if (perms.includes('category:manage') || perms.includes('report:generate')) {
    if (!links.find(l => l[0] === '#/categories')) links.push(['#/categories', 'Categories']);
    if (!links.find(l => l[0] === '#/reports')) links.push(['#/reports', 'Reports']);
  }

  // Render nav + main content
  app().innerHTML = `<nav> ${links.map(([h, l]) => `<a href="${h}">${l}</a>`).join('')} <button id="lo">Logout</button> </nav> <main class="card">${h}</main>`;

  // Attach logout handler
  $('#lo').onclick = async () => {
    await api('/api/auth/logout', { method: 'POST' });
    window.__user = null;
    route();
  };
}

// =============================================================================
// [SECTION: AUTHENTICATION & DASHBOARD]
// =============================================================================

/** 
 * US #1, #13, #35, #39 - Login Screen.
 * Renders the login form and handles submission.
 */
function login() {
  app().innerHTML = `<main class="card" style="max-width:480px; margin-top:100px;"> ${pageHeader('Sign In', 'Enter your credentials to access the internal dashboard based on your assigned User Profile.')} <form id="f"> <div class="form-group"><label>Email</label><input name="email" type="email" required /></div> <div class="form-group"><label>Password</label><input name="password" type="password" placeholder="Enter your password..." required /></div> <p id="m" class="error"></p> <button type="submit" class="btn btn-elegant" style="width:100%;">Login</button> </form> </main>`;

  $('#f').onsubmit = async e => {
    e.preventDefault();
    showMsg('m', 'Logging in...', false);
    const data = Object.fromEntries(new FormData(e.target));
    try {
      const response = await api('/api/auth/login', { method: 'POST', body: JSON.stringify(data) });
      window.__user = response.user;
      route();
      if (window.location.hash !== '#/') window.location.hash = '#/';
    } catch (ex) {
      showMsgError('m', ex);
    }
  };
}

/**
 * Renders the Dashboard.
 * Shows/hides module cards based on the user's permissions.
 */
async function dashboard() {
  const perms = window.__user.permissions || [];
  const name = esc(window.__user.username);
  const roleName = esc(window.__user.role);
  let cards = '';

  // Admin modules
  if (perms.includes('user:manage') || perms.includes('profile:manage')) {
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">User Accounts</h3> <p class="meta-text" style="margin-bottom: 2rem;">Manage platform users, reset credentials, and toggle account access.</p> <a href="#/admin" class="btn btn-elegant" style="width: 100%;">Manage User Accounts</a> </div>`;
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">User Profiles</h3> <p class="meta-text" style="margin-bottom: 2rem;">Configure system roles and assign granular module permissions.</p> <a href="#/profiles" class="btn btn-elegant" style="width: 100%;">Manage User Profiles</a> </div>`;
  }
  // Designer modules
  if (perms.includes('company:manage') || perms.includes('project:manage')) {
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Portfolio Projects</h3> <p class="meta-text" style="margin-bottom: 2rem;">Upload new designs, track project status, and view engagement metrics.</p> <a href="#/projects" class="btn btn-elegant" style="width: 100%;">View Portfolio</a> </div>`;
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Company Entities</h3> <p class="meta-text" style="margin-bottom: 2rem;">Update your registered business details and contact information.</p> <a href="#/companies" class="btn btn-elegant" style="width: 100%;">Manage Companies</a> </div>`;
  }
  // Customer modules
  if (perms.includes('project:view') || perms.includes('favourite:manage')) {
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Discovery Gallery</h3> <p class="meta-text" style="margin-bottom: 2rem;">Explore our curated collection of premium interior designs.</p> <a href="#/browse" class="btn btn-elegant" style="width: 100%;">Browse Designs</a> </div>`;
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">My Favourites</h3> <p class="meta-text" style="margin-bottom: 2rem;">Review the rooms and projects you have saved to your mood board.</p> <a href="#/favourites" class="btn btn-elegant" style="width: 100%;">View Shortlists</a> </div>`;
  }
  // Platform Manager modules
  if (perms.includes('category:manage') || perms.includes('report:generate')) {
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Executive Reports</h3> <p class="meta-text" style="margin-bottom: 2rem;">Generate traffic, engagement, and user acquisition analytics.</p> <a href="#/reports" class="btn btn-elegant" style="width: 100%;">View Analytics</a> </div>`;
    cards += `<div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Categories</h3> <p class="meta-text" style="margin-bottom: 2rem;">Maintain the standard classification tags used across the platform.</p> <a href="#/categories" class="btn btn-elegant" style="width: 100%;">Manage Categories</a> </div>`;
  }

  // Fallback if user has no permissions
  if (!cards) {
    cards = `<p class="error" style="grid-column: 1 / -1;">No modules have been assigned to your profile. Please contact the administrator.</p>`;
  }

  const content = `${pageHeader(`Welcome, ${name}`, `You are logged in as a <strong>${roleName}</strong>.`)} <div class="data-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));"> ${cards} </div>`;
  shell(content);
}

// =============================================================================
// [SECTION: USER ACCOUNTS] US #3–#7 (Admin Only)
// =============================================================================

/**
 * US #7, #5 - User Account List & Search
 */
async function adminList() {
  checkFlash();
  shell(`${pageHeader('User Account Management', 'Manage platform users, roles, and account statuses.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/admin/create" class="btn btn-elegant">+ Create New User Account</a> </div> <div class="search-bar"> <input id="q" placeholder="Search by username or email..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);

  const reload = async q => {
    try {
      const u = (q ? await api('/api/users/search?q=' + encodeURIComponent(q)) : await api('/api/users')).users;
      const tb = $('#tb'); if (!tb) return;
      if (u.length === 0) { tb.innerHTML = '<p class="error">❌ No user accounts found.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + u.map(x => `<div class="data-card"> <div class="data-info"> <div class="data-header"> <h3>${esc(x.username)}</h3> <span class="badge ${x.status === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(x.status)}</span> </div> <p class="meta-text"><strong>Role:</strong> ${esc(x.profile_name)}</p> <p class="meta-text"><strong>Email:</strong> ${esc(x.email)}</p> <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;"> <a href="#/admin/view/${x.account_id}" class="btn btn-outline" style="flex:1">View</a> <a href="#/admin/edit/${x.account_id}" class="btn btn-outline" style="flex:1">Update</a> <button class="btn btn-outline" data-s="${x.account_id}" data-st="${x.status}"> ${x.status === 'Active' ? 'Suspend' : 'Activate'} </button> </div> </div> </div>`).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  // Handle Suspend/Activate clicks via event delegation
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-s]');
    if (btn) {
      const s = btn.getAttribute('data-s'), st = btn.getAttribute('data-st');
      if (!confirm(`Are you sure you want to ${st === 'Active' ? 'suspend' : 'activate'} this user account?`)) return;
      try {
        await api('/api/users/' + s + '/' + (st === 'Active' ? 'suspend' : 'activate'), { method: 'PATCH' });
        showToast(`User Account ${st === 'Active' ? 'suspended' : 'activated'} successfully!`);
        reload($('#q').value);
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

/**
 * US #3 - Create User Account
 */
async function adminCreate() {
  let P = (await api('/api/profiles')).profiles;
  shell(`${pageHeader('Create User Account', 'Provision a new system user and assign their User Profile.')} <form id="cf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Username</label><input name="username" placeholder="e.g., johndoe" required /></div> <div class="form-group"><label>Email Address</label><input name="email" type="email" placeholder="e.g., john@example.com" required /></div> <div class="form-group"><label>Temporary Password</label><input name="password" type="password" placeholder="Enter a secure password..." required /></div> <div class="form-group"> <label>Assign User Profile</label> <select name="profile_id">${P.map(p => `<option value="${p.profile_id}">${esc(p.profile_name)}</option>`).join('')}</select> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Create User Account</button> <a href="#/admin" class="btn btn-outline">Cancel</a> </div> </form>`);

  $('#cf').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    b.profile_id = +b.profile_id;
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/users', { method: 'POST', body: JSON.stringify(b) });
      flash('User Account created successfully!');
      window.location.hash = '#/admin';
    } catch (ex) { showMsgError('m', ex); }
  };
}

/**
 * US #5 - View User Account
 */
async function adminView(id) {
  try {
    const r = await api('/api/users/' + id);
    shell(`
      ${pageHeader('View User Account', 'Viewing immutable record for ' + esc(r.user.username))}
      <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;">
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Username:</strong> ${esc(r.user.username)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.user.account_id}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Email:</strong> ${esc(r.user.email)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Assigned Profile:</strong> ${esc(r.user.profile_name)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Status:</strong> <span class="badge ${r.user.status === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(r.user.status)}</span></p>
        <div class="actions" style="margin-top: 2rem; justify-content:center;">
          <a href="#/admin" class="btn btn-outline">Return to Directory</a>
        </div>
      </div>
    `);
  } catch (ex) {
    shell(errorHtml(ex) + `<div style="text-align:center; margin-top:2rem;"><a href="#/admin" class="btn btn-outline">Back</a></div>`);
  }
}

/**
 * US #4 - Update User Account
 */
async function adminEdit(id) {
  try {
    const r = await api('/api/users/' + id);
    const P = (await api('/api/profiles')).profiles;
    shell(`${pageHeader('Update User Account', 'Modify credentials and profile assignments for ' + esc(r.user.username))} <form id="cf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Username</label><input name="username" value="${esc(r.user.username)}" required /></div> <div class="form-group"><label>Email</label><input name="email" type="email" value="${esc(r.user.email)}" required /></div> <div class="form-group"><label>Reset Password (leave blank to keep current)</label><input name="password" type="password" placeholder="Enter new password..." /></div> <div class="form-group"> <label>Assign User Profile</label> <select name="profile_id"> ${P.map(p => `<option value="${p.profile_id}" ${p.profile_id === r.user.profile_id ? 'selected' : ''}>${esc(p.profile_name)}</option>`).join('')} </select> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Update User Account</button> <a href="#/admin" class="btn btn-outline">Cancel</a> </div> </form>`);

    $('#cf').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      b.profile_id = +b.profile_id;
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/users/' + id, { method: 'PUT', body: JSON.stringify(b) });
        flash('User Account updated successfully!');
        window.location.hash = '#/admin';
      } catch (ex) { showMsgError('m', ex); }
    };
  } catch (ex) {
    shell(errorHtml(ex));
  }
}

// =============================================================================
// [SECTION: USER PROFILES] US #8–#12 (Admin Only)
// =============================================================================

/**
 * US #12, #10 - List & Search User Profiles
 */
async function profilesList() {
  checkFlash();
  shell(`${pageHeader('User Profiles', 'Manage platform roles and their granular system permissions.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/profiles/create" class="btn btn-elegant">+ Create New User Profile</a> </div> <div class="search-bar"> <input id="q" placeholder="Search by profile name..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);

  const reload = async (q) => {
    try {
      const L = (q ? await api('/api/profiles/search?q=' + encodeURIComponent(q)) : await api('/api/profiles')).profiles;
      const tb = $('#tb'); if (!tb) return;
      if (L.length === 0) { tb.innerHTML = '<p class="error">❌ No profiles found.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + L.map(p => `<div class="data-card"> <div class="data-info"> <div class="data-header"> <h3>${esc(p.profile_name)}</h3> <span class="badge ${(p.status || 'Active') === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(p.status || 'Active')}</span> </div> <p class="meta-text"><strong>ID:</strong> #${p.profile_id}</p> <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;"> <a href="#/profiles/view/${p.profile_id}" class="btn btn-outline" style="flex:1">View</a> <a href="#/profiles/edit/${p.profile_id}" class="btn btn-outline" style="flex:1">Update</a> <button class="btn btn-outline" data-s="${p.profile_id}" data-st="${p.status || 'Active'}"> ${(p.status || 'Active') === 'Active' ? 'Suspend' : 'Activate'} </button> </div> </div> </div>`).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#tb').onclick = async (e) => {
    const btn = e.target.closest('[data-s]');
    if (btn) {
      const sId = btn.getAttribute('data-s'), sStatus = btn.getAttribute('data-st');
      if (!confirm(`Are you sure you want to ${sStatus === 'Active' ? 'suspend' : 'activate'} this profile?`)) return;
      try {
        await api('/api/profiles/' + sId + '/' + (sStatus === 'Active' ? 'suspend' : 'activate'), { method: 'PATCH' });
        showToast(`User Profile ${sStatus === 'Active' ? 'suspended' : 'activated'} successfully!`);
        reload($('#q').value);
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

/**
 * US #8 - Create User Profile
 */
async function profilesCreate() {
  const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
  const checkboxes = perms.map(p => {
    const label = getPermissionLabel(p);
    return `<label style="display:inline-flex; align-items:center; margin-right: 1.5rem; text-transform:none; font-weight:normal;"><input type="checkbox" name="permissions" value="${p}" style="width:auto; margin-right:8px;"> ${label}</label>`;
  }).join('');

  shell(`${pageHeader('Create User Profile', 'Create a new role structure and attach system permissions.')} <form id="pf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Profile Designation (Name)</label><input name="profile_name" placeholder="e.g., Senior Designer" required /></div> <div class="form-group"> <label>System Permissions Grants</label> <div style="display:flex; flex-wrap:wrap; gap:0.5rem; background:#fff; padding:1.5rem; border:1px solid #ddd; border-radius:4px;"> ${checkboxes} </div> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Create Profile</button> <a href="#/profiles" class="btn btn-outline">Cancel</a> </div> </form>`);

  $('#pf').onsubmit = async e => {
    e.preventDefault();
    const profile_name = e.target.profile_name.value;
    const checkedPerms = Array.from(document.querySelectorAll('input[name="permissions"]:checked')).map(cb => cb.value);
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/profiles', { method: 'POST', body: JSON.stringify({ profile_name, permissions: checkedPerms }) });
      flash('User Profile created successfully!');
      window.location.hash = '#/profiles';
    } catch (ex) { showMsgError('m', ex); }
  };
}

/**
 * US #10 - View User Profile
 */
async function profilesView(id) {
  try {
    const r = await api('/api/profiles/' + id);

    const rawPerms = r.profile.permissions || [];
    const permissionBadges = parsePermissions(rawPerms).map(p =>
      `<span class="badge badge-neutral" style="padding: 0.6rem 1rem; font-size: 0.95rem; border-radius: 6px; white-space: nowrap; text-align: left;">${p}</span>`
    ).join('');

    shell(`
      ${pageHeader('View User Profile', 'Viewing immutable record for ' + esc(r.profile.profile_name))}
      <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;">
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Profile Designation:</strong> ${esc(r.profile.profile_name)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.profile.profile_id}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Status:</strong> <span class="badge ${(r.profile.status || 'Active') === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(r.profile.status || 'Active')}</span></p>

        <div style="margin-bottom: 2rem;">
          <strong style="font-size: 1.1rem; display: block; margin-bottom: 1rem;">System Grants:</strong>
          <div style="display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start;">
            ${permissionBadges || '<span class="meta-text">None Assigned</span>'}
          </div>
        </div>

        <div class="actions" style="margin-top: 2rem; justify-content:center;">
          <a href="#/profiles" class="btn btn-outline">Return to Directory</a>
        </div>
      </div>
    `);
  } catch (ex) {
    shell(errorHtml(ex) + `<div style="text-align:center; margin-top:2rem;"><a href="#/profiles" class="btn btn-outline">Back</a></div>`);
  }
}

/**
 * US #9 - Update User Profile
 */
async function profilesEdit(id) {
  try {
    const r = await api('/api/profiles/' + id);
    const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];

    const checkboxes = perms.map(p => {
      // Handle Postgres TEXT[] representation robustly
      const isChecked = (r.profile.permissions || []).includes(p) ||
                        (Array.isArray(r.profile.permissions) && r.profile.permissions.includes(p)) ||
                        (typeof r.profile.permissions === 'string' && r.profile.permissions.includes(p));

      const checked = isChecked ? 'checked' : '';
      const label = getPermissionLabel(p);
      return `<label style="display:inline-flex; align-items:center; margin-right: 1.5rem; text-transform:none; font-weight:normal;"><input type="checkbox" name="permissions" value="${p}" ${checked} style="width:auto; margin-right:8px;"> ${label}</label>`;
    }).join('');

    shell(`
       ${pageHeader('Update User Profile', 'Modify role designation or attached permissions.')}
       <form id="pf" style="max-width: 600px; margin: 0 auto;">
         <div class="form-group"><label>Profile Designation (Name)</label><input name="profile_name" value="${esc(r.profile.profile_name)}" required /></div>
         <div class="form-group">
           <label>System Permissions Grants</label>
           <div style="display:flex; flex-wrap:wrap; gap:0.5rem; background:#fff; padding:1.5rem; border:1px solid #ddd; border-radius:4px;">
             ${checkboxes}
           </div>
         </div>
         <p id="m" class="error"></p>
         <div class="actions" style="justify-content: center; margin-top: 2rem;">
           <button type="submit" class="btn btn-elegant">Update User Profile</button>
           <a href="#/profiles" class="btn btn-outline">Cancel</a>
         </div>
       </form>
     `);

    $('#pf').onsubmit = async e => {
      e.preventDefault();
      const profile_name = e.target.profile_name.value;
      const checkedPerms = Array.from(document.querySelectorAll('input[name="permissions"]:checked')).map(cb => cb.value);
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/profiles/' + id, { method: 'PUT', body: JSON.stringify({ profile_name, permissions: checkedPerms }) });
        flash('User Profile updated successfully!');
        window.location.hash = '#/profiles';
      } catch (ex) { showMsgError('m', ex); }
    };
  } catch (ex) { shell(errorHtml(ex)); }
}

// =============================================================================
// [SECTION: COMPANY INFORMATION] US #15–#19 (Designer Only)
// =============================================================================

/**
 * US #19, #16 - List & Search Companies
 */
async function companiesList() {
  checkFlash();
  shell(`${pageHeader('Company Directory', 'Manage your associated business entities before attaching projects.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/companies/create" class="btn btn-elegant">+ Create Company</a> </div> <div class="search-bar"> <input id="q" placeholder="Search by company name or address..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);

  const reload = async q => {
    try {
      const L = (q ? await api('/api/companies/search?q=' + encodeURIComponent(q)) : await api('/api/companies')).companies;
      const tb = $('#tb'); if (!tb) return;
      if (L.length === 0) { tb.innerHTML = '<p class="error">❌ No companies registered yet.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + L.map(c => `
          <div class="data-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom:1rem; font-size: 1.5rem; border-bottom: 1px solid #eee; padding-bottom:1rem;">${esc(c.company_name)}</h3>
            <p class="meta-text" style="display:flex; align-items:flex-start; gap:8px;"><strong>📍</strong> <span>${esc(c.address || 'Address Unlisted')}</span></p>
            <p class="meta-text" style="display:flex; align-items:center; gap:8px;"><strong>📞</strong> <span>${esc(c.contact_no || 'No Contact')}</span></p>
            <div class="actions" style="margin-top:auto; padding-top:1rem;">
              <a href="#/companies/view/${c.company_id}" class="btn btn-outline" style="flex:1">View</a>
              <a href="#/companies/edit/${c.company_id}" class="btn btn-outline" style="flex:1">Update</a>
              <button class="btn btn-danger" data-d="${c.company_id}" style="border:none;">Delete</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  // Delete confirmation
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Delete Company #' + di + ' permanently?\n\nNote: companies with linked projects cannot be deleted — you must delete their projects first.')) {
      try {
        await api('/api/companies/' + di, { method: 'DELETE' });
        showToast('Company Information deleted successfully!');
        reload($('#q').value);
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

/**
 * US #15 - Create Company
 */
async function companiesCreate() {
  shell(`${pageHeader('Create Company Information', 'Enter your corporate details to associate future interior design projects.')}
    <form id="f" style="max-width: 600px; margin: 0 auto;">
      <div class="form-group"><label>Registered Business Name</label><input name="company_name" placeholder="e.g., My Design Studio" required/></div>
      <div class="form-group"><label>Address</label><input name="address" placeholder="e.g., 123 Orchard Road" /></div>
      <div class="form-group">
        <label>Contact Hotline (8 Digits)</label>
        <input name="contact_no" type="tel" pattern="\\d{8}" maxlength="8" placeholder="e.g., 91234567" title="Please enter exactly 8 digits"/>
      </div>
      <p id="m" class="error"></p>
      <div class="actions" style="justify-content: center; margin-top: 2rem;">
        <button type="submit" class="btn btn-elegant">Create Company Information</button>
        <a href="#/companies" class="btn btn-outline">Cancel</a>
      </div>
    </form>`);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    
    // Frontend validation for 8-digit contact number
    if (b.contact_no && !/^\d{8}$/.test(b.contact_no)) {
      showMsgError('m', Object.assign(new Error('Contact Hotline must be exactly 8 digits.'), { status: 400 }));
      return;
    }
    
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/companies', { method: 'POST', body: JSON.stringify(b) });
      flash('Company Information created successfully!');
      window.location.hash = '#/companies';
    } catch (ex) { showMsgError('m', ex); }
  };
}

/**
 * US #16 - View Company
 */
async function companiesView(id) {
  try {
    const r = await api('/api/companies/' + id);
    shell(`
      ${pageHeader('View Company Information', 'Viewing immutable record for ' + esc(r.company.company_name))}
      <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;">
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Company Name:</strong> ${esc(r.company.company_name)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.company.company_id}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Address:</strong> ${esc(r.company.address || 'Unlisted')}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Contact:</strong> ${esc(r.company.contact_no || 'Unlisted')}</p>
        <div class="actions" style="margin-top: 2rem; justify-content:center;">
          <a href="#/companies" class="btn btn-outline">Return to Directory</a>
        </div>
      </div>
    `);
  } catch (ex) { shell(errorHtml(ex)); }
}

/**
 * US #17 - Update Company
 */
async function companiesEdit(id) {
  try {
    const r = await api('/api/companies/' + id);
    shell(`${pageHeader('Update Company Information', 'Updating details for ' + esc(r.company.company_name))}
      <form id="f" style="max-width: 600px; margin: 0 auto;">
        <div class="form-group"><label>Company name</label><input name="company_name" value="${esc(r.company.company_name)}" required/></div>
        <div class="form-group"><label>Address</label><input name="address" value="${esc(r.company.address || '')}"/></div>
        <div class="form-group">
          <label>Contact Hotline (8 Digits)</label>
          <input name="contact_no" type="tel" pattern="\\d{8}" maxlength="8" value="${esc(r.company.contact_no || '')}" placeholder="e.g., 91234567"/>
        </div>
        <p id="m" class="error"></p>
        <div class="actions" style="justify-content: center; margin-top: 2rem;">
          <button type="submit" class="btn btn-elegant">Update Company Information</button>
          <a href="#/companies" class="btn btn-outline">Cancel</a>
        </div>
      </form>`);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      if (b.contact_no && !/^\d{8}$/.test(b.contact_no)) {
        showMsgError('m', Object.assign(new Error('Contact Hotline must be exactly 8 digits.'), { status: 400 }));
        return;
      }
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/companies/' + id, { method: 'PUT', body: JSON.stringify(b) });
        flash('Company Information updated successfully!');
        window.location.hash = '#/companies';
      } catch (ex) { showMsgError('m', ex); }
    };
  } catch (ex) { shell(errorHtml(ex)); }
}

// =============================================================================
// [SECTION: PROJECTS] US #20–#31, #37, #38 (Designer Only)
// =============================================================================

/**
 * US #21, #24, #37, #38 - Project Workspace & Analytics.
 * This is the main designer dashboard where they can see their projects
 * and the aggregated view/save metrics (which link to US #37/#38).
 */
async function projectsList() {
  checkFlash();
  let CS = (await api('/api/companies')).companies;
  if (!CS.length) return shell(pageHeader('Project Workspace', '❌ Please create a company first.') + `<div style="text-align:center;"><a href="#/companies/create" class="btn btn-elegant">Create Company</a></div>`);

  if (!window._activeCompany || !CS.find(c => c.company_id == window._activeCompany)) {
    window._activeCompany = CS[0].company_id;
  }

  let L = [], im = {};
  shell(`
    ${pageHeader('Project Workspace & Analytics', 'Track your portfolio and monitor audience engagement metrics (Views/Saves).')}
    <div style="max-width:800px; margin:0 auto; background: #fff; padding: 1.5rem; border-radius:8px; border:1px solid #eee; margin-bottom: 2rem; display:flex; gap: 1rem; align-items:center; justify-content: space-between;">
      <div style="flex:1;">
        <span class="meta-text"><strong>${CS.length}</strong> ${CS.length === 1 ? 'company' : 'companies'} &middot; <strong id="projCount">0</strong> projects shown</span>
      </div>
      <a href="#/projects/create" class="btn btn-elegant">+ Create New Project</a>
    </div>
    <div class="search-bar">
      <input id="q" placeholder="Search by project title..." style="flex:1;" />
      <select id="st" style="width:180px; border-radius:30px;"><option value="">All Statuses</option><option>Ongoing</option><option>Completed</option></select>
      <button class="btn btn-elegant" id="s">Search</button>
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    try {
      im = {}; // Clear previous metrics
      const st = $('#st').value;
      const u = '/api/projects/search?q=' + encodeURIComponent(q) + (st ? '&status=' + encodeURIComponent(st) : '');
      L = (await api(u)).projects;

      // Fetch analytics (US #37/#38) and map them by project_id
      try {
        const ri = await api('/api/projects/search/interest');
        (ri.interest || []).forEach(i => im[i.project_id] = i);
      } catch (_) {}

      const tb = $('#tb'); if (!tb) return;
      const cnt = $('#projCount'); if (cnt) cnt.textContent = L.length;

      if (L.length === 0) { tb.innerHTML = '<p class="error">❌ No projects found for this criteria.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + L.map(p => {
          // Fallback to 0 if no analytics data exists for this project
          const i = im[p.project_id] || { views: 0, shortlists: 0 };
          return `
            <div class="data-card">
              <div class="data-info">
                <div class="data-header">
                  <h3>${esc(p.title)}</h3>
                  <span class="badge ${p.status === 'Completed' ? 'badge-neutral' : 'badge-success'}">${esc(p.status)}</span>
                </div>
                ${p.company_name ? `<p class="meta-text" style="color:var(--primary); font-size:0.75rem; text-transform:uppercase; letter-spacing:1px; margin: 0.25rem 0 0.75rem;">${esc(p.company_name)}</p>` : ''}
                <div class="metrics-row">
                  <div class="metric-item">👁️ ${i.views} Views</div>
                  <div class="metric-item">⭐ ${i.shortlists} Saves</div>
                </div>
                <div class="actions" style="margin-top:auto;">
                  <a href="#/projects/view/${p.project_id}" class="btn btn-outline" style="flex:1">View</a>
                  <a href="#/projects/edit/${p.project_id}" class="btn btn-outline" style="flex:1">Update</a>
                  <button class="btn btn-danger" data-d="${p.project_id}" data-st="${p.status}" style="border:none;">Delete</button>
                </div>
              </div>
            </div>
          `;
        }).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; $('#st').value = ''; reload(''); };

  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-d]');
    if (!btn) return;
    const di = btn.getAttribute('data-d');
    const st = btn.getAttribute('data-st');
    if (!confirm('Delete Project #' + di + ' permanently?')) return;
    try {
      // Use the specific endpoint if it's a completed project
      const url = '/api/projects/' + di + (st === 'Completed' ? '/completed' : '');
      await api(url, { method: 'DELETE' });
      showToast('Project deleted successfully!');
      reload($('#q').value);
    } catch (ex) { notifyError(ex); }
  };
  await reload('');
}

/**
 * US #20, #25 - Create New Project
 */
async function projectsCreate() {
  let CS = (await api('/api/companies')).companies;
  if (!CS.length) return shell(pageHeader('Create New Project', '❌ Missing company requirement.') + `<a href="#/companies" class="btn btn-outline">Back</a>`);

  let activeId = window._activeCompany || CS[0].company_id;

  shell(`${pageHeader('Create New Project', 'Add a new showcase to your portfolio.')}
    <form id="f" style="max-width: 600px; margin: 0 auto;">
      <div class="form-group">
        <label>Company</label>
        <select name="company_id">
          ${CS.map(c => `<option value="${c.company_id}" ${c.company_id == activeId ? 'selected' : ''}>${esc(c.company_name)}</option>`).join('')}
        </select>
      </div>
      <div class="form-group"><label>Project Title</label><input name="title" placeholder="e.g., Modern BTO Renovation" required/></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="4" placeholder="Provide a brief overview of the design concept..." required></textarea></div>
      <div class="form-group">
        <label>Phase Status</label>
        <select name="status"><option value="Ongoing">Ongoing</option><option value="Completed">Completed</option></select>
      </div>
      <p id="m" class="error"></p>
      <div class="actions" style="justify-content: center; margin-top: 2rem;">
        <button type="submit" class="btn btn-elegant">Create New Project</button>
        <a href="#/projects" class="btn btn-outline">Cancel</a>
      </div>
    </form>`);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    b.company_id = +b.company_id;
    showMsg('m', 'Saving...', false);
    try {
      // Dynamically choose the correct endpoint based on status
      const endpoint = (b.status === 'Completed') ? '/api/projects/completed' : '/api/projects';
      await api(endpoint, { method: 'POST', body: JSON.stringify(b) });
      flash('Project created successfully!');
      window.location.hash = '#/projects';
    } catch (ex) { showMsgError('m', ex); }
  };
}

/**
 * US #21, #26 - View Project Details
 */
async function projectsView(id) {
  try {
    const r = await api('/api/projects/' + id);
    const p = r.project;
    shell(`
      ${pageHeader('View Interior Design Project', esc(p.title))}
      <div style="max-width: 800px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; overflow:hidden;">
        <div style="padding: 2rem;">
          <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;">
            <span class="badge ${p.status === 'Completed' ? 'badge-neutral' : 'badge-success'}">${esc(p.status)}</span>
          </p>
          <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem; white-space:pre-line;">${esc(p.description || 'No description provided.')}</p>
          <div class="actions" style="margin-top: 2rem; justify-content:center;">
            <a href="#/projects" class="btn btn-outline">Return to Portfolio</a>
          </div>
        </div>
      </div>
    `);
  } catch (ex) { shell(errorHtml(ex)); }
}

/**
 * US #22, #27 - Update Project Details
 */
async function projectsEdit(id) {
  try {
    const r  = await api('/api/projects/' + id);
    const CS = (await api('/api/companies')).companies;
    const p  = r.project;
    
    // Dynamically choose the correct update endpoint based on the current status
    const putEndpoint = (p.status === 'Completed')
      ? '/api/projects/' + id + '/completed'
      : '/api/projects/' + id;

    shell(`${pageHeader('Update Project Details', 'Updating parameters for ' + esc(p.title))}
      <form id="f" style="max-width: 600px; margin: 0 auto;">
        <div class="form-group">
          <label>Company</label>
          <select name="company_id">
            ${CS.map(c => `<option value="${c.company_id}" ${c.company_id === p.company_id ? 'selected' : ''}>${esc(c.company_name)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label>Project Title</label><input name="title" value="${esc(p.title)}" required/></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="4" required>${esc(p.description || '')}</textarea></div>
        <div class="form-group">
          <label>Phase Status</label>
          <select name="status">
            <option value="Ongoing" ${p.status === 'Ongoing' ? 'selected' : ''}>Ongoing</option>
            <option value="Completed" ${p.status === 'Completed' ? 'selected' : ''}>Completed</option>
          </select>
        </div>
        <p id="m" class="error"></p>
        <div class="actions" style="justify-content: center; margin-top: 2rem;">
          <button type="submit" class="btn btn-elegant">Update Project Details</button>
          <a href="#/projects" class="btn btn-outline">Cancel</a>
        </div>
      </form>`);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      b.company_id = +b.company_id;
      showMsg('m', 'Saving...', false);
      try {
        await api(putEndpoint, { method: 'PUT', body: JSON.stringify(b) });
        flash('Project updated successfully!');
        window.location.hash = '#/projects';
      } catch (ex) { showMsgError('m', ex); }
    };
  } catch (ex) { shell(errorHtml(ex)); }
}

// =============================================================================
// [SECTION: CUSTOMER BROWSE] US #30, #31 (Customer Only)
// =============================================================================

/**
 * US #30 - Discovery Gallery Search
 */
async function browseList() {
  shell(`
    ${pageHeader('Discovery Gallery', 'Discover visually striking interior design projects and save your inspirations.')}
    <div class="search-bar">
      <input id="q" placeholder="Search by project title or description..." style="flex:1;" />
      <button class="btn btn-elegant" id="s">Search</button>
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    try {
      const r = await api('/api/projects/public/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
      const tb = $('#tb'); if (!tb) return;
      if (r.projects.length === 0) { tb.innerHTML = '<p class="error">❌ No projects discovered matching criteria.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + r.projects.map(p => `
          <div class="data-card" style="padding: 1.5rem;">
            <div class="data-header">
              <h3>${esc(p.title)}</h3>
              <span class="badge ${p.status === 'Completed' ? 'badge-neutral' : 'badge-success'}">${esc(p.status)}</span>
            </div>
            <p class="meta-text" style="color:var(--primary); font-weight:500; font-size:0.8rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:1rem;">By ${esc(p.company_name)}</p>
            <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;">
              <a href="#/browse/view/${p.project_id}" class="btn btn-elegant" style="flex: 1;">Explore Room</a>
              <button class="btn btn-outline" data-s2="${p.project_id}" title="Save to Favourites" style="padding: 0.6rem;">⭐</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  // Handle Save to Favourites (US #32)
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-s2]');
    if (btn) {
      try {
        await api('/api/favourites', { method: 'POST', body: JSON.stringify({ project_id: +btn.getAttribute('data-s2') }) });
        showToast('Project saved to Favourites successfully!');
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

/**
 * US #31 - View Public Project (triggers view count increment on backend)
 */
async function browseView(id) {
  try {
    const r = await api('/api/projects/public/' + id);
    const p = r.project;
    shell(`
      ${pageHeader('Explore Room', esc(p.title))}
      <div style="max-width: 900px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; overflow:hidden;">
        <div style="padding: 3rem;">
          <p class="meta-text" style="font-size:1.2rem; margin-bottom:2rem; line-height:1.8; color:#444;">${esc(p.description || 'No conceptual narrative provided by designer.')}</p>
          <div class="actions" style="justify-content:center;">
            <a href="#/browse" class="btn btn-outline">Back to Gallery</a>
          </div>
        </div>
      </div>
    `);
  } catch (ex) { shell(errorHtml(ex)); }
}

// =============================================================================
// [SECTION: CUSTOMER FAVOURITES] US #32–#34 (Customer Only)
// =============================================================================

/**
 * US #33, #34 - My Shortlists (List & Search Favourites)
 */
async function favouritesList() {
  checkFlash();
  shell(`
    ${pageHeader('My Shortlists (Favourites)', 'Curate a personalized mood board of saved interior designs.')}
    <div class="search-bar">
      <input id="q" placeholder="Search saved projects..." style="flex:1;" />
      <button class="btn btn-elegant" id="s">Search</button>
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    try {
      const r = q ? await api('/api/favourites/search?q=' + encodeURIComponent(q)) : await api('/api/favourites');
      const tb = $('#tb'); if (!tb) return;
      if (r.favourites.length === 0) { tb.innerHTML = '<p class="error">❌ You have not saved any projects yet.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + r.favourites.map(f => `
          <div class="data-card" style="padding: 1.5rem;">
            <div class="data-header">
              <h3>${esc(f.title)}</h3>
            </div>
            <div class="actions" style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid #f0f0f0;">
              <a href="#/browse/view/${f.project_id}" class="btn btn-elegant" style="flex: 1;">View Again</a>
              <button class="btn btn-danger" data-x="${f.project_id}" style="border:none;">Remove</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  // Handle Remove Favourite
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-x]');
    if (btn) {
      try {
        await api('/api/favourites/' + btn.getAttribute('data-x'), { method: 'DELETE' });
        showToast('Project removed from Favourites successfully!');
        reload($('#q').value);
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

// =============================================================================
// [SECTION: CATEGORIES] US #41–#45 (Platform Manager Only)
// =============================================================================

/**
 * US #42, #45 - List & Search Categories
 */
async function categoriesList() {
  checkFlash();
  shell(`${pageHeader('Category Management', 'Curate standard design categories ensuring systemic consistency.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/categories/create" class="btn btn-elegant">+ Create New Category</a> </div> <div class="search-bar"> <input id="q" placeholder="Search by category name..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);

  const reload = async q => {
    try {
      const L = (q ? (await api('/api/categories/search?q=' + encodeURIComponent(q))) : (await api('/api/categories'))).categories;
      const tb = $('#tb'); if (!tb) return;
      if (L.length === 0) { tb.innerHTML = '<p class="error">❌ No classification categories found.</p>'; }
      else {
        tb.innerHTML = `<div class="data-grid">` + L.map(c => `
          <div class="data-card" style="padding: 1.5rem;">
            <div class="data-header">
              <h3 style="margin-bottom:1rem; font-size: 1.5rem;">${esc(c.category_name)}</h3>
            </div>
            <p class="meta-text" style="margin-bottom: 1.5rem;">${esc(c.description || 'No defining description provided.')}</p>
            <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #eee;">
              <a href="#/categories/view/${c.category_id}" class="btn btn-outline" style="flex:1">View</a>
              <a href="#/categories/edit/${c.category_id}" class="btn btn-outline" style="flex:1">Update</a>
              <button class="btn btn-danger" data-d="${c.category_id}" style="border:none;">Delete</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { notifyError(ex); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Delete this category?')) {
      try {
        await api('/api/categories/' + di, { method: 'DELETE' });
        showToast('Category deleted successfully!');
        reload($('#q').value);
      } catch (ex) { notifyError(ex); }
    }
  };
  await reload();
}

/**
 * US #41 - Create Category
 */
async function categoriesCreate() {
  shell(`${pageHeader('Create IDP Category', 'Standardize a new classification for portfolios.')} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Category Name</label><input name="category_name" placeholder="e.g., Residential" required/></div> <div class="form-group"><label>Description</label><textarea name="description" rows="4" placeholder="Describe what this category entails..."></textarea></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Create IDP Category</button> <a href="#/categories" class="btn btn-outline">Cancel</a> </div> </form>`);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/categories', { method: 'POST', body: JSON.stringify(b) });
      flash('Category created successfully!');
      window.location.hash = '#/categories';
    } catch (ex) { showMsgError('m', ex); }
  };
}

/**
 * US #42 - View Category
 */
async function categoriesView(id) {
  try {
    const r = await api('/api/categories/' + id);
    shell(`
      ${pageHeader('View IDP Category', 'Viewing immutable record for ' + esc(r.category.category_name))}
      <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;">
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Category Name:</strong> ${esc(r.category.category_name)}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.category.category_id}</p>
        <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem; white-space:pre-line;"><strong>Description:</strong><br>${esc(r.category.description || 'N/A')}</p>
        <div class="actions" style="margin-top: 2rem; justify-content:center;">
          <a href="#/categories" class="btn btn-outline">Return to Directory</a>
        </div>
      </div>
    `);
  } catch (ex) { shell(errorHtml(ex)); }
}

/**
 * US #43 - Update Category
 */
async function categoriesEdit(id) {
  try {
    const r = await api('/api/categories/' + id);
    shell(`${pageHeader('Update IDP Category', '')} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Category Name</label><input name="category_name" value="${esc(r.category.category_name)}" required/></div> <div class="form-group"><label>Description</label><textarea name="description" rows="4">${esc(r.category.description || '')}</textarea></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Update IDP Category</button> <a href="#/categories" class="btn btn-outline">Cancel</a> </div> </form>`);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/categories/' + id, { method: 'PUT', body: JSON.stringify(b) });
        flash('Category updated successfully!');
        window.location.hash = '#/categories';
      } catch (ex) { showMsgError('m', ex); }
    };
  } catch (ex) { shell(errorHtml(ex)); }
}

// =============================================================================
// [SECTION: REPORTS] US #46–#48 (Platform Manager Only)
// =============================================================================

/**
 * Renders the Reports Page.
 * Dynamically fetches and renders Daily, Weekly, or Monthly reports.
 */
async function reports() {
  shell(`
    ${pageHeader('Executive Platform Analytics', 'View platform performance and engagement metrics.')}
    <div class="actions" style="justify-content: center; margin-bottom: 2rem;">
       <button class="btn btn-elegant" data-p="daily">Daily Report</button>
       <button class="btn btn-elegant" data-p="weekly">Weekly Report</button>
       <button class="btn btn-elegant" data-p="monthly">Monthly Report</button>
     </div>
     <h2 id="t" style="text-align:center; font-weight:300; margin-bottom:2rem;">Select a report to generate</h2>
     <div id="m"></div>
     <div id="sm"></div>
     <div id="tb-wrap" style="display:none; margin-top:3rem;">
       <h2 style="font-weight:400; font-size:1.5rem; margin-bottom:1rem;">Top Projects</h2>
       <table>
         <thead>
           <tr>
             <th>Project Title</th>
             <th>Company</th>
             <th>Views</th>
             <th>Saves</th>
           </tr>
         </thead>
         <tbody id="tb"></tbody>
       </table>
     </div>
  `);

  // Event delegation for the three report buttons
  app().onclick = async e => {
    const p = e.target.getAttribute('data-p');
    if (!p) return;

    showMsg('m', 'Generating report...', false);
    try {
      const r = await api('/api/reports/' + p);
      const tEl = $('#t'); if (!tEl) return;
      tEl.textContent = p.charAt(0).toUpperCase() + p.slice(1) + ' Performance Report';

      const smEl = $('#sm'); if (!smEl) return;

      // Render summary KPI cards
      smEl.innerHTML = `
         <div class="data-grid" style="grid-template-columns: repeat(4, 1fr); margin-top:0;">
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">New Users</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.new_users}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">Total Views</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.project_views}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">Total Saves</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.shortlists}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">New Projects</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.new_projects}</strong>
           </div>
         </div>
       `;

      const tbWrap = $('#tb-wrap'); if (!tbWrap) return;
      tbWrap.style.display = 'block';

      // Render Top Projects Table
      const tbEl = $('#tb'); if (!tbEl) return;
      tbEl.innerHTML = r.topProjects.map(x => `
        <tr>
          <td style="font-weight:500;">${esc(x.title)}</td>
          <td style="color:var(--text-muted);">${esc(x.company_name)}</td>
          <td><span class="badge badge-neutral">${x.views}</span></td>
          <td><span class="badge badge-success">${x.shortlists}</span></td>
        </tr>
      `).join('');
      showMsg('m', '', false);
      showToast(`${p.charAt(0).toUpperCase() + p.slice(1)} Report generated successfully!`);
    } catch (ex) { showMsgError('m', ex); }
  };
}

// =============================================================================
// [SECTION: DIRECTORY ROUTER]
// =============================================================================
// This is the SPA Hash Router. It reads `window.location.hash` and decides
// which view function to call. It also enforces frontend RBAC.
// =============================================================================

async function route() {
  // Example hash: "#/admin/view/12" -> page="admin", action="view", id="12"
  const hash = (location.hash || '#/').replace(/^#\/?/, '');
  const parts = hash.split('/');
  const page = parts[0];
  const action = parts[1];
  const id = parts[2];

  // Rehydrate user session on every route change to ensure permissions are up-to-date
  try { window.__user = (await api('/api/auth/me')).user; }
  catch (_) { return login(); } // If 401, redirect to login screen

  const perms = window.__user.permissions || [];

  // --- 1. Admin Routes ---
  if (page === 'admin' || page === 'profiles') {
    if (!perms.includes('user:manage') && !perms.includes('profile:manage')) return dashboard();
    if (page === 'admin') {
      if (action === 'create') return adminCreate();
      if (action === 'view') return adminView(id);
      if (action === 'edit') return adminEdit(id);
      return adminList();
    }
    if (page === 'profiles') {
      if (action === 'create') return profilesCreate();
      if (action === 'view') return profilesView(id);
      if (action === 'edit') return profilesEdit(id);
      return profilesList();
    }
  }

  // --- 2. Designer Routes ---
  if (page === 'companies' || page === 'projects') {
    if (!perms.includes('company:manage') && !perms.includes('project:manage')) return dashboard();
    if (page === 'companies') {
      if (action === 'create') return companiesCreate();
      if (action === 'view') return companiesView(id);
      if (action === 'edit') return companiesEdit(id);
      return companiesList();
    }
    if (page === 'projects') {
      if (action === 'create') return projectsCreate();
      if (action === 'view') return projectsView(id);
      if (action === 'edit') return projectsEdit(id);
      return projectsList();
    }
  }

  // --- 3. Customer Routes ---
  if (page === 'browse' || page === 'favourites') {
    if (!perms.includes('project:view') && !perms.includes('favourite:manage')) return dashboard();
    if (page === 'browse') {
      if (action === 'view') return browseView(id);
      return browseList();
    }
    if (page === 'favourites') return favouritesList();
  }

  // --- 4. Platform Manager Routes ---
  if (page === 'categories' || page === 'reports') {
    if (!perms.includes('category:manage') && !perms.includes('report:generate')) return dashboard();
    if (page === 'categories') {
      if (action === 'create') return categoriesCreate();
      if (action === 'view') return categoriesView(id);
      if (action === 'edit') return categoriesEdit(id);
      return categoriesList();
    }
    if (page === 'reports') return reports();
  }

  // --- 5. Fallback ---
  return dashboard();
}

// Listen for URL changes and trigger the router
window.addEventListener('hashchange', route);

// Initial route execution when the app first loads
route();