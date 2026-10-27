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
  if(u.role==='User Admin')links.push(['#/admin','Users']);
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
      setTimeout(() => {
        window.location.hash = '#/'; 
        route();
      }, 500);
    } catch(ex){
      showMsg('m', '❌ ' + ex.message, true);
    }
  }
}

async function dashboard(){shell(`<h1>Welcome, ${esc(window.__user.username)}</h1><p>Role: <strong>${esc(window.__user.role)}</strong></p><p>Use the navigation above.</p>`)}

async function admin(){
  let P=(await api('/api/profiles')).profiles,U=(await api('/api/users')).users;
  const rows=u=>tbl(['account_id','username','email','profile_name','status'],u,x=>`<button data-s="${x.account_id}" data-st="${x.status}">${x.status==='Active'?'Suspend':'Activate'}</button>`);
  shell(`<h1>User Accounts</h1><h2>Create</h2><form id="cf"><label>Username <input name="username" required/></label><label>Email <input name="email" type="email" required/></label><label>Password <input name="password" required/></label><label>Profile <select name="profile_id">${P.map(p=>`<option value="${p.profile_id}">${esc(p.profile_name)}</option>`).join('')}</select></label><button>Create</button></form><p id="m" class="error"></p><h2>Search</h2><input id="q" placeholder="Search"/><button id="s">Search</button> <button id="r">Reset</button><div id="tb">${rows(U)}</div>`);
  const reload=async q=>{const u=(q?await api('/api/users/search?q='+encodeURIComponent(q)):await api('/api/users')).users;$('#tb').innerHTML=rows(u);};
  
  $('#cf').onsubmit=async e=>{
    e.preventDefault();
    const b=Object.fromEntries(new FormData(e.target));
    b.profile_id=+b.profile_id;
    showMsg('m', 'Creating user...', false);
    try{
      await api('/api/users',{method:'POST',body:JSON.stringify(b)});
      e.target.reset();
      showMsg('m', '✅ User account created successfully!', false);
      reload();
    } catch(ex){
      showMsg('m', '❌ ' + ex.message, true);
    }
  };
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';reload()};
  $('#tb').onclick=async e=>{
    const id=e.target.getAttribute('data-s');if(!id)return;
    const act=e.target.getAttribute('data-st')==='Active'?'suspend':'activate';
    try {
      await api('/api/users/'+id+'/'+act,{method:'PATCH'});
      showMsg('m', `✅ User status updated!`, false);
      reload();
    } catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
  }
}

async function companies(){
  let L=(await api('/api/companies')).companies;
  const rows=c=>tbl(['company_id','company_name','address','contact_no'],c,x=>`<button data-e="${x.company_id}">Edit</button> <button data-d="${x.company_id}">Delete</button>`);
  shell(`<h1>Company Information</h1><h2 id="ft">Create</h2><form id="f"><input type="hidden" name="company_id"/><label>Company name <input name="company_name" required/></label><label>Address <input name="address"/></label><label>Contact <input name="contact_no"/></label><button>Save</button> <button type="button" id="c">Cancel edit</button></form><p id="m" class="error"></p><h2>Search</h2><input id="q" placeholder="Search"/><button id="s">Search</button> <button id="r">Reset</button><div id="tb">${rows(L)}</div>`);
  const reload=async q=>{L=q?(await api('/api/companies/search?q='+encodeURIComponent(q))).companies:(await api('/api/companies')).companies;$('#tb').innerHTML=rows(L)};
  const F=$('#f');$('#c').onclick=()=>{F.reset();F.company_id.value='';$('#ft').textContent='Create';showMsg('m','',false)};
  
  F.onsubmit=async e=>{
    e.preventDefault();
    const b={company_name:F.company_name.value,address:F.address.value,contact_no:F.contact_no.value};
    showMsg('m', 'Saving...', false);
    try{
      if(F.company_id.value){
        await api('/api/companies/'+F.company_id.value,{method:'PUT',body:JSON.stringify(b)});
        showMsg('m', '✅ Company updated successfully!', false);
      } else {
        await api('/api/companies',{method:'POST',body:JSON.stringify(b)});
        showMsg('m', '✅ Company created successfully!', false);
      }
      F.reset();F.company_id.value='';$('#ft').textContent='Create';reload();
    }catch(ex){showMsg('m', '❌ ' + ex.message, true);}
  };
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';reload()};
  $('#tb').onclick=async e=>{
    const ei=e.target.getAttribute('data-e'),di=e.target.getAttribute('data-d');
    if(ei){const c=L.find(x=>String(x.company_id)===ei);F.company_id.value=c.company_id;F.company_name.value=c.company_name;F.address.value=c.address||'';F.contact_no.value=c.contact_no||'';$('#ft').textContent='Update #'+c.company_id;}
    if(di&&confirm('Delete #'+di+'?')){
      try { await api('/api/companies/'+di,{method:'DELETE'}); showMsg('m', '✅ Company deleted successfully!', false); reload(); } 
      catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    }
  }
}

async function projects(){
  let CS=(await api('/api/companies')).companies;if(!CS.length)return shell('<h1>Projects</h1><p>Create a company first.</p>');
  let cur=CS[0].company_id,L=[],im={};
  const rows=()=>tbl(['project_id','title','status'],L,p=>{const i=im[p.project_id]||{views:0,shortlists:0};return `<button data-e="${p.project_id}">Edit</button> <button data-d="${p.project_id}">Delete</button> <span>V:${i.views} S:${i.shortlists}</span>`});
  shell(`<h1>Projects</h1><select id="sel">${CS.map(c=>`<option value="${c.company_id}">${esc(c.company_name)}</option>`).join('')}</select><h2 id="ft">Create</h2><form id="f"><input type="hidden" name="project_id"/><label>Title <input name="title" required/></label><label>Description <textarea name="description" rows="2"></textarea></label><label>Status <select name="status"><option>Ongoing</option><option>Completed</option></select></label><label>Image URL <input name="image_url"/></label><button>Save</button> <button type="button" id="c">Cancel</button></form><p id="m" class="error"></p><input id="q" placeholder="Search"/> <select id="st"><option value="">All</option><option>Ongoing</option><option>Completed</option></select> <button id="s">Search</button> <button id="r">Reset</button><div id="tb"></div>`);
  const reload=async q=>{im={};((await api('/api/projects/company/'+cur+'/interest')).interest).forEach(i=>im[i.project_id]=i);const st=$('#st').value;let u='/api/projects/company/'+cur;if(q)u+='/search?q='+encodeURIComponent(q)+(st?'&status='+st:'');else if(st)u+='?status='+st;L=(await api(u)).projects;$('#tb').innerHTML=rows()};
  const F=$('#f');$('#sel').onchange=e=>{cur=e.target.value;F.reset();F.project_id.value='';$('#ft').textContent='Create';reload()};
  $('#c').onclick=()=>{F.reset();F.project_id.value='';$('#ft').textContent='Create'};
  
  F.onsubmit=async e=>{
    e.preventDefault();
    const b={title:F.title.value,description:F.description.value,status:F.status.value,image_url:F.image_url.value};
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
      F.reset();F.project_id.value='';$('#ft').textContent='Create';reload();
    }catch(ex){showMsg('m', '❌ ' + ex.message, true);}
  };
  $('#s').onclick=()=>reload($('#q').value);$('#r').onclick=()=>{$('#q').value='';$('#st').value='';reload()};
  $('#tb').onclick=async e=>{
    const ei=e.target.getAttribute('data-e'),di=e.target.getAttribute('data-d');
    if(ei){const p=L.find(x=>String(x.project_id)===ei);F.project_id.value=p.project_id;F.title.value=p.title;F.description.value=p.description||'';F.status.value=p.status;F.image_url.value=p.image_url||'';$('#ft').textContent='Update #'+p.project_id}
    if(di&&confirm('Delete #'+di+'?')){
      try { await api('/api/projects/'+di,{method:'DELETE'}); showMsg('m', '✅ Project deleted successfully!', false); reload(); }
      catch(ex) { showMsg('m', '❌ ' + ex.message, true); }
    }
  };
  await reload()
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
  if(h==='companies'&&r==='Interior Designer')return companies();
  if(h==='projects'&&r==='Interior Designer')return projects();
  if(h==='browse'&&r==='Customer')return browse();
  if(h==='favourites'&&r==='Customer')return favourites();
  if(h==='categories'&&r==='Platform Manager')return categories();
  if(h==='reports'&&r==='Platform Manager')return reports();
  return dashboard()
}
window.addEventListener('hashchange',route);route();