const pool = require('../config/db');
const { hashPassword } = require('../config/auth');

const F = ['Aria','Kai','Mia','Noah','Leah','Ethan','Zoe','Luca','Ivy','Owen'];
const L = ['Tan','Lim','Wong','Ng','Lee','Chan','Koh','Yeo','Goh','Ong'];
const CATS = ['Residential','Commercial','Kitchen','Bathroom','Living Room','Bedroom','Office','Retail','Hospitality','Outdoor'];
const rnd = a => a[Math.floor(Math.random() * a.length)];
const ri = (a, b) => Math.floor(Math.random() * (b - a + 1)) + a;

(async () => {
  const c = await pool.connect();
  try {
    await c.query('BEGIN');
    const profs = [
      ['User Admin', ['user:manage','profile:manage']],
      ['Interior Designer', ['company:manage','project:manage','interest:view']],
      ['Customer', ['project:view','favourite:manage']],
      ['Platform Manager', ['category:manage','report:generate']]
    ];
    for (const [n, p] of profs) await c.query('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) ON CONFLICT DO NOTHING', [n, p]);
    
    const pids = {};
    (await c.query('SELECT profile_id, profile_name FROM user_profile')).rows.forEach(r => pids[r.profile_name] = r.profile_id);
    const hash = await hashPassword('Password123!');
    const accs = { 'User Admin': [], 'Interior Designer': [], 'Customer': [], 'Platform Manager': [] };
    let n = 1;
    
    for (const role of Object.keys(accs)) {
      for (let i = 0; i < 30; i++) {
        const u = role.toLowerCase().replace(/\s+/g, '.') + '.' + n;
        const s = Math.random() < 0.1 ? 'Suspended' : 'Active';
        const r = await c.query('INSERT INTO user_account (profile_id, username, email, password_hash, status) VALUES ($1,$2,$3,$4,$5) RETURNING account_id', [pids[role], u, u + '@example.test', hash, s]);
        accs[role].push(r.rows[0].account_id); n++;
      }
    }
    
    const comps = [];
    for (let i = 0; i < 150; i++) {
      const r = await c.query('INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1,$2,$3,$4) RETURNING company_id', [rnd(accs['Interior Designer']), 'Studio ' + rnd(F) + ' ' + rnd(L) + ' #' + (i+1), ri(1,200) + ' Demo St, SG', '+65' + ri(60000000,99999999)]);
      comps.push(r.rows[0].company_id);
    }
    
    for (const nm of CATS) await c.query('INSERT INTO category (category_name, description) VALUES ($1,$2) ON CONFLICT DO NOTHING', [nm, 'Category for ' + nm.toLowerCase()]);
    const catIds = (await c.query('SELECT category_id FROM category')).rows.map(r => r.category_id);
    
    const projs = [];
    for (let i = 0; i < 200; i++) {
      const st = Math.random() < 0.4 ? 'Completed' : 'Ongoing';
      const r = await c.query('INSERT INTO project (company_id, title, description, status, image_url) VALUES ($1,$2,$3,$4,$5) RETURNING project_id', [rnd(comps), 'Project ' + rnd(F) + ' ' + (i+1), 'Sample interior design project.', st, '/img/sample-' + ((i%10)+1) + '.jpg']);
      projs.push(r.rows[0].project_id);
      const used = new Set();
      for (let k = 0; k < ri(1,3); k++) used.add(rnd(catIds));
      for (const cid of used) await c.query('INSERT INTO project_category (project_id, category_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [r.rows[0].project_id, cid]);
    }
    
    for (let i = 0; i < 500; i++) {
      await c.query("INSERT INTO project_view (project_id, customer_id, viewed_at) VALUES ($1,$2, now() - ($3 || ' days')::interval)", [rnd(projs), rnd(accs['Customer']), ri(0,60)]);
    }
    
    let fav = 0, g = 0;
    while (fav < 200 && g < 5000) {
      g++;
      try {
        await c.query('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2)', [rnd(accs['Customer']), rnd(projs)]);
        fav++;
      } catch (_) {}
    }
    
    await c.query('COMMIT');
    console.log('Seed complete: 120 accounts, 150 companies, 200 projects, 500 views, 200 favourites.');
    console.log('All accounts use password: Password123!');
  } catch (e) {
    await c.query('ROLLBACK');
    console.error('Seed failed:', e);
  } finally {
    c.release();
    await pool.end();
  }
})();