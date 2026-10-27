const pool = require('../config/db');
const { hashPassword } = require('../config/auth');

const FIRST = ['Aria','Kai','Mia','Noah','Leah','Ethan','Zoe','Luca','Ivy','Owen'];
const LAST  = ['Tan','Lim','Wong','Ng','Lee','Chan','Koh','Yeo','Goh','Ong'];
const CATS  = ['Residential','Commercial','Kitchen','Bathroom','Living Room','Bedroom'];
const rnd = a => a[Math.floor(Math.random() * a.length)];
const ri = (a, b) => Math.floor(Math.random() * (b - a + 1)) + a;

(async () => {
  const c = await pool.connect();
  try {
    console.log('--- STARTING SEED ---');
    await c.query('BEGIN');
    
    // 1. Profiles
    const profiles = [
      ['User Admin', ['user:manage']],
      ['Interior Designer', ['company:manage']],
      ['Customer', ['project:view']],
      ['Platform Manager', ['category:manage']]
    ];
    for (const [name, perms] of profiles) {
      await c.query('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) ON CONFLICT DO NOTHING', [name, perms]);
    }
    const pids = {};
    (await c.query('SELECT profile_id, profile_name FROM user_profile')).rows.forEach(r => pids[r.profile_name] = r.profile_id);
    
    // 2. Users
    const hash = await hashPassword('Password123!');
    for (const role of Object.keys(pids)) {
      for (let i = 1; i <= 30; i++) {
        const u = role.toLowerCase().replace(/\s+/g, '.') + '.' + i;
        await c.query(
          'INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1,$2,$3,$4) ON CONFLICT DO NOTHING',
          [pids[role], u, u + '@example.test', hash]
        );
      }
    }
    console.log('✅ Users checked/inserted');

    // FETCH IDs DIRECTLY FROM DB (Fixes the null owner_id issue)
    const accs = { 'User Admin': [], 'Interior Designer': [], 'Customer': [], 'Platform Manager': [] };
    const allUsers = (await c.query(`
      SELECT a.account_id, p.profile_name 
      FROM user_account a 
      JOIN user_profile p ON p.profile_id = a.profile_id
    `)).rows;
    
    allUsers.forEach(u => {
      if (accs[u.profile_name]) accs[u.profile_name].push(u.account_id);
    });

    // 3. Companies
    for (let i = 1; i <= 50; i++) {
      await c.query('INSERT INTO company (owner_id, company_name, address) VALUES ($1,$2,$3)', [rnd(accs['Interior Designer']), 'Studio ' + rnd(LAST) + ' ' + i, 'Address ' + i]);
    }
    const comps = (await c.query('SELECT company_id FROM company')).rows.map(r => r.company_id);
    console.log('✅ Companies inserted');

    // 4. Categories
    for (const cat of CATS) {
      await c.query('INSERT INTO category (category_name) VALUES ($1) ON CONFLICT DO NOTHING', [cat]);
    }
    const catIds = (await c.query('SELECT category_id FROM category')).rows.map(r => r.category_id);

    // 5. Projects
    const projs = [];
    for (let i = 1; i <= 100; i++) {
      const st = Math.random() < 0.4 ? 'Completed' : 'Ongoing';
      const r = await c.query(
        'INSERT INTO project (company_id, title, description, status, image_url) VALUES ($1,$2,$3,$4,$5) RETURNING project_id',
        [rnd(comps), 'Project ' + rnd(FIRST) + ' ' + i, 'Sample interior design project.', st, '/img/sample.jpg']
      );
      projs.push(r.rows[0].project_id);
      await c.query('INSERT INTO project_category (project_id, category_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [r.rows[0].project_id, rnd(catIds)]);
    }
    console.log('✅ Projects inserted');

    // 6. Project Views (for the interest metrics)
    for (let i = 0; i < 200; i++) {
      await c.query('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [rnd(projs), rnd(accs['Customer'])]);
    }
    console.log('✅ Views inserted');

    // 7. Favourites (for shortlists)
    for (let i = 0; i < 100; i++) {
      await c.query('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [rnd(accs['Customer']), rnd(projs)]);
    }
    console.log('✅ Favourites inserted');

    await c.query('COMMIT');
    console.log('--- SEED COMPLETE & COMMITTED ---');
  } catch (e) {
    await c.query('ROLLBACK');
    console.error('❌ SEED FAILED (ROLLED BACK):', e.message);
  } finally {
    c.release();
    await pool.end();
  }
})();