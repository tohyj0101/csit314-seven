// =============================================================================
// CSIT314 T04: Seven - Frontend Application Logic (Boundary Layer)
// =============================================================================

// =============================================================================
// 1. GLOBAL UTILITIES & ERROR HANDLING
// =============================================================================

/**
 * Global API fetch wrapper.
 * Handles JSON parsing, credentials, and user-friendly error catching.
 * Why? To prevent raw "Internal Server Error" messages from confusing the user.
 */
async function api(u, o = {}) {
  try {
    const r = await fetch(u, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', ...(o.headers || {}) },
      method: o.method || 'GET',
      body: o.body
    });
    
    let b = null;
    try { b = await r.json(); } catch (_) { /* Handle empty body */ }
    
    if (!r.ok) {
      // If the backend provided a specific validation error, use it.
      if (b && b.error) throw new Error(b.error);
      
      // Otherwise, fallback to friendly HTTP status messages
      if (r.status === 401) throw new Error('Your session has expired. Please log in again.');
      if (r.status === 403) throw new Error('You do not have permission to perform this action.');
      if (r.status === 404) throw new Error('The requested item could not be found.');
      if (r.status === 409) throw new Error('A conflict occurred. The record might already exist.');
      if (r.status === 500) throw new Error('The server encountered an issue. Please try again in a few moments.');
      throw new Error('An unknown network error occurred.');
    }
    
    return b || {};
  } catch (err) {
    if (err.name === 'TypeError' && err.message.includes('Failed to fetch')) {
      throw new Error('Unable to connect to the server. Please check your internet connection.');
    }
    throw err;
  }
}

/**
 * Security Helper: XSS Prevention.
 */
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = s => document.querySelector(s), app = () => $('#app');

/**
 * Performance Optimization: Predictive Search (Debounce).
 */
function debounce(func, delay) {
  let timeout;
  return function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), delay);
  };
}

/**
 * UI Helper: Displays Success (Green) or Failure (Red) messages.
 */
function showMsg(id, text, isError) {
  const el = $('#' + id);
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#dc2626' : '#16a34a';
  el.style.fontWeight = 'bold';
}

/**
 * UI Component: Reusable HTML Table Generator.
 */
const tbl = (cols, rows, act) => `
  <table>
    <thead><tr>${cols.map(c => `<th>${c}</th>`).join('')}<th>Actions</th></tr></thead>
    <tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${esc(r[c])}</td>`).join('')}<td>${act(r)}</td></tr>`).join('')}</tbody>
  </table>`;

/**
 * UI Component: Application Shell (Navigation Bar).
 */
const shell = h => {
  const u = window.__user, links = [['#/', 'Home']];
  if (u.role === 'User Admin') links.push(['#/admin', 'Users'], ['#/profiles', 'Profiles']);
  if (u.role === 'Interior Designer') links.push(['#/companies', 'Companies'], ['#/projects', 'Projects']);
  if (u.role === 'Customer') links.push(['#/browse', 'Browse'], ['#/favourites', 'Favourites']);
  if (u.role === 'Platform Manager') links.push(['#/categories', 'Categories'], ['#/reports', 'Reports']);
  
  app().innerHTML = `
    <nav>
      ${links.map(([h, l]) => `<a href="${h}">${l}</a>`).join('')}
      <button id="lo" class="btn btn-danger">Logout</button>
    </nav>
    <main class="card">${h}</main>
  `;
  $('#lo').onclick = async () => { 
    await api('/api/auth/logout', { method: 'POST' }); 
    window.__user = null; 
    route(); 
  };
}

// =============================================================================
// 2. AUTHENTICATION (US #1, #13, #35, #39)
// =============================================================================
function login() {
  app().innerHTML = `
    <main class="card" style="max-width:420px; margin-top:100px;">
      <h1 style="text-align:center;">Sign in</h1>
      <form id="f">
        <div class="form-group"><label>Email</label><input name="email" type="email" required /></div>
        <div class="form-group"><label>Password</label><input name="password" type="password" required /></div>
        <p id="m" class="error"></p>
        <button type="submit" class="btn btn-primary" style="width:100%;">Login</button>
      </form>
    </main>`;
    
  $('#f').onsubmit = async e => {
    e.preventDefault();
    showMsg('m', 'Logging in...', false);
    const data = Object.fromEntries(new FormData(e.target));
    try {
      await api('/api/auth/login', { method: 'POST', body: JSON.stringify(data) });
      window.location.hash = '#/';
    } catch (ex) {
      showMsg('m', '❌ ' + ex.message, true);
    }
  };
}

async function dashboard() {
  shell(`<h1>Welcome, ${esc(window.__user.username)}</h1>
         <p>Role: <strong>${esc(window.__user.role)}</strong></p>
         <p>Use the navigation bar above to manage the system.</p>`);
}

// =============================================================================
// 3. USER ACCOUNTS (US #3 - #7) - Admin Only
// =============================================================================
async function adminList() {
  let U = (await api('/api/users')).users;
  const rows = u => tbl(['account_id', 'username', 'email', 'profile_name', 'status'], u, x => `
    <div class="actions">
      <a href="#/admin/view/${x.account_id}" class="btn btn-primary">View</a>
      <a href="#/admin/edit/${x.account_id}" class="btn btn-secondary">Edit</a>
      <button class="btn ${x.status === 'Active' ? 'btn-warning' : 'btn-success'}" data-s="${x.account_id}" data-st="${x.status}">
        ${x.status === 'Active' ? 'Suspend' : 'Activate'}
      </button>
    </div>
  `);
  
  shell(`
    <h1>User Accounts</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <a href="#/admin/create" class="btn btn-primary">+ Create User</a>
    </div>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search username or email" style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button>
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb">${rows(U)}</div>
  `);

  const reload = async q => {
    try {
      const u = (q ? await api('/api/users/search?q=' + encodeURIComponent(q)) : await api('/api/users')).users;
      if (q && u.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No users found matching your search.</p>';
      } else {
        $('#tb').innerHTML = rows(u);
      }
    } catch (ex) { alert(ex.message); }
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#tb').onclick = async e => {
    const s = e.target.getAttribute('data-s');
    const st = e.target.getAttribute('data-st');
    if (s) {
      if (!confirm(`Are you sure you want to ${st === 'Active' ? 'suspend' : 'activate'} this user?`)) return;
      try {
        await api('/api/users/' + s + '/' + (st === 'Active' ? 'suspend' : 'activate'), { method: 'PATCH' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
}

async function adminCreate() {
  let P = (await api('/api/profiles')).profiles;
  shell(`
    <h1>Create User</h1>
    <form id="cf">
      <div class="form-group"><label>Username</label><input name="username" required /></div>
      <div class="form-group"><label>Email</label><input name="email" type="email" required /></div>
      <div class="form-group"><label>Password</label><input name="password" type="password" required /></div>
      <div class="form-group">
        <label>Profile</label>
        <select name="profile_id">${P.map(p => `<option value="${p.profile_id}">${esc(p.profile_name)}</option>`).join('')}</select>
      </div>
      <p id="m" class="error"></p>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Create User</button>
        <a href="#/admin" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  `);

  $('#cf').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    b.profile_id = +b.profile_id;
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/users', { method: 'POST', body: JSON.stringify(b) });
      window.location.hash = '#/admin';
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function adminView(id) {
  try {
    const r = await api('/api/users/' + id);
    shell(`
      <h1>View User</h1>
      <div style="padding: 1rem; background: #f8fafc; border-radius: 6px;">
        <p><strong>ID:</strong> ${r.user.account_id}</p>
        <p><strong>Username:</strong> ${esc(r.user.username)}</p>
        <p><strong>Email:</strong> ${esc(r.user.email)}</p>
        <p><strong>Role:</strong> ${esc(r.user.profile_name)}</p>
        <p><strong>Status:</strong> ${esc(r.user.status)}</p>
      </div>
      <div class="actions" style="margin-top: 1rem;">
        <a href="#/admin" class="btn btn-secondary">Back to List</a>
      </div>
    `);
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/admin" class="btn btn-secondary">Back</a>`); }
}

async function adminEdit(id) {
  try {
    const r = await api('/api/users/' + id);
    const P = (await api('/api/profiles')).profiles;
    shell(`
      <h1>Edit User: ${esc(r.user.username)}</h1>
      <form id="cf">
        <div class="form-group"><label>Username</label><input name="username" value="${esc(r.user.username)}" required /></div>
        <div class="form-group"><label>Email</label><input name="email" type="email" value="${esc(r.user.email)}" required /></div>
        <div class="form-group"><label>Password (leave blank to keep current)</label><input name="password" type="password" placeholder="New Password..." /></div>
        <div class="form-group">
          <label>Profile</label>
          <select name="profile_id">
            ${P.map(p => `<option value="${p.profile_id}" ${p.profile_id === r.user.profile_id ? 'selected' : ''}>${esc(p.profile_name)}</option>`).join('')}
          </select>
        </div>
        <p id="m" class="error"></p>
        <div class="actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="#/admin" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    `);

    $('#cf').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      b.profile_id = +b.profile_id;
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/users/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/admin';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/admin" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 4. USER PROFILES (US #8 - #12) - Admin Only
// =============================================================================
async function profilesList() {
  let L = (await api('/api/profiles')).profiles;
  const rows = () => tbl(['profile_id', 'profile_name', 'status'], L, p => `
    <div class="actions">
      <a href="#/profiles/view/${p.profile_id}" class="btn btn-primary">View</a>
      <a href="#/profiles/edit/${p.profile_id}" class="btn btn-secondary">Edit</a>
      <button class="btn ${(p.status || 'Active') === 'Active' ? 'btn-warning' : 'btn-success'}" data-s="${p.profile_id}" data-st="${p.status || 'Active'}">
        ${(p.status || 'Active') === 'Active' ? 'Suspend' : 'Activate'}
      </button>
    </div>
  `);

  shell(`
    <h1>User Profiles</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <a href="#/profiles/create" class="btn btn-primary">+ Create Profile</a>
    </div>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search profile name" style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button>
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb">${rows()}</div>
  `);

  const reload = async (q) => {
    if (q) {
      L = (await api('/api/profiles/search?q=' + encodeURIComponent(q))).profiles;
      if (L.length === 0) return $('#tb').innerHTML = '<p class="error">❌ No matching profiles found.</p>';
    } else {
      L = (await api('/api/profiles')).profiles;
    }
    $('#tb').innerHTML = rows();
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#tb').onclick = async (e) => {
    const sId = e.target.getAttribute('data-s');
    const sStatus = e.target.getAttribute('data-st');
    if (sId) {
      if (!confirm(`Are you sure you want to ${sStatus === 'Active' ? 'suspend' : 'activate'} this profile?`)) return;
      try {
        await api('/api/profiles/' + sId + '/' + (sStatus === 'Active' ? 'suspend' : 'activate'), { method: 'PATCH' });
        reload($('#q').value);
      } catch(ex) { alert(ex.message); }
    }
  };
}

async function profilesCreate() {
  const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
  const checkboxes = perms.map(p => `<label style="display:inline-flex; align-items:center; margin-right: 1rem;"><input type="checkbox" name="permissions" value="${p}" style="margin-right:5px; width:auto;"> ${p}</label>`).join('');

  shell(`
    <h1>Create Profile</h1>
    <form id="pf">
      <div class="form-group"><label>Profile Name</label><input name="profile_name" required placeholder="e.g. Senior Designer" /></div>
      <div class="form-group">
        <label>Permissions:</label><br/>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">${checkboxes}</div>
      </div>
      <p id="m" class="error"></p>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Create Profile</button>
        <a href="#/profiles" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  `);

  $('#pf').onsubmit = async e => {
    e.preventDefault();
    const profile_name = e.target.profile_name.value;
    const checkedPerms = Array.from(document.querySelectorAll('input[name="permissions"]:checked')).map(cb => cb.value);
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/profiles', { method: 'POST', body: JSON.stringify({ profile_name, permissions: checkedPerms }) });
      window.location.hash = '#/profiles';
    } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function profilesView(id) {
  try {
    const r = await api('/api/profiles/' + id);
    shell(`
      <h1>View Profile</h1>
      <div style="padding: 1rem; background: #f8fafc; border-radius: 6px;">
        <p><strong>ID:</strong> ${r.profile.profile_id}</p>
        <p><strong>Name:</strong> ${esc(r.profile.profile_name)}</p>
        <p><strong>Status:</strong> ${esc(r.profile.status || 'Active')}</p>
        <p><strong>Permissions:</strong> ${(r.profile.permissions || []).join(', ') || 'None'}</p>
      </div>
      <div class="actions" style="margin-top: 1rem;">
        <a href="#/profiles" class="btn btn-secondary">Back to List</a>
      </div>
    `);
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/profiles" class="btn btn-secondary">Back</a>`); }
}

async function profilesEdit(id) {
  try {
    const r = await api('/api/profiles/' + id);
    const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
    const checkboxes = perms.map(p => {
      const checked = (r.profile.permissions || []).includes(p) ? 'checked' : '';
      return `<label style="display:inline-flex; align-items:center; margin-right: 1rem;"><input type="checkbox" name="permissions" value="${p}" ${checked} style="margin-right:5px; width:auto;"> ${p}</label>`;
    }).join('');

    shell(`
      <h1>Edit Profile: ${esc(r.profile.profile_name)}</h1>
      <form id="pf">
        <div class="form-group"><label>Profile Name</label><input name="profile_name" value="${esc(r.profile.profile_name)}" required /></div>
        <div class="form-group">
          <label>Permissions:</label><br/>
          <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">${checkboxes}</div>
        </div>
        <p id="m" class="error"></p>
        <div class="actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="#/profiles" class="btn btn-secondary">Cancel</a>
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
        window.location.hash = '#/profiles';
      } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/profiles" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 5. COMPANY INFORMATION (US #15 - #19) - Designer Only
// =============================================================================
async function companiesList() {
  let L = (await api('/api/companies')).companies;
  const rows = c => tbl(['company_id', 'company_name', 'address', 'contact_no'], c, x => `
    <div class="actions">
      <a href="#/companies/view/${x.company_id}" class="btn btn-primary">View</a>
      <a href="#/companies/edit/${x.company_id}" class="btn btn-secondary">Edit</a>
      <button class="btn btn-danger" data-d="${x.company_id}">Delete</button>
    </div>
  `);

  shell(`
    <h1>Company Information</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <a href="#/companies/create" class="btn btn-primary">+ Create Company</a>
    </div>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search company name" style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button>
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb">${rows(L)}</div>
  `);

  const reload = async q => {
    L = (q ? await api('/api/companies/search?q=' + encodeURIComponent(q)) : await api('/api/companies')).companies;
    if (q && L.length === 0) return $('#tb').innerHTML = '<p class="error">❌ No companies found.</p>';
    $('#tb').innerHTML = rows(L);
  };

  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Delete Company #' + di + '?')) {
      try {
        await api('/api/companies/' + di, { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
}

async function companiesCreate() {
  shell(`
    <h1>Create Company</h1>
    <form id="f">
      <div class="form-group"><label>Company name</label><input name="company_name" required/></div>
      <div class="form-group"><label>Address</label><input name="address"/></div>
      <div class="form-group"><label>Contact</label><input name="contact_no"/></div>
      <p id="m" class="error"></p>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Save Company</button> 
        <a href="#/companies" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  `);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/companies', { method: 'POST', body: JSON.stringify(b) });
      window.location.hash = '#/companies';
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function companiesView(id) {
  try {
    const r = await api('/api/companies/' + id);
    shell(`
      <h1>View Company</h1>
      <div style="padding: 1rem; background: #f8fafc; border-radius: 6px;">
        <p><strong>ID:</strong> ${r.company.company_id}</p>
        <p><strong>Name:</strong> ${esc(r.company.company_name)}</p>
        <p><strong>Address:</strong> ${esc(r.company.address || 'N/A')}</p>
        <p><strong>Contact:</strong> ${esc(r.company.contact_no || 'N/A')}</p>
      </div>
      <div class="actions" style="margin-top: 1rem;">
        <a href="#/companies" class="btn btn-secondary">Back to List</a>
      </div>
    `);
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/companies" class="btn btn-secondary">Back</a>`); }
}

async function companiesEdit(id) {
  try {
    const r = await api('/api/companies/' + id);
    shell(`
      <h1>Edit Company: ${esc(r.company.company_name)}</h1>
      <form id="f">
        <div class="form-group"><label>Company name</label><input name="company_name" value="${esc(r.company.company_name)}" required/></div>
        <div class="form-group"><label>Address</label><input name="address" value="${esc(r.company.address || '')}"/></div>
        <div class="form-group"><label>Contact</label><input name="contact_no" value="${esc(r.company.contact_no || '')}"/></div>
        <p id="m" class="error"></p>
        <div class="actions">
          <button type="submit" class="btn btn-primary">Save Changes</button> 
          <a href="#/companies" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    `);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/companies/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/companies';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/companies" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 6. PROJECTS (US #20 - #29, #37, #38) - Designer Only
// =============================================================================
async function projectsList() {
  let CS = (await api('/api/companies')).companies;
  if (!CS.length) return shell('<h1>Projects</h1><p class="error">❌ Please create a company first.</p><a href="#/companies/create" class="btn btn-primary">Create Company</a>');
  
  let cur = CS[0].company_id, L = [], im = {};
  
  const rows = () => tbl(['project_id', 'title', 'status', 'Metrics'], L, p => {
    const i = im[p.project_id] || { views: 0, shortlists: 0 };
    return `
      <div class="actions">
        <a href="#/projects/view/${p.project_id}" class="btn btn-primary">View</a>
        <a href="#/projects/edit/${p.project_id}" class="btn btn-secondary">Edit</a>
        <button class="btn btn-danger" data-d="${p.project_id}">Delete</button>
        <span style="margin-left:10px; font-weight:bold; color:var(--text-muted);">V:${i.views} S:${i.shortlists}</span>
      </div>
    `;
  });

  shell(`
    <h1>Projects</h1>
    <div class="form-group">
      <label>Select Company</label>
      <select id="sel">${CS.map(c => `<option value="${c.company_id}">${esc(c.company_name)}</option>`).join('')}</select>
    </div>
    
    <div class="actions" style="margin-bottom: 1rem;">
      <a href="#/projects/create" class="btn btn-primary">+ Create Project</a>
    </div>
    
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search projects..." style="flex:1; margin:0;" />
      <select id="st" style="width:auto; margin:0;"><option value="">All Statuses</option><option>Ongoing</option><option>Completed</option></select>
      <button class="btn btn-primary" id="s">Search</button>
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    
    <div id="tb"></div>
  `);

  const reload = async q => {
    im = {};
    ((await api('/api/projects/company/' + cur + '/interest')).interest).forEach(i => im[i.project_id] = i);
    
    const st = $('#st').value;
    let u = '/api/projects/company/' + cur;
    if (q) u += '/search?q=' + encodeURIComponent(q) + (st ? '&status=' + st : '');
    else if (st) u += '?status=' + st;
    
    L = (await api(u)).projects;
    if (L.length === 0) {
      $('#tb').innerHTML = '<p class="error">❌ No projects exist.</p>';
    } else {
      $('#tb').innerHTML = rows();
    }
  };

  $('#sel').onchange = e => { cur = e.target.value; reload(); };
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; $('#st').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Delete Project #' + di + '?')) {
      try {
        await api('/api/projects/' + di, { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  
  await reload();
}

async function projectsCreate() {
  let CS = (await api('/api/companies')).companies;
  if (!CS.length) return shell('<h1>Error</h1><p class="error">❌ Please create a company first.</p><a href="#/companies" class="btn btn-secondary">Back</a>');

  shell(`
    <h1>Create Project</h1>
    <form id="f">
      <div class="form-group">
        <label>Company</label>
        <select name="company_id">${CS.map(c => `<option value="${c.company_id}">${esc(c.company_name)}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label>Title</label><input name="title" required/></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2" required></textarea></div>
      <div class="form-group">
        <label>Status</label>
        <select name="status"><option>Ongoing</option><option>Completed</option></select>
      </div>
      <div class="form-group"><label>Image URL</label><input name="image_url"/></div>
      <p id="m" class="error"></p>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Save Project</button> 
        <a href="#/projects" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  `);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    b.company_id = +b.company_id;
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/projects', { method: 'POST', body: JSON.stringify(b) });
      window.location.hash = '#/projects';
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function projectsView(id) {
  try {
    const r = await api('/api/projects/' + id);
    shell(`
      <h1>View Project</h1>
      <div style="padding: 1rem; background: #f8fafc; border-radius: 6px;">
        <p><strong>Title:</strong> ${esc(r.project.title)}</p>
        <p><strong>Description:</strong> ${esc(r.project.description || 'N/A')}</p>
        <p><strong>Status:</strong> ${esc(r.project.status)}</p>
        <p><strong>Image URL:</strong> ${esc(r.project.image_url || 'N/A')}</p>
      </div>
      <div class="actions" style="margin-top: 1rem;">
        <a href="#/projects" class="btn btn-secondary">Back to List</a>
      </div>
    `);
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/projects" class="btn btn-secondary">Back</a>`); }
}

async function projectsEdit(id) {
  try {
    const r = await api('/api/projects/' + id);
    shell(`
      <h1>Edit Project: ${esc(r.project.title)}</h1>
      <form id="f">
        <div class="form-group"><label>Title</label><input name="title" value="${esc(r.project.title)}" required/></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2" required>${esc(r.project.description || '')}</textarea></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="Ongoing" ${r.project.status === 'Ongoing' ? 'selected' : ''}>Ongoing</option>
            <option value="Completed" ${r.project.status === 'Completed' ? 'selected' : ''}>Completed</option>
          </select>
        </div>
        <div class="form-group"><label>Image URL</label><input name="image_url" value="${esc(r.project.image_url || '')}"/></div>
        <p id="m" class="error"></p>
        <div class="actions">
          <button type="submit" class="btn btn-primary">Save Changes</button> 
          <a href="#/projects" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    `);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/projects/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/projects';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/projects" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 7. CUSTOMER BROWSE (US #30 - #32) - Customer Only
// =============================================================================
async function browseList() {
  shell(`
    <h1>Browse Projects</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search title or description" style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button> 
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    const r = await api('/api/projects/public/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
    $('#tb').innerHTML = tbl(['project_id', 'title', 'company_name', 'status'], r.projects, p => `
      <div class="actions">
        <a href="#/browse/view/${p.project_id}" class="btn btn-primary">View</a>
        <button class="btn btn-success" data-s2="${p.project_id}">Save</button>
      </div>
    `);
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const si = e.target.getAttribute('data-s2');
    if (si) {
      try {
        await api('/api/favourites', { method: 'POST', body: JSON.stringify({ project_id: +si }) });
        alert(`✅ Saved project #${si} to favourites!`);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function browseView(id) {
  try {
    const r = await api('/api/projects/public/' + id);
    shell(`
      <h1>Project Details</h1>
      <div style="padding: 1rem; background: #f8fafc; border-radius: 6px;">
        <p><strong>Title:</strong> ${esc(r.project.title)}</p>
        <p><strong>Description:</strong> ${esc(r.project.description || 'N/A')}</p>
        <p><strong>Status:</strong> ${esc(r.project.status)}</p>
      </div>
      <div class="actions" style="margin-top: 1rem;">
        <a href="#/browse" class="btn btn-secondary">Back to Browse</a>
      </div>
    `);
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/browse" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 8. CUSTOMER FAVOURITES (US #33 - #34) - Customer Only
// =============================================================================
async function favouritesList() {
  shell(`
    <h1>Favourite List</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search saved projects..." style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button> 
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    const r = q ? await api('/api/favourites/search?q=' + encodeURIComponent(q)) : await api('/api/favourites');
    $('#tb').innerHTML = tbl(['project_id', 'title', 'status', 'saved_at'], r.favourites, f => `
      <div class="actions">
        <button class="btn btn-danger" data-x="${f.project_id}">Remove</button>
      </div>
    `);
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const id = e.target.getAttribute('data-x'); if (!id) return;
    try {
      await api('/api/favourites/' + id, { method: 'DELETE' });
      reload($('#q').value);
    } catch (ex) { alert(ex.message); }
  };
  await reload();
}

// =============================================================================
// 9. CATEGORIES (US #41 - #45) - Platform Manager Only
// =============================================================================
async function categoriesList() {
  let L = [];
  shell(`
    <h1>IDP Categories</h1>
    <div class="actions" style="margin-bottom: 1rem;">
      <a href="#/categories/create" class="btn btn-primary">+ Create Category</a>
    </div>
    <div class="actions" style="margin-bottom: 1rem;">
      <input id="q" placeholder="Search categories..." style="flex:1; margin:0;" />
      <button class="btn btn-primary" id="s">Search</button>
      <button class="btn btn-secondary" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);

  const reload = async q => {
    L = (q ? (await api('/api/categories/search?q=' + encodeURIComponent(q))) : (await api('/api/categories'))).categories;
    $('#tb').innerHTML = tbl(['category_id', 'category_name', 'description'], L, c => `
      <div class="actions">
        <a href="#/categories/edit/${c.category_id}" class="btn btn-secondary">Edit</a> 
        <button class="btn btn-danger" data-d="${c.category_id}">Delete</button>
      </div>
    `);
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Delete Category #' + di + '?')) {
      try {
        await api('/api/categories/' + di, { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function categoriesCreate() {
  shell(`
    <h1>Create Category</h1>
    <form id="f">
      <div class="form-group"><label>Name</label><input name="category_name" required/></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
      <p id="m" class="error"></p>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Save Category</button> 
        <a href="#/categories" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  `);

  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    showMsg('m', 'Saving...', false);
    try {
      await api('/api/categories', { method: 'POST', body: JSON.stringify(b) });
      window.location.hash = '#/categories';
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function categoriesEdit(id) {
  try {
    const r = await api('/api/categories/' + id);
    shell(`
      <h1>Edit Category: ${esc(r.category.category_name)}</h1>
      <form id="f">
        <div class="form-group"><label>Name</label><input name="category_name" value="${esc(r.category.category_name)}" required/></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2">${esc(r.category.description || '')}</textarea></div>
        <p id="m" class="error"></p>
        <div class="actions">
          <button type="submit" class="btn btn-primary">Save Changes</button> 
          <a href="#/categories" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    `);

    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/categories/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/categories';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<h1>Error</h1><p class="error">❌ ${ex.message}</p><a href="#/categories" class="btn btn-secondary">Back</a>`); }
}

// =============================================================================
// 10. REPORTS (US #46 - #48) - Platform Manager Only
// =============================================================================
async function reports() {
  shell(`
    <h1>Platform Reports</h1>
    <div class="actions" style="margin-bottom: 1.5rem;">
      <button class="btn btn-primary" data-p="daily">Daily Report</button> 
      <button class="btn btn-primary" data-p="weekly">Weekly Report</button> 
      <button class="btn btn-primary" data-p="monthly">Monthly Report</button>
    </div>
    
    <h2 id="t">Select a report period above</h2>
    <p id="m" class="error"></p>
    <div id="sm" style="background: #f8fafc; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;"></div>
    
    <h2>Top Performing Projects</h2>
    <table>
      <thead><tr><th>Project</th><th>Views</th></tr></thead>
      <tbody id="tb"></tbody>
    </table>
  `);
  
  app().onclick = async e => {
    const p = e.target.getAttribute('data-p'); if (!p) return;
    showMsg('m', 'Generating report...', false);
    try {
      const r = await api('/api/reports/' + p);
      $('#t').textContent = p.toUpperCase() + ' Performance Report';
      
      $('#sm').innerHTML = `
        <div style="display:flex; gap:2rem; flex-wrap:wrap;">
          <div><span style="color:var(--text-muted); font-size:0.9rem;">New Users</span><br><strong style="font-size:1.5rem;">${r.summary.new_users}</strong></div>
          <div><span style="color:var(--text-muted); font-size:0.9rem;">Project Views</span><br><strong style="font-size:1.5rem;">${r.summary.project_views}</strong></div>
          <div><span style="color:var(--text-muted); font-size:0.9rem;">Shortlists</span><br><strong style="font-size:1.5rem;">${r.summary.shortlists}</strong></div>
          <div><span style="color:var(--text-muted); font-size:0.9rem;">New Projects</span><br><strong style="font-size:1.5rem;">${r.summary.new_projects}</strong></div>
        </div>
      `;
      
      $('#tb').innerHTML = r.topProjects.map(x => `<tr><td>${esc(x.title)}</td><td>${x.views}</td></tr>`).join('');
      showMsg('m', '✅ Report generated successfully!', false);
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

// =============================================================================
// 11. DIRECTORY ROUTER
// =============================================================================
async function route() {
  const hash = (location.hash || '#/').replace(/^#\/?/, '');
  const parts = hash.split('/');
  const page = parts[0];
  const action = parts[1];
  const id = parts[2];

  try { window.__user = (await api('/api/auth/me')).user; } 
  catch (_) { return login(); }
  
  const r = window.__user.role;

  if (r === 'User Admin') {
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

  if (r === 'Interior Designer') {
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

  if (r === 'Customer') {
    if (page === 'browse') {
      if (action === 'view') return browseView(id);
      return browseList();
    }
    if (page === 'favourites') return favouritesList();
  }

  if (r === 'Platform Manager') {
    if (page === 'categories') {
      if (action === 'create') return categoriesCreate();
      if (action === 'edit') return categoriesEdit(id);
      return categoriesList();
    }
    if (page === 'reports') return reports();
  }

  return dashboard();
}

window.addEventListener('hashchange', route);
route();