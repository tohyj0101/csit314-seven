// =============================================================================
// CSIT314 T04: Seven - Frontend Application Logic (Boundary Layer)
// =============================================================================

// =============================================================================
// 1. GLOBAL UTILITIES & ERROR HANDLING
// =============================================================================
async function api(u, o = {}) {
  try {
    const r = await fetch(u, {
      credentials: 'include', // <--- FIX 1: Changed from 'same-origin' to 'include'
      headers: { 'Content-Type': 'application/json', ...(o.headers || {}) },
      method: o.method || 'GET',
      body: o.body
    });
    
    let b = null;
    try { b = await r.json(); } catch (_) { }
    
    if (!r.ok) {
      if (b && b.error) throw new Error(b.error);
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

// Fixed the esc() function syntax and HTML entity mapping
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtDate = d => d ? new Date(d).toLocaleString('en-SG', { timeZone: 'Asia/Singapore', hour12: true }) : 'N/A';
const $ = s => document.querySelector(s), app = () => $('#app');

function debounce(func, delay) {
  let timeout;
  return function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), delay);
  };
}

function showMsg(id, text, isError) {
  const el = $('#' + id);
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#dc2626' : '#16a34a';
  el.style.fontWeight = 'bold';
}

const pageHeader = (title, desc) => `
  <div class="page-header">
    <h1>${title}</h1>
    <p>${desc}</p>
  </div>
`;

const shell = h => {
  const u = window.__user, links = [['#/', 'Dashboard']];
  if (u.role === 'User Admin') links.push(['#/admin', 'Users'], ['#/profiles', 'Profiles']);
  if (u.role === 'Interior Designer') links.push(['#/companies', 'Companies'], ['#/projects', 'Projects']);
  if (u.role === 'Customer') links.push(['#/browse', 'Browse'], ['#/favourites', 'Favourites']);
  if (u.role === 'Platform Manager') links.push(['#/categories', 'Categories'], ['#/reports', 'Reports']);
  
  app().innerHTML = `<nav> ${links.map(([h, l]) => `<a href="${h}">${l}</a>`).join('')} <button id="lo">Logout</button> </nav> <main class="card">${h}</main>`;
  
  $('#lo').onclick = async () => {
    await api('/api/auth/logout', { method: 'POST' });
    window.__user = null;
    route();
  };
}

// =============================================================================
// 2. AUTHENTICATION & DASHBOARD
// =============================================================================
function login() {
  app().innerHTML = `<main class="card" style="max-width:480px; margin-top:100px;"> ${pageHeader('Sign In', 'Enter your credentials to access the internal dashboard based on your assigned Role-Based Access Control (RBAC) profile.')} <form id="f"> <div class="form-group"><label>Email</label><input name="email" type="email" required /></div> <div class="form-group"><label>Password</label><input name="password" type="password" required /></div> <p id="m" class="error"></p> <button type="submit" class="btn btn-elegant" style="width:100%;">Login</button> </form> </main>`;
  
  $('#f').onsubmit = async e => {
    e.preventDefault();
    showMsg('m', 'Logging in...', false);
    const data = Object.fromEntries(new FormData(e.target));
    
    try {
      // <--- FIX 2: Capture response, set global user, force route, and conditionally update hash
      const response = await api('/api/auth/login', { method: 'POST', body: JSON.stringify(data) });
      window.__user = response.user;
      route(); // Force UI to redraw immediately
      
      if (window.location.hash !== '#/') {
        window.location.hash = '#/';
      }
    } catch (ex) {
      showMsg('m', '❌ ' + ex.message, true);
    }
  };
}

async function dashboard() {
  const role = window.__user.role;
  const name = esc(window.__user.username);
  let content = '';
  
  if (role === 'User Admin') {
    content = `${pageHeader(`Welcome, ${name}`, 'User Administration Dashboard')} <div class="data-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));"> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">User Accounts</h3> <p class="meta-text" style="margin-bottom: 2rem;">Manage platform users, reset credentials, and toggle account access.</p> <a href="#/admin" class="btn btn-elegant" style="width: 100%;">Manage Users</a> </div> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">RBAC Profiles</h3> <p class="meta-text" style="margin-bottom: 2rem;">Configure system roles and assign granular module permissions.</p> <a href="#/profiles" class="btn btn-elegant" style="width: 100%;">Manage Profiles</a> </div> </div>`;
  } else if (role === 'Interior Designer') {
    content = `${pageHeader(`Welcome, ${name}`, 'Designer Workspace')} <div class="data-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));"> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Portfolio Projects</h3> <p class="meta-text" style="margin-bottom: 2rem;">Upload new designs, track project status, and view engagement metrics.</p> <a href="#/projects" class="btn btn-elegant" style="width: 100%;">View Portfolio</a> </div> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Company Entities</h3> <p class="meta-text" style="margin-bottom: 2rem;">Update your registered business details and contact information.</p> <a href="#/companies" class="btn btn-elegant" style="width: 100%;">Manage Companies</a> </div> </div>`;
  } else if (role === 'Customer') {
    content = `${pageHeader(`Welcome, ${name}`, 'Your Inspiration Hub')} <div class="data-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));"> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Discovery Gallery</h3> <p class="meta-text" style="margin-bottom: 2rem;">Explore our curated collection of premium interior designs.</p> <a href="#/browse" class="btn btn-elegant" style="width: 100%;">Browse Designs</a> </div> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">My Favourites</h3> <p class="meta-text" style="margin-bottom: 2rem;">Review the rooms and projects you have saved to your mood board.</p> <a href="#/favourites" class="btn btn-elegant" style="width: 100%;">View Shortlists</a> </div> </div>`;
  } else if (role === 'Platform Manager') {
    content = `${pageHeader(`Welcome, ${name}`, 'Platform Management Dashboard')} <div class="data-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));"> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Executive Reports</h3> <p class="meta-text" style="margin-bottom: 2rem;">Generate traffic, engagement, and user acquisition analytics.</p> <a href="#/reports" class="btn btn-elegant" style="width: 100%;">View Analytics</a> </div> <div class="data-card" style="padding: 2.5rem 2rem; text-align: center;"> <h3 style="margin-bottom: 1rem; font-size: 1.5rem;">Taxonomy</h3> <p class="meta-text" style="margin-bottom: 2rem;">Maintain the standard classification tags used across the platform.</p> <a href="#/categories" class="btn btn-elegant" style="width: 100%;">Manage Categories</a> </div> </div>`;
  } else {
    content = pageHeader(`Welcome, ${name}`, `You are logged in as a <strong>${esc(role)}</strong>. Use the top navigation bar to access your modules.`);
  }
  shell(content);
}

// =============================================================================
// 3. USER ACCOUNTS (Admin Only)
// =============================================================================
async function adminList() {
  shell(`${pageHeader('User Account Management', 'Manage platform users, roles, and account statuses.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/admin/create" class="btn btn-elegant">+ Create New User</a> </div> <div class="search-bar"> <input id="q" placeholder="Search by username or email..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);
  
  const reload = async q => {
    try {
      const u = (q ? await api('/api/users/search?q=' + encodeURIComponent(q)) : await api('/api/users')).users;
      if (u.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No users found.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + u.map(x => `<div class="data-card"> <div class="data-info"> <div class="data-header"> <h3>${esc(x.username)}</h3> <span class="badge ${x.status === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(x.status)}</span> </div> <p class="meta-text"><strong>Role:</strong> ${esc(x.profile_name)}</p> <p class="meta-text"><strong>Email:</strong> ${esc(x.email)}</p> <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;"> <a href="#/admin/view/${x.account_id}" class="btn btn-outline" style="flex:1">View</a> <a href="#/admin/edit/${x.account_id}" class="btn btn-outline" style="flex:1">Edit</a> <button class="btn btn-outline" data-s="${x.account_id}" data-st="${x.status}" title="${x.status === 'Active' ? 'Suspend' : 'Activate'}"> ${x.status === 'Active' ? '🛑' : '✅'} </button> </div> </div> </div>`).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-s]');
    if (btn) {
      const s = btn.getAttribute('data-s'), st = btn.getAttribute('data-st');
      if (!confirm(`Are you sure you want to ${st === 'Active' ? 'suspend' : 'activate'} this user?`)) return;
      try {
        await api('/api/users/' + s + '/' + (st === 'Active' ? 'suspend' : 'activate'), { method: 'PATCH' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function adminCreate() {
  let P = (await api('/api/profiles')).profiles;
  shell(`${pageHeader('Create User Account', 'Provision a new system user and assign their RBAC profile.')} <form id="cf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Username</label><input name="username" required /></div> <div class="form-group"><label>Email Address</label><input name="email" type="email" required /></div> <div class="form-group"><label>Temporary Password</label><input name="password" type="password" required /></div> <div class="form-group"> <label>Assign Profile Role</label> <select name="profile_id">${P.map(p => `<option value="${p.profile_id}">${esc(p.profile_name)}</option>`).join('')}</select> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Create Account</button> <a href="#/admin" class="btn btn-outline">Cancel</a> </div> </form>`);
  
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
    shell(`${pageHeader('User Details', 'Viewing immutable system record for ' + esc(r.user.username))} <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;"> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>System ID:</strong> #${r.user.account_id}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Email:</strong> ${esc(r.user.email)}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Assigned Role:</strong> ${esc(r.user.profile_name)}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Status:</strong> <span class="badge ${r.user.status === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(r.user.status)}</span></p> <div class="actions" style="margin-top: 2rem; justify-content:center;"> <a href="#/admin" class="btn btn-outline">Return to Directory</a> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p><a href="#/admin" class="btn btn-outline">Back</a>`); }
}

async function adminEdit(id) {
  try {
    const r = await api('/api/users/' + id);
    const P = (await api('/api/profiles')).profiles;
    shell(`${pageHeader('Edit User Account', 'Modify credentials and role assignments for ' + esc(r.user.username))} <form id="cf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Username</label><input name="username" value="${esc(r.user.username)}" required /></div> <div class="form-group"><label>Email</label><input name="email" type="email" value="${esc(r.user.email)}" required /></div> <div class="form-group"><label>Reset Password (leave blank to keep current)</label><input name="password" type="password" placeholder="New Password..." /></div> <div class="form-group"> <label>Assign Profile Role</label> <select name="profile_id"> ${P.map(p => `<option value="${p.profile_id}" ${p.profile_id === r.user.profile_id ? 'selected' : ''}>${esc(p.profile_name)}</option>`).join('')} </select> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Commit Changes</button> <a href="#/admin" class="btn btn-outline">Cancel</a> </div> </form>`);
    
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
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 4. USER PROFILES (Admin Only)
// =============================================================================
async function profilesList() {
  shell(`${pageHeader('RBAC Profiles', 'Manage platform roles and their granular system permissions.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/profiles/create" class="btn btn-elegant">+ Create New Profile</a> </div> <div class="search-bar"> <input id="q" placeholder="Search profile titles..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);
  
  const reload = async (q) => {
    try {
      const L = (q ? await api('/api/profiles/search?q=' + encodeURIComponent(q)) : await api('/api/profiles')).profiles;
      if (L.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No profiles found.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + L.map(p => `<div class="data-card"> <div class="data-info"> <div class="data-header"> <h3>${esc(p.profile_name)}</h3> <span class="badge ${(p.status || 'Active') === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(p.status || 'Active')}</span> </div> <p class="meta-text"><strong>ID:</strong> #${p.profile_id}</p> <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;"> <a href="#/profiles/view/${p.profile_id}" class="btn btn-outline" style="flex:1">View</a> <a href="#/profiles/edit/${p.profile_id}" class="btn btn-outline" style="flex:1">Edit</a> <button class="btn btn-outline" data-s="${p.profile_id}" data-st="${p.status || 'Active'}" title="${(p.status || 'Active') === 'Active' ? 'Suspend' : 'Activate'}"> ${(p.status || 'Active') === 'Active' ? '🛑' : '✅'} </button> </div> </div> </div>`).join('') + `</div>`;
      }
    } catch(ex) { alert(ex.message); }
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
        reload($('#q').value);
      } catch(ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function profilesCreate() {
  const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
  const checkboxes = perms.map(p => `<label style="display:inline-flex; align-items:center; margin-right: 1.5rem; text-transform:none; font-weight:normal;"><input type="checkbox" name="permissions" value="${p}" style="width:auto; margin-right:8px;"> ${p}</label>`).join('');
  
  shell(`${pageHeader('Define RBAC Profile', 'Create a new role structure and attach system permissions.')} <form id="pf" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Profile Designation (Name)</label><input name="profile_name" required placeholder="e.g. Senior Designer" /></div> <div class="form-group"> <label>System Permissions Grants</label> <div style="display:flex; flex-wrap:wrap; gap:0.5rem; background:#fff; padding:1.5rem; border:1px solid #ddd; border-radius:4px;"> ${checkboxes} </div> </div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Create Profile</button> <a href="#/profiles" class="btn btn-outline">Cancel</a> </div> </form>`);
  
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
    shell(`${pageHeader('Profile Access Blueprint', 'Reviewing system grants for ' + esc(r.profile.profile_name))} <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;"> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>ID:</strong> #${r.profile.profile_id}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Designation:</strong> ${esc(r.profile.profile_name)}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Status:</strong> <span class="badge ${(r.profile.status || 'Active') === 'Active' ? 'badge-success' : 'badge-danger'}">${esc(r.profile.status || 'Active')}</span></p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>System Grants:</strong><br><br> ${(r.profile.permissions || []).map(p => `<span class="badge badge-neutral" style="margin:4px;">${p}</span>`).join('') || 'None Assigned'}</p> <div class="actions" style="margin-top: 2rem; justify-content:center;"> <a href="#/profiles" class="btn btn-outline">Return to Directory</a> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p><a href="#/profiles" class="btn btn-outline">Back</a>`); }
}

async function profilesEdit(id) {
  try {
    const r = await api('/api/profiles/' + id);
    const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
    const checkboxes = perms.map(p => {
      const checked = (r.profile.permissions || []).includes(p) ? 'checked' : '';
      return `<label style="display:inline-flex; align-items:center; margin-right: 1.5rem; text-transform:none; font-weight:normal;"><input type="checkbox" name="permissions" value="${p}" ${checked} style="width:auto; margin-right:8px;"> ${p}</label>`;
    }).join('');
    
    shell(`
       ${pageHeader('Edit Profile Blueprint', 'Modify role designation or attached permissions.')}
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
           <button type="submit" class="btn btn-elegant">Commit Changes</button>
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
         window.location.hash = '#/profiles';
       } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
     };
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 5. COMPANY INFORMATION (Designer Only)
// =============================================================================
async function companiesList() {
  shell(`${pageHeader('Company Directory', 'Manage your associated business entities before attaching projects.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/companies/create" class="btn btn-elegant">+ Register Company</a> </div> <div class="search-bar"> <input id="q" placeholder="Search business names or locations..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);
  
  const reload = async q => {
    try {
      const L = (q ? await api('/api/companies/search?q=' + encodeURIComponent(q)) : await api('/api/companies')).companies;
      if (L.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No companies registered yet.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + L.map(c => `
          <div class="data-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom:1rem; font-size: 1.5rem; border-bottom: 1px solid #eee; padding-bottom:1rem;">${esc(c.company_name)}</h3>
            <p class="meta-text" style="display:flex; align-items:flex-start; gap:8px;"><strong>📍</strong> <span>${esc(c.address || 'Address Unlisted')}</span></p>
            <p class="meta-text" style="display:flex; align-items:center; gap:8px;"><strong>📞</strong> <span>${esc(c.contact_no || 'No Contact')}</span></p>
            <div class="actions" style="margin-top:auto; padding-top:1rem;">
              <a href="#/companies/view/${c.company_id}" class="btn btn-outline" style="flex:1">View</a>
              <a href="#/companies/edit/${c.company_id}" class="btn btn-outline" style="flex:1">Edit</a>
              <button class="btn btn-danger" data-d="${c.company_id}" style="border:none;">Delete</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Permanently remove Company #' + di + '? All attached projects will fail if restricted.')) {
      try {
        await api('/api/companies/' + di, { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function companiesCreate() {
  shell(`${pageHeader('Register Company', 'Enter your corporate details to associate future interior design projects.')} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Registered Business Name</label><input name="company_name" required/></div> <div class="form-group"><label>HQ Address</label><input name="address"/></div> <div class="form-group"><label>Contact Hotline</label><input name="contact_no"/></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Save Business Profile</button> <a href="#/companies" class="btn btn-outline">Cancel</a> </div> </form>`);
  
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
    shell(`${pageHeader('Company Record', esc(r.company.company_name))} <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;"> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.company.company_id}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>HQ Address:</strong> ${esc(r.company.address || 'Unlisted')}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Contact:</strong> ${esc(r.company.contact_no || 'Unlisted')}</p> <div class="actions" style="margin-top: 2rem; justify-content:center;"> <a href="#/companies" class="btn btn-outline">Return to Directory</a> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

async function companiesEdit(id) {
  try {
    const r = await api('/api/companies/' + id);
    shell(`${pageHeader('Edit Company', 'Updating details for ' + esc(r.company.company_name))} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Company name</label><input name="company_name" value="${esc(r.company.company_name)}" required/></div> <div class="form-group"><label>Address</label><input name="address" value="${esc(r.company.address || '')}"/></div> <div class="form-group"><label>Contact</label><input name="contact_no" value="${esc(r.company.contact_no || '')}"/></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Commit Changes</button> <a href="#/companies" class="btn btn-outline">Cancel</a> </div> </form>`);
    
    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/companies/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/companies';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 6. PROJECTS (Designer Only)
// =============================================================================
async function projectsList() {
  let CS = (await api('/api/companies')).companies;
  if (!CS.length) return shell(pageHeader('Project Workspace', '❌ Please register a company first.') + `<div style="text-align:center;"><a href="#/companies/create" class="btn btn-elegant">Register Company</a></div>`);
  
  if (!window._activeCompany || !CS.find(c => c.company_id == window._activeCompany)) {
    window._activeCompany = CS[0].company_id;
  }
  
  let cur = window._activeCompany, L = [], im = {};
  shell(`
    ${pageHeader('Project Workspace & Analytics', 'Track your portfolio and monitor audience engagement metrics (Views/Saves).')}
    <div style="max-width:800px; margin:0 auto; background: #fff; padding: 1.5rem; border-radius:8px; border:1px solid #eee; margin-bottom: 2rem; display:flex; gap: 1rem; align-items:flex-end;">
      <div style="flex:1;">
        <label style="font-size:0.8rem;">Operating Entity</label>
        <select id="sel">${CS.map(c => `<option value="${c.company_id}" ${c.company_id == cur ? 'selected' : ''}>${esc(c.company_name)}</option>`).join('')}</select>
      </div>
      <a href="#/projects/create" class="btn btn-elegant">+ Publish Project</a>
    </div>
    <div class="search-bar">
      <input id="q" placeholder="Search portfolio..." style="flex:1;" />
      <select id="st" style="width:180px; border-radius:30px;"><option value="">All Statuses</option><option>Ongoing</option><option>Completed</option></select>
      <button class="btn btn-elegant" id="s">Search</button>
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);
  
  const reload = async q => {
    try {
      im = {};
      const interestReq = await api('/api/projects/company/' + cur + '/interest');
      (interestReq.interest || []).forEach(i => im[i.project_id] = i);
      
      const st = $('#st').value;
      let u = '/api/projects/company/' + cur;
      if (q) u += '/search?q=' + encodeURIComponent(q) + (st ? '&status=' + st : '');
      else if (st) u += '?status=' + st;
      
      L = (await api(u)).projects;
      if (L.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ Portfolio is empty for this criteria.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + L.map(p => {
          const i = im[p.project_id] || { views: 0, shortlists: 0 };
          return `
            <div class="data-card">
              <img src="${p.image_url || 'https://via.placeholder.com/600x400?text=No+Cover+Image'}" alt="${esc(p.title)}">
              <div class="data-info">
                <div class="data-header">
                  <h3>${esc(p.title)}</h3>
                  <span class="badge ${p.status === 'Completed' ? 'badge-neutral' : 'badge-success'}">${esc(p.status)}</span>
                </div>
                <div class="metrics-row">
                  <div class="metric-item">👁️ ${i.views} Views</div>
                  <div class="metric-item">⭐ ${i.shortlists} Saves</div>
                </div>
                <div class="actions" style="margin-top:auto;">
                  <a href="#/projects/view/${p.project_id}" class="btn btn-outline" style="flex:1">View</a>
                  <a href="#/projects/edit/${p.project_id}" class="btn btn-outline" style="flex:1">Edit</a>
                  <button class="btn btn-danger" data-d="${p.project_id}" style="border:none;">Delete</button>
                </div>
              </div>
            </div>
          `;
        }).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#sel').onchange = e => {
    cur = e.target.value;
    window._activeCompany = cur;
    reload();
  };
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; $('#st').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Permanently remove Project #' + di + '?')) {
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
  if (!CS.length) return shell(pageHeader('Publish Project', '❌ Missing company requirement.') + `<a href="#/companies" class="btn btn-outline">Back</a>`);
  
  shell(`${pageHeader('Publish Project', 'Add a new showcase to your portfolio.')} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"> <label>Governing Company</label> <select name="company_id">${CS.map(c => `<option value="${c.company_id}">${esc(c.company_name)}</option>`).join('')}</select> </div> <div class="form-group"><label>Project Title</label><input name="title" required/></div> <div class="form-group"><label>Design Narrative</label><textarea name="description" rows="4" required></textarea></div> <div class="form-group"> <label>Phase Status</label> <select name="status"><option>Ongoing</option><option>Completed</option></select> </div> <div class="form-group"><label>Hero Image URL</label><input name="image_url" placeholder="https://..."/></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Publish Showcase</button> <a href="#/projects" class="btn btn-outline">Cancel</a> </div> </form>`);
  
  $('#f').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    b.company_id = +b.company_id;
    showMsg('m', 'Publishing...', false);
    try {
      await api('/api/projects', { method: 'POST', body: JSON.stringify(b) });
      window.location.hash = '#/projects';
    } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
}

async function projectsView(id) {
  try {
    const r = await api('/api/projects/' + id);
    shell(`${pageHeader('Project Profile', esc(r.project.title))} <div style="max-width: 800px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; overflow:hidden;"> <img src="${r.project.image_url || 'https://via.placeholder.com/800x400?text=No+Cover+Image'}" style="width:100%; height:300px; object-fit:cover; border-bottom:1px solid #eee;"> <div style="padding: 2rem;"> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><span class="badge ${r.project.status === 'Completed' ? 'badge-neutral' : 'badge-success'}">${esc(r.project.status)}</span></p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem; white-space:pre-line;">${esc(r.project.description || 'No narrative provided.')}</p> <div class="actions" style="margin-top: 2rem; justify-content:center;"> <a href="#/projects" class="btn btn-outline">Return to Portfolio</a> </div> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

async function projectsEdit(id) {
  try {
    const r = await api('/api/projects/' + id);
    shell(`${pageHeader('Revise Showcase', 'Updating parameters for ' + esc(r.project.title))} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Project Title</label><input name="title" value="${esc(r.project.title)}" required/></div> <div class="form-group"><label>Design Narrative</label><textarea name="description" rows="4" required>${esc(r.project.description || '')}</textarea></div> <div class="form-group"> <label>Phase Status</label> <select name="status"> <option value="Ongoing" ${r.project.status === 'Ongoing' ? 'selected' : ''}>Ongoing</option> <option value="Completed" ${r.project.status === 'Completed' ? 'selected' : ''}>Completed</option> </select> </div> <div class="form-group"><label>Hero Image URL</label><input name="image_url" value="${esc(r.project.image_url || '')}"/></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Commit Revisions</button> <a href="#/projects" class="btn btn-outline">Cancel</a> </div> </form>`);
    
    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/projects/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/projects';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 7. CUSTOMER BROWSE (Customer Only)
// =============================================================================
async function browseList() {
  shell(`
    ${pageHeader('Discovery Gallery', 'Discover visually striking interior design projects and save your inspirations.')}
    <div class="search-bar">
      <input id="q" placeholder="Search styles, spaces, or designers..." style="flex:1;" />
      <button class="btn btn-elegant" id="s">Search</button> 
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);
  
  const reload = async q => {
    try {
      const r = await api('/api/projects/public/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
      if (r.projects.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No projects discovered matching criteria.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + r.projects.map(p => `
          <div class="data-card">
            <img src="${p.image_url || 'https://via.placeholder.com/600x400?text=Premium+Design'}" alt="${esc(p.title)}">
            <div class="data-info">
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
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-s2]');
    if (btn) {
      try {
        await api('/api/favourites', { method: 'POST', body: JSON.stringify({ project_id: +btn.getAttribute('data-s2') }) });
        alert(`✅ Saved to your personal inspiration gallery!`);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function browseView(id) {
  try {
    const r = await api('/api/projects/public/' + id);
    shell(`${pageHeader('Room Exploration', esc(r.project.title))} <div style="max-width: 900px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; overflow:hidden;"> <img src="${r.project.image_url || 'https://via.placeholder.com/900x500?text=Room+Preview'}" style="width:100%; height:400px; object-fit:cover; border-bottom:1px solid #eee;"> <div style="padding: 3rem;"> <p class="meta-text" style="font-size:1.2rem; margin-bottom:2rem; line-height:1.8; color:#444;">${esc(r.project.description || 'No conceptual narrative provided by designer.')}</p> <div class="actions" style="justify-content:center;"> <a href="#/browse" class="btn btn-outline">Back to Gallery</a> </div> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 8. CUSTOMER FAVOURITES (Customer Only)
// =============================================================================
async function favouritesList() {
  shell(`
    ${pageHeader('My Shortlists (Favourites)', 'Curate a personalized mood board of saved interior designs.')}
    <div class="search-bar">
      <input id="q" placeholder="Filter inspirations..." style="flex:1;" />
      <button class="btn btn-elegant" id="s">Search</button> 
      <button class="btn btn-outline" id="r">Reset</button>
    </div>
    <div id="tb"></div>
  `);
  
  const reload = async q => {
    try {
      const r = q ? await api('/api/favourites/search?q=' + encodeURIComponent(q)) : await api('/api/favourites');
      if (r.favourites.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ Your inspiration board is currently empty.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + r.favourites.map(f => `
          <div class="data-card">
            <img src="${f.image_url || 'https://via.placeholder.com/600x400?text=Inspiration'}" alt="${esc(f.title)}">
            <div class="data-info">
              <div class="data-header">
                <h3>${esc(f.title)}</h3>
              </div>
              <p class="meta-text" style="font-size:0.8rem; margin-bottom:1rem;">Added: ${fmtDate(f.saved_at)}</p>
              <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #f0f0f0;">
                <a href="#/browse/view/${f.project_id}" class="btn btn-elegant" style="flex: 1;">View Again</a>
                <button class="btn btn-danger" data-x="${f.project_id}" style="border:none;">Remove</button>
              </div>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const btn = e.target.closest('[data-x]');
    if (btn) {
      try {
        await api('/api/favourites/' + btn.getAttribute('data-x'), { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

// =============================================================================
// 9. CATEGORIES (Platform Manager Only)
// =============================================================================
async function categoriesList() {
  shell(`${pageHeader('Taxonomy Management', 'Curate standard design categories ensuring systemic consistency.')} <div class="actions" style="justify-content: center; margin-bottom: 2rem;"> <a href="#/categories/create" class="btn btn-elegant">+ Define Category</a> </div> <div class="search-bar"> <input id="q" placeholder="Search taxonomies..." style="flex:1;" /> <button class="btn btn-elegant" id="s">Search</button> <button class="btn btn-outline" id="r">Reset</button> </div> <div id="tb"></div>`);
  
  const reload = async q => {
    try {
      const L = (q ? (await api('/api/categories/search?q=' + encodeURIComponent(q))) : (await api('/api/categories'))).categories;
      if (L.length === 0) {
        $('#tb').innerHTML = '<p class="error">❌ No classification categories found.</p>';
      } else {
        $('#tb').innerHTML = `<div class="data-grid">` + L.map(c => `
          <div class="data-card" style="padding: 1.5rem;">
            <div class="data-header">
              <h3 style="margin-bottom:1rem; font-size: 1.5rem;">${esc(c.category_name)}</h3>
            </div>
            <p class="meta-text" style="margin-bottom: 1.5rem;">${esc(c.description || 'No defining description provided.')}</p>
            <div class="actions" style="margin-top:auto; padding-top:1rem; border-top:1px solid #eee;">
              <a href="#/categories/view/${c.category_id}" class="btn btn-outline" style="flex:1">View</a>
              <a href="#/categories/edit/${c.category_id}" class="btn btn-outline" style="flex:1">Edit</a> 
              <button class="btn btn-danger" data-d="${c.category_id}" style="border:none;">Delete</button>
            </div>
          </div>
        `).join('') + `</div>`;
      }
    } catch (ex) { alert(ex.message); }
  };
  
  $('#q').oninput = debounce(() => reload($('#q').value), 300);
  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const di = e.target.getAttribute('data-d');
    if (di && confirm('Eradicate Category #' + di + '?')) {
      try {
        await api('/api/categories/' + di, { method: 'DELETE' });
        reload($('#q').value);
      } catch (ex) { alert(ex.message); }
    }
  };
  await reload();
}

async function categoriesCreate() {
  shell(`${pageHeader('Define Taxonomy', 'Standardize a new structural category for portfolios.')} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Classification Title</label><input name="category_name" required/></div> <div class="form-group"><label>Architectural Description</label><textarea name="description" rows="4"></textarea></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Save Classification</button> <a href="#/categories" class="btn btn-outline">Cancel</a> </div> </form>`);
  
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

async function categoriesView(id) {
  try {
    const r = await api('/api/categories/' + id);
    shell(`${pageHeader('Taxonomy Rules', esc(r.category.category_name))} <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; border: 1px solid #f0f0f0;"> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem;"><strong>Registry ID:</strong> #${r.category.category_id}</p> <p class="meta-text" style="font-size:1.1rem; margin-bottom:1rem; white-space:pre-line;"><strong>Description:</strong><br>${esc(r.category.description || 'N/A')}</p> <div class="actions" style="margin-top: 2rem; justify-content:center;"> <a href="#/categories" class="btn btn-outline">Return to Taxonomy List</a> </div> </div>`);
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

async function categoriesEdit(id) {
  try {
    const r = await api('/api/categories/' + id);
    shell(`${pageHeader('Refine Taxonomy', 'Amending ' + esc(r.category.category_name))} <form id="f" style="max-width: 600px; margin: 0 auto;"> <div class="form-group"><label>Classification Title</label><input name="category_name" value="${esc(r.category.category_name)}" required/></div> <div class="form-group"><label>Architectural Description</label><textarea name="description" rows="4">${esc(r.category.description || '')}</textarea></div> <p id="m" class="error"></p> <div class="actions" style="justify-content: center; margin-top: 2rem;"> <button type="submit" class="btn btn-elegant">Apply Revisions</button> <a href="#/categories" class="btn btn-outline">Cancel</a> </div> </form>`);
    
    $('#f').onsubmit = async e => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(e.target));
      showMsg('m', 'Saving...', false);
      try {
        await api('/api/categories/' + id, { method: 'PUT', body: JSON.stringify(b) });
        window.location.hash = '#/categories';
      } catch (ex) { showMsg('m', '❌ ' + ex.message, true); }
    };
  } catch (ex) { shell(`<p class="error">❌ ${ex.message}</p>`); }
}

// =============================================================================
// 10. REPORTS (Platform Manager Only)
// =============================================================================
async function reports() {
  shell(`
    ${pageHeader('Executive Platform Analytics', 'Synthesize total ecosystem performance including acquisition & engagement.')}
    <div class="actions" style="justify-content: center; margin-bottom: 2rem;">
       <button class="btn btn-elegant" data-p="daily">Daily Pulse</button> 
       <button class="btn btn-elegant" data-p="weekly">Weekly Rollup</button> 
       <button class="btn btn-elegant" data-p="monthly">Monthly Synthesis</button>
     </div>
     <h2 id="t" style="text-align:center; font-weight:300; margin-bottom:2rem;">Awaiting Generation Parameters</h2>
     <p id="m" class="error"></p>
     <div id="sm"></div>
     <div id="tb-wrap" style="display:none; margin-top:3rem;">
       <h2 style="font-weight:400; font-size:1.5rem; margin-bottom:1rem;">Leaderboard: Traffic & Engagement</h2>
       <table>
         <thead>
           <tr>
             <th>Published Asset</th>
             <th>Operating Entity</th>
             <th>Traffic (Views)</th>
             <th>Conversions (Saves)</th>
           </tr>
         </thead>
         <tbody id="tb"></tbody>
       </table>
     </div>
  `);
  
  app().onclick = async e => {
    const p = e.target.getAttribute('data-p'); 
    if (!p) return;
    
    showMsg('m', 'Generating report...', false);
    try {
      const r = await api('/api/reports/' + p);
      $('#t').textContent = p.toUpperCase() + ' PERFORMANCE SYNTHESIS';
      
      $('#sm').innerHTML = `
         <div class="data-grid" style="grid-template-columns: repeat(4, 1fr); margin-top:0;">
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">User Acquisition</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.new_users}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">Traffic Volume</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.project_views}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">Lead Intent (Saves)</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.shortlists}</strong>
           </div>
           <div class="data-card" style="padding: 2rem; text-align:center; border-top: 4px solid var(--primary);">
             <span style="color:var(--text-muted); font-size:0.9rem; text-transform:uppercase; letter-spacing:1px;">Asset Velocity</span><br>
             <strong style="font-size:3rem; font-weight:300;">${r.summary.new_projects}</strong>
           </div>
         </div>
       `;
       
      $('#tb-wrap').style.display = 'block';
      $('#tb').innerHTML = r.topProjects.map(x => `
        <tr>
          <td style="font-weight:500;">${esc(x.title)}</td>
          <td style="color:var(--text-muted);">${esc(x.company_name)}</td>
          <td><span class="badge badge-neutral">👁️ ${x.views}</span></td>
          <td><span class="badge badge-success">⭐ ${x.shortlists}</span></td>
        </tr>
      `).join('');
      showMsg('m', '', false);
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
      if (action === 'view') return categoriesView(id);
      if (action === 'edit') return categoriesEdit(id);
      return categoriesList();
    }
    if (page === 'reports') return reports();
  }
  
  return dashboard();
}

window.addEventListener('hashchange', route);
route();