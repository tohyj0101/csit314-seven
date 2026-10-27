async function api(u,o={}){
  const r=await fetch(u,{credentials:'same-origin',headers:{'Content-Type':'application/json',...(o.headers||{})},method:o.method||'GET',body:o.body});
  let b=null;try{b=await r.json()}catch(_){}
  if(!r.ok)throw new Error((b&&b.error)||r.statusText);return b||{}
}
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const $=s=>document.querySelector(s),app=()=>$('#app');

function showMsg(id, text, isError) {
  const el = $('#' + id);
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#b91c1c' : '#15803d';
  el.style.fontWeight = 'bold';
}

const tbl=(cols,rows,act)=>`<table><thead><tr>${cols.map(c=>`<th>${c}</th>`).join('')}<th>Actions</th></tr></thead><tbody>${rows.map(r=>`<tr>${cols.map(c=>`<td>${esc(r[c])}</td>`).join('')}<td>${act(r)}</td></tr>`).join('')}</tbody></table>`;

const shell=h=>{
  const u=window.__user,links=[['#/','Home']];
  if(u.role==='User Admin')links.push(['#/admin','Users'],['#/profiles','Profiles']);
  if(u.role==='Interior Designer')links.push(['#/companies','Companies'],['#/projects','Projects']);
  if(u.role==='Customer')links.push(['#/browse','Browse'],['#/favourites','Favourites']);
  if(u.role==='Platform Manager')links.push(['#/categories','Categories'],['#/reports','Reports']);
  app().innerHTML=`<nav>${links.map(([h,l])=>`<a href="${h}">${l}</a>`).join('')}<button id="lo">Logout</button></nav><main class="card">${h}</main>`;
  $('#lo').onclick=async()=>{await api('/api/auth/logout',{method:'POST'});window.__user=null;route()};
}

function login(){
  app().innerHTML=`<main class="card" style="max-width:420px"><h1>Sign in</h1><form id="f"><label>Email <input name="email" type="email" required/></label><label>Password <input name="password" type="password" required/></label><p id="m" class="error"></p><button>Login</button></form></main>`;
  $('#f').onsubmit=async e=>{
    e.preventDefault();
    showMsg('m', 'Logging in...', false);
    const formData = new FormData(e.target);
    const data = {};
    formData.forEach((value, key) => { data[key] = value; });
    try {
      await api('/api/auth/login',{method:'POST',body:JSON.stringify(data)});
      showMsg('m', 'Success! Redirecting...', false);
      setTimeout(() => { window.location.hash = '#/'; route(); }, 500);
    } catch(ex){ showMsg('m', '❌ ' + ex.message, true); }
  }
}

async function dashboard(){shell(`<h1>Welcome, ${esc(window.__user.username)}</h1><p>Role: <strong>${esc(window.__user.role)}</strong></p><p>Use the navigation above.</p>`)}

async function profiles(){
  let L = (await api('/api/profiles')).profiles;
  const perms = ['user:manage', 'profile:manage', 'company:manage', 'project:manage', 'interest:view', 'project:view', 'favourite:manage', 'category:manage', 'report:generate'];
  const checkboxes = perms.map(p => `<label style="display:inline-block; margin-right: 1rem;"><input type="checkbox" name="permissions" value="${p}"> ${p}</label>`).join('');

  const rows = () => tbl(['profile_id', 'profile_name', 'status'], L, p => `
    <button data-view="${p.profile_id}" style="background:#0ea5e9; margin-right: 4px;">View</button>
    <button data-edit="${p.profile_id}" data-name="${esc(p.profile_name)}" data-perms='${JSON.stringify(p.permissions || [])}' style="margin-right: 4px;">Edit</button>
    <button data-s="${p.profile_id}" data-st="${p.status || 'Active'}" style="background:${(p.status || 'Active') === 'Active' ? '#eab308' : '#22c55e'};">${(p.status || 'Active') === 'Active' ? 'Suspend' : 'Activate'}</button>
  `);

  shell(`
    <h1>User Profiles</h1>
    <div id="view-panel" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; margin-bottom:1rem;">
      <h3 style="margin-top:0;">Profile Details</h3>
      <p><strong>ID:</strong> <span id="v-id"></span></p>
      <p><strong>Name:</strong> <span id="v-name"></span></p>
      <p><strong>Permissions:</strong> <span id="v-perms"></span></p>
      <button onclick="document.getElementById('view-panel').style.display='none'" style="background:#64748b;">Close View</button>
    </div>
    
    <h2 id="ft">Create Profile</h2>
    <form id="pf">
      <input type="hidden" name="profile_id" id="pid" />
      <label>Profile Name <input name="profile_name" id="pname" required placeholder="e.g. Senior Designer"/></label>
      <div style="margin: 1rem 0;"><strong>Permissions:</strong><br/>${checkboxes}</div>
      <button type="submit" id="cbtn">Create Profile</button>
      <button type="button" id="cancel" style="display:none; background:#64748b;">Cancel Edit</button>
    </form>
    <p id="m" class="error"></p>

    <h2>Search Profiles</h2>
    <input id="q" placeholder="Search profile name"/>
    <button id="s">Search</button> <button id="r">Reset</button>
    
    <h2>Existing Profiles</h2>
    <div id="tb">${rows()}</div>
  `);

  const resetForm = () => {
    $('#pf').reset();
    $('#pid').value = '';
    $('#ft').textContent = 'Create Profile';
    $('#cbtn').textContent = 'Create Profile';
    $('#cancel').style.display = 'none';
    document.querySelectorAll('input[name="permissions"]').forEach(cb => cb.checked = false);
  };

  const reload = async (q) => {
    if (q) {
      const r = await api('/api/profiles/search?q=' + encodeURIComponent(q));
      L = r.profiles;
      if (L.length === 0) {
        showMsg('m', '❌ No matching user profiles found.', true);
        $('#tb').innerHTML = '';
        return;
      }
    } else {
      L = (await api('/api/profiles')).profiles;
    }
    showMsg('m', '', false);
    $('#tb').innerHTML = rows();
  };

  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };

  $('#cancel').onclick = () => { resetForm(); showMsg('m', '', false); };

  $('#tb').onclick = async (e) => {
    const viewId = e.target.getAttribute('data-view');
    const editId = e.target.getAttribute('data-edit');
    const sId = e.target.getAttribute('data-s');
    const sStatus = e.target.getAttribute('data-st');

    if (sId) {
      const isConfirmed = confirm(`Are you sure you want to ${sStatus === 'Active' ? 'suspend' : 'activate'} this profile?`);
      if (!isConfirmed) return; 
      try {
        const act = sStatus === 'Active' ? 'suspend' : 'activate';
        await api('/api/profiles/' + sId + '/' + act, { method: 'PATCH' });
        showMsg('m', `✅ Profile status updated!`, false);
        reload($('#q').value);
      } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    }

    if (viewId) {
      showMsg('m', 'Fetching profile details...', false);
      try {
        const r = await api('/api/profiles/' + viewId);
        $('#v-id').textContent = r.profile.profile_id;
        $('#v-name').textContent = r.profile.profile_name;
        $('#v-perms').textContent = (r.profile.permissions || []).join(', ') || 'None';
        $('#view-panel').style.display = 'block';
        showMsg('m', '', false);
      } catch(ex) {
        $('#view-panel').style.display = 'none';
        showMsg('m', '❌ ' + ex.message, true);
      }
    }

    if (editId) {
      $('#view-panel').style.display = 'none';
      $('#pid').value = editId;
      $('#pname').value = e.target.getAttribute('data-name');
      
      let currentPerms = [];
      try { currentPerms = JSON.parse(e.target.getAttribute('data-perms') || '[]'); } catch(err) {}
      
      document.querySelectorAll('input[name="permissions"]').forEach(cb => {
        cb.checked = currentPerms.includes(cb.value);
      });
      $('#ft').textContent = 'Update Profile #' + editId;
      $('#cbtn').textContent = 'Update Profile';
      $('#cancel').style.display = 'inline-block';
      showMsg('m', '', false);
    }
  };

  $('#pf').onsubmit = async e => {
    e.preventDefault();
    
    // Ensure proper extraction of form data
    const formData = new FormData(e.target);
    const profile_name = formData.get('profile_name');
    const id = formData.get('profile_id');
    
    const checkedPerms = Array.from(document.querySelectorAll('input[name="permissions"]:checked')).map(cb => cb.value);
    
    showMsg('m', 'Saving...', false);
    try {
      if (id) {
        await api('/api/profiles/' + id, { method: 'PUT', body: JSON.stringify({ profile_name, permissions: checkedPerms }) });
        showMsg('m', '✅ Profile updated successfully!', false);
      } else {
        await api('/api/profiles', { method: 'POST', body: JSON.stringify({ profile_name, permissions: checkedPerms }) });
        showMsg('m', '✅ Profile created successfully!', false);
      }
      $('#q').value = ''; 
      await reload();
      resetForm();
    } catch(ex) {
      showMsg('m', '❌ ' + ex.message, true);
    }
  };
}

async function admin(){
  let P=(await api('/api/profiles')).profiles,U=(await api('/api/users')).users;
  const rows=u=>tbl(['account_id','username','email','profile_name','status'],u,x=>`
    <button data-view="${x.account_id}" style="background:#0ea5e9;">View</button>
    <button data-edit="${x.account_id}" data-u="${x.username}" data-e="${x.email}" data-p="${x.profile_id}">Edit</button>
    <button data-s="${x.account_id}" data-st="${x.status}">${x.status==='Active'?'Suspend':'Activate'}</button>
  `);
  
  shell(`
    <h1>User Accounts</h1>
    <div id="view-panel" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; margin-bottom:1rem;">
      <h3 style="margin-top:0;">User Details</h3>
      <p><strong>ID:</strong> <span id="v-id"></span></p>
      <p><strong>Username:</strong> <span id="v-uname"></span></p>
      <p><strong>Email:</strong> <span id="v-email"></span></p>
      <p><strong>Role:</strong> <span id="v-role"></span></p>
      <p><strong>Status:</strong> <span id="v-status"></span></p>
      <button onclick="document.getElementById('view-panel').style.display='none'" style="background:#64748b;">Close View</button>
    </div>
    <h2 id="ft">Create User</h2>
    <form id="cf">
      <input type="hidden" name="account_id" id="uid"/>
      <label>Username <input name="username" id="uname" required/></label>
      <label>Email <input name="email" type="email" id="uemail" required/></label>
      <label>Password <input name="password" type="password" id="upass" required/></label>
      <label>Profile <select name="profile_id" id="uprofile">${P.map(p=>`<option value="${p.profile_id}">${esc(p.profile_name)}</option>`).join('')}</select></label>
      <button type="submit" id="cbtn">Create</button> 
      <button type="button" id="cancel" style="display:none; background:#64748b;">Cancel Edit</button>
    </form>
    <p id="m" class="error"></p>
    <h2>Search</h2>
    <input id="q" placeholder="Search username or email"/>
    <button id="s">Search</button> <button id="r">Reset</button>
    <div id="tb">${rows(U)}</div>
  `);

  const reload=async q=>{
    try {
      const u = (q ? await api('/api/users/search?q='+encodeURIComponent(q)) : await api('/api/users')).users;
      if (q && u.length === 0) {
        showMsg('m', '❌ No users found matching your search.', true);
        $('#tb').innerHTML = ''; 
      } else {
        showMsg('m', '', false); 
        $('#tb').innerHTML = rows(u);
      }
    } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  };

  const resetForm = () => {
    $('#cf').reset();
    $('#uid').value = '';
    $('#upass').required = true;
    $('#ft').textContent = 'Create User';
    $('#cbtn').textContent = 'Create';
    $('#cancel').style.display = 'none';
  };

  $('#cancel').onclick = () => { resetForm(); showMsg('m', '', false); };

  $('#cf').onsubmit = async e => {
    e.preventDefault();
    const b = Object.fromEntries(new FormData(e.target));
    const id = b.account_id;
    delete b.account_id; 
    if (!id) b.profile_id = +b.profile_id;

    showMsg('m', 'Saving...', false);
    try {
      if (id) {
        delete b.password;
        await api('/api/users/' + id, { method: 'PUT', body: JSON.stringify(b) });
        resetForm(); 
        showMsg('m', '✅ User updated successfully!', false); 
      } else {
        await api('/api/users', { method: 'POST', body: JSON.stringify(b) });
        resetForm();
        showMsg('m', '✅ User created successfully!', false);
      }
      reload();
    } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  };

  $('#s').onclick = () => reload($('#q').value);
  $('#r').onclick = () => { $('#q').value = ''; reload(); };
  
  $('#tb').onclick = async e => {
    const s = e.target.getAttribute('data-s');
    const st = e.target.getAttribute('data-st');
    const editId = e.target.getAttribute('data-edit');
    const viewId = e.target.getAttribute('data-view');
    
    if (s) {
      const isConfirmed = confirm(`Are you sure you want to ${st === 'Active' ? 'suspend' : 'activate'} this user?`);
      if (!isConfirmed) return; 
      try {
        const act = st === 'Active' ? 'suspend' : 'activate';
        await api('/api/users/' + s + '/' + act, { method: 'PATCH' });
        showMsg('m', `✅ User status updated!`, false);
        reload();
      } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    }

    if (editId) {
      $('#view-panel').style.display = 'none'; 
      $('#uid').value = editId;
      $('#uname').value = e.target.getAttribute('data-u');
      $('#uemail').value = e.target.getAttribute('data-e');
      $('#uprofile').value = e.target.getAttribute('data-p');
      $('#upass').required = false; 
      $('#upass').value = ''; 
      $('#ft').textContent = 'Update User #' + editId;
      $('#cbtn').textContent = 'Update';
      $('#cancel').style.display = 'inline-block';
      showMsg('m', '', false); 
    }

    if (viewId) {
      showMsg('m', 'Fetching user details...', false);
      try {
        const r = await api('/api/users/' + viewId);
        $('#v-id').textContent = r.user.account_id;
        $('#v-uname').textContent = r.user.username;
        $('#v-email').textContent = r.user.email;
        $('#v-role').textContent = r.user.profile_name;
        $('#v-status').textContent = r.user.status;
        $('#view-panel').style.display = 'block';
        showMsg('m', '', false); 
      } catch(ex) {
        $('#view-panel').style.display = 'none';
        showMsg('m', '❌ ' + ex.message, true); 
      }
    }
  };
}

async function companies(){
  let L=(await api('/api/companies')).companies;
  const rows=c=>tbl(['company_id','company_name','address','contact_no'],c,x=>`
    <button data-view="${x.company_id}" style="background:#0ea5e9; margin-right:4px;">View</button>
    <button data-e="${x.company_id}" style="margin-right:4px;">Edit</button> 
    <button data-d="${x.company_id}">Delete</button>
  `);
  
  shell(`
    <h1>Company Information</h1>
    <div id="view-panel" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; margin-bottom:1rem;">
      <h3 style="margin-top:0;">Company Details</h3>
      <p><strong>ID:</strong> <span id="v-id"></span></p>
      <p><strong>Name:</strong> <span id="v-name"></span></p>
      <p><strong>Address:</strong> <span id="v-address"></span></p>
      <p><strong>Contact:</strong> <span id="v-contact"></span></p>
      <button onclick="document.getElementById('view-panel').style.display='none'" style="background:#64748b;">Close View</button>
    </div>

    <h2 id="ft">Create Company</h2>
    <form id="f">
      <input type="hidden" name="company_id"/>
      <label>Company name <input name="company_name" required/></label>
      <label>Address <input name="address"/></label>
      <label>Contact <input name="contact_no"/></label>
      <button type="submit">Save Company</button> 
      <button type="button" id="c" style="display:none; background:#64748b;">Cancel edit</button>
    </form>
    <p id="m" class="error"></p>
    
    <h2>Search</h2>
    <input id="q" placeholder="Search company name"/>
    <button id="s">Search</button> <button id="r">Reset</button>
    <div id="tb">${rows(L)}</div>
  `);

  const reload=async q=>{
    try {
      const data = q ? await api('/api/companies/search?q='+encodeURIComponent(q)) : await api('/api/companies');
      L = data.companies;
      
      if (q && L.length === 0) {
        showMsg('m', '❌ No companies found.', true);
        $('#tb').innerHTML = '';
      } else {
        showMsg('m', '', false);
        $('#tb').innerHTML = rows(L);
      }
    } catch (ex) {
      showMsg('m', '❌ ' + ex.message, true);
    }
  };
  
  const F=$('#f');
  $('#c').onclick=()=>{
    F.reset();
    F.company_id.value='';
    $('#ft').textContent='Create Company';
    $('#c').style.display='none';
    showMsg('m','',false);
  };
  
  F.onsubmit=async e=>{
    e.preventDefault();
    const b={
      company_name:F.company_name.value,
      address:F.address.value,
      contact_no:F.contact_no.value
    };
    showMsg('m', 'Saving...', false);
    
    try{
      if(F.company_id.value){
        await api('/api/companies/'+F.company_id.value,{method:'PUT',body:JSON.stringify(b)});
        showMsg('m', '✅ Company updated successfully!', false);
      } else {
        await api('/api/companies',{method:'POST',body:JSON.stringify(b)});
        showMsg('m', '✅ Company created successfully!', false); 
      }
      F.reset();
      F.company_id.value='';
      $('#ft').textContent='Create Company';
      $('#c').style.display='none';
      reload();
    }catch(ex){
      showMsg('m', '❌ ' + ex.message, true); 
    }
  };
  
  $('#s').onclick=()=>reload($('#q').value);
  $('#r').onclick=()=>{$('#q').value='';reload()};
  
  $('#tb').onclick=async e=>{
    const vi=e.target.getAttribute('data-view');
    const ei=e.target.getAttribute('data-e');
    const di=e.target.getAttribute('data-d');
    
    if(vi){
      showMsg('m', 'Fetching company info...', false);
      try {
        const r = await api('/api/companies/' + vi);
        $('#v-id').textContent = r.company.company_id;
        $('#v-name').textContent = r.company.company_name;
        $('#v-address').textContent = r.company.address || 'N/A';
        $('#v-contact').textContent = r.company.contact_no || 'N/A';
        $('#view-panel').style.display = 'block';
        showMsg('m', '', false);
      } catch(ex) {
        $('#view-panel').style.display = 'none';
        showMsg('m', '❌ ' + ex.message, true);
      }
    }

    if(ei){
      $('#view-panel').style.display = 'none';
      const c=L.find(x=>String(x.company_id)===ei);
      F.company_id.value=c.company_id;
      F.company_name.value=c.company_name;
      F.address.value=c.address||'';
      F.contact_no.value=c.contact_no||'';
      $('#ft').textContent='Update Company #'+c.company_id;
      $('#c').style.display='inline-block';
      showMsg('m', '', false);
    }
    
    if(di&&confirm('Delete Company #'+di+'?')){
      try { 
        await api('/api/companies/'+di,{method:'DELETE'}); 
        showMsg('m', '✅ Company deleted successfully!', false); 
        $('#view-panel').style.display = 'none';
        reload(); 
      } catch(ex) { 
        showMsg('m', '❌ ' + ex.message, true); 
      }
    }
  }
}

async function projects(){
  let CS=(await api('/api/companies')).companies;
  if(!CS.length) return shell('<h1>Projects</h1><p>❌ Please create a company first.</p>');
  
  let cur=CS[0].company_id, L=[], im={};
  
  const rows=()=>tbl(['project_id','title','status'],L,p=>{
    const i=im[p.project_id]||{views:0,shortlists:0};
    return `
      <button data-v="${p.project_id}" style="background:#0ea5e9; margin-right:4px;">View</button>
      <button data-e="${p.project_id}" style="margin-right:4px;">Edit</button> 
      <button data-d="${p.project_id}">Delete</button> 
      <span style="margin-left:10px; font-weight:bold;">V:${i.views} S:${i.shortlists}</span>
    `;
  });

  shell(`
    <h1>Projects</h1>
    <select id="sel">${CS.map(c=>`<option value="${c.company_id}">${esc(c.company_name)}</option>`).join('')}</select>
    
    <div id="view-panel" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; margin-bottom:1rem;">
      <h3 style="margin-top:0;">Project Details</h3>
      <p><strong>Title:</strong> <span id="v-title"></span></p>
      <p><strong>Description:</strong> <span id="v-desc"></span></p>
      <p><strong>Status:</strong> <span id="v-status"></span></p>
      <p><strong>Image URL:</strong> <span id="v-img"></span></p>
      <button onclick="document.getElementById('view-panel').style.display='none'" style="background:#64748b;">Close View</button>
    </div>

    <h2 id="ft">Create Project</h2>
    <form id="f">
      <input type="hidden" name="project_id"/>
      <label>Title <input name="title" required/></label>
      <label>Description <textarea name="description" rows="2" required></textarea></label>
      <label>Status <select name="status"><option>Ongoing</option><option>Completed</option></select></label>
      <label>Image URL <input name="image_url"/></label>
      <button type="submit">Save</button> 
      <button type="button" id="c" style="display:none; background:#64748b;">Cancel</button>
    </form>
    
    <p id="m" class="error"></p>
    
    <input id="q" placeholder="Search"/> 
    <select id="st"><option value="">All</option><option>Ongoing</option><option>Completed</option></select> 
    <button id="s">Search</button> <button id="r">Reset</button>
    
    <div id="tb"></div>
  `);

  const reload=async q=>{
    try {
      im={};
      ((await api('/api/projects/company/'+cur+'/interest')).interest).forEach(i=>im[i.project_id]=i);
      const st=$('#st').value;
      let u='/api/projects/company/'+cur;
      if(q)u+='/search?q='+encodeURIComponent(q)+(st?'&status='+st:'');
      else if(st)u+='?status='+st;
      
      L=(await api(u)).projects;
      
      if(L.length === 0) {
        showMsg('m', '❌ No projects exist.', true);
        $('#tb').innerHTML = '';
      } else {
        showMsg('m', '', false);
        $('#tb').innerHTML=rows();
      }
    } catch (ex) {
      showMsg('m', '❌ ' + ex.message, true);
    }
  };

  const F=$('#f');
  $('#sel').onchange=e=>{cur=e.target.value;F.reset();F.project_id.value='';$('#ft').textContent='Create Project';$('#c').style.display='none';reload()};
  $('#c').onclick=()=>{F.reset();F.project_id.value='';$('#ft').textContent='Create Project';$('#c').style.display='none';showMsg('m','',false)};
  
  F.onsubmit=async e=>{
    e.preventDefault();
    if(!F.title.value || !F.description.value) {
      return showMsg('m', '❌ Title and Description are required.', true);
    }

    const b={
      title:F.title.value,
      description:F.description.value,
      status:F.status.value,
      image_url:F.image_url.value
    };
    
    showMsg('m', 'Saving...', false);
    
    try{
      if(F.project_id.value){
        await api('/api/projects/'+F.project_id.value,{method:'PUT',body:JSON.stringify(b)});
        showMsg('m', '✅ Project updated successfully!', false);
      } else {
        b.company_id=+cur;
        await api('/api/projects',{method:'POST',body:JSON.stringify(b)});
        showMsg('m', '✅ Project created successfully!', false); 
      }
      
      F.reset();
      F.project_id.value='';
      $('#ft').textContent='Create Project';
      $('#c').style.display='none';
      reload();
    }catch(ex){
      showMsg('m', '❌ ' + ex.message, true); 
    }
  };
  
  $('#s').onclick=()=>reload($('#q').value);
  $('#r').onclick=()=>{$('#q').value='';$('#st').value='';reload()};
  
  $('#tb').onclick=async e=>{
    const vi=e.target.getAttribute('data-v');
    const ei=e.target.getAttribute('data-e');
    const di=e.target.getAttribute('data-d');
    
    if(vi){
      showMsg('m', 'Fetching project details...', false);
      try {
        const r = await api('/api/projects/' + vi);
        $('#v-title').textContent = r.project.title;
        $('#v-desc').textContent = r.project.description || 'N/A';
        $('#v-status').textContent = r.project.status;
        $('#v-img').textContent = r.project.image_url || 'N/A';
        
        $('#view-panel').style.display = 'block';
        showMsg('m', '', false);
      } catch(ex) {
        $('#view-panel').style.display = 'none';
        showMsg('m', '❌ ' + ex.message, true);
      }
    }

    if(ei){
      $('#view-panel').style.display = 'none';
      const p=L.find(x=>String(x.project_id)===ei);
      F.project_id.value=p.project_id;
      F.title.value=p.title;
      F.description.value=p.description||'';
      F.status.value=p.status;
      F.image_url.value=p.image_url||'';
      $('#ft').textContent='Update Project #'+p.project_id;
      $('#c').style.display='inline-block';
      showMsg('m', '', false);
    }
    
    if(di&&confirm('Delete Project #'+di+'?')){
      try { 
        await api('/api/projects/'+di,{method:'DELETE'}); 
        showMsg('m', '✅ Project deleted successfully!', false); 
        $('#view-panel').style.display = 'none';
        reload(); 
      } catch(ex) { 
        showMsg('m', '❌ ' + ex.message, true); 
      }
    }
  };
  
  await reload();
}

async function browse(){
  shell(`<h1>Browse Projects</h1><input id="q" placeholder="Search title or description"/> <button id="s">Search</button> <button id="r">Reset</button><div id="tb"></div><h2>Detail</h2><p id="m" class="error"></p><div id="d">Select a project.</div>`);
  const reload=async q=>{const r=await api('/api/projects/public/search'+(q?'?q='+encodeURIComponent(q):''));$('#tb').innerHTML=tbl(['project_id','title','company_name','status'],r.projects,p=>`<button data-v="${p.project_id}">View</button> <button data-s2="${p.project_id}">Save</button>`)};
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';reload()};
  $('#tb').onclick=async e=>{
    const vi=e.target.getAttribute('data-v'),si=e.target.getAttribute('data-s2');
    if(vi){
      try{
        const r=await api('/api/projects/public/'+vi);
        $('#d').innerHTML=`<strong>${esc(r.project.title)}</strong><br>${esc(r.project.description||'')}<br>Status: ${r.project.status}`;
        showMsg('m','',false);
      }catch(ex){ showMsg('m', '❌ ' + ex.message, true); }
    }
    if(si){
      try{
        await api('/api/favourites',{method:'POST',body:JSON.stringify({project_id:+si})});
        showMsg('m', `✅ Saved project #${si} to favourites!`, false);
      }catch(ex){ showMsg('m', '❌ ' + ex.message, true); }
    }
  };
  await reload()
}

async function favourites(){
  shell(`<h1>Favourite List</h1><input id="q" placeholder="Search"/> <button id="s">Search</button> <button id="r">Reset</button><p id="m" class="error"></p><div id="tb"></div>`);
  const reload=async q=>{const r=q?await api('/api/favourites/search?q='+encodeURIComponent(q)):await api('/api/favourites');$('#tb').innerHTML=tbl(['project_id','title','status','saved_at'],r.favourites,f=>`<button data-x="${f.project_id}">Remove</button>`)};
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';reload()};
  $('#tb').onclick=async e=>{
    const id=e.target.getAttribute('data-x');if(!id)return;
    try { await api('/api/favourites/'+id,{method:'DELETE'}); showMsg('m', '✅ Removed from favourites!', false); reload(); }
    catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  };
  await reload()
}

async function categories(){
  let L=[];
  shell(`<h1>IDP Categories</h1><h2 id="ft">Create</h2><form id="f"><input type="hidden" name="category_id"/><label>Name <input name="category_name" required/></label><label>Description <textarea name="description" rows="2"></textarea></label><button>Save</button> <button type="button" id="c">Cancel</button></form><p id="m" class="error"></p><input id="q" placeholder="Search"/> <button id="s">Search</button> <button id="r">Reset</button><div id="tb"></div>`);
  const reload=async q=>{L=(q?(await api('/api/categories/search?q='+encodeURIComponent(q))):(await api('/api/categories'))).categories;$('#tb').innerHTML=tbl(['category_id','category_name','description'],L,c=>`<button data-e="${c.category_id}">Edit</button> <button data-d="${c.category_id}">Delete</button>`)};
  const F=$('#f');$('#c').onclick=()=>{F.reset();F.category_id.value='';$('#ft').textContent='Create'};
  
  F.onsubmit=async e=>{
    e.preventDefault();
    const b={category_name:F.category_name.value,description:F.description.value};
    showMsg('m', 'Saving...', false);
    try{
      if(F.category_id.value){
        await api('/api/categories/'+F.category_id.value,{method:'PUT',body:JSON.stringify(b)});
        showMsg('m', '✅ Category updated successfully!', false);
      } else {
        await api('/api/categories',{method:'POST',body:JSON.stringify(b)});
        showMsg('m', '✅ Category created successfully!', false);
      }
      F.reset();F.category_id.value='';$('#ft').textContent='Create';reload();
    }catch(ex){showMsg('m', '❌ ' + ex.message, true);}
  };
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';reload()};
  $('#tb').onclick=async e=>{
    const ei=e.target.getAttribute('data-e'),di=e.target.getAttribute('data-d');
    if(ei){const c=L.find(x=>String(x.category_id)===ei);F.category_id.value=c.category_id;F.category_name.value=c.category_name;F.description.value=c.description||'';$('#ft').textContent='Update #'+c.category_id}
    if(di&&confirm('Delete #'+di+'?')){
      try { await api('/api/categories/'+di,{method:'DELETE'}); showMsg('m', '✅ Category deleted successfully!', false); reload(); }
      catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    }
  };
  await reload()
}

async function reports(){
  shell(`<h1>Platform Reports</h1><button data-p="daily">Daily</button> <button data-p="weekly">Weekly</button> <button data-p="monthly">Monthly</button><h2 id="t">Select</h2><p id="m" class="error"></p><div id="sm"></div><h2>Top</h2><table><thead><tr><th>Project</th><th>Views</th></tr></thead><tbody id="tb"></tbody></table>`);
  app().onclick=async e=>{
    const p=e.target.getAttribute('data-p');if(!p)return;
    showMsg('m', 'Generating report...', false);
    try {
      const r=await api('/api/reports/'+p);
      $('#t').textContent=p.toUpperCase()+' report';
      $('#sm').innerHTML=`<p>New users: <strong>${r.summary.new_users}</strong></p><p>Views: <strong>${r.summary.project_views}</strong></p><p>Shortlists: <strong>${r.summary.shortlists}</strong></p><p>New projects: <strong>${r.summary.new_projects}</strong></p>`;
      $('#tb').innerHTML=r.topProjects.map(x=>`<tr><td>${esc(x.title)}</td><td>${x.views}</td></tr>`).join('');
      showMsg('m', '✅ Report generated successfully!', false);
    } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  }
}

async function route(){
  const h=(location.hash||'#/').replace(/^#\/?/,'');
  try{window.__user=(await api('/api/auth/me')).user}catch(_){return login()}
  const r=window.__user.role;
  if(h==='admin'&&r==='User Admin')return admin();
  if(h==='profiles'&&r==='User Admin')return profiles();
  if(h==='companies'&&r==='Interior Designer')return companies();
  if(h==='projects'&&r==='Interior Designer')return projects();
  if(h==='browse'&&r==='Customer')return browse();
  if(h==='favourites'&&r==='Customer')return favourites();
  if(h==='categories'&&r==='Platform Manager')return categories();
  if(h==='reports'&&r==='Platform Manager')return reports();
  return dashboard()
}
window.addEventListener('hashchange',route);route();