const pool = require('../config/db');
const { hashPassword } = require('../config/auth');

(async () => {
  const c = await pool.connect();
  try {
    console.log('--- STARTING CLEAN SEED ---');
    await c.query('BEGIN');
    
    // 1. Wipe all existing data cleanly and reset IDs
    await c.query(`
      TRUNCATE TABLE 
        project_view, favourite, project_category, category, project, company, user_account, user_profile 
      RESTART IDENTITY CASCADE;
    `);
    console.log('✅ Database wiped clean');

    // 2. Insert exactly 4 Profiles
    const profiles = [
      ['User Admin', ['user:manage', 'profile:manage']],
      ['Interior Designer', ['company:manage', 'project:manage', 'interest:view']],
      ['Customer', ['project:view', 'favourite:manage']],
      ['Platform Manager', ['category:manage', 'report:generate']]
    ];
    const pids = {};
    for (const [name, perms] of profiles) {
      const r = await c.query('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING profile_id', [name, perms]);
      pids[name] = r.rows[0].profile_id;
    }

    // 3. Insert exactly 4 Users (1 per role)
    const hash = await hashPassword('Password123!');
    const users = [
      ['User Admin', 'admin@example.test'],
      ['Interior Designer', 'designer@example.test'],
      ['Customer', 'customer@example.test'],
      ['Platform Manager', 'manager@example.test']
    ];
    const uids = {};
    for (const [role, email] of users) {
      const r = await c.query(
        'INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1, $2, $3, $4) RETURNING account_id',
        [pids[role], role.replace(/\s+/g, '').toLowerCase(), email, hash]
      );
      uids[role] = r.rows[0].account_id;
    }
    console.log('✅ 4 Accounts created');

    // 4. Insert 1 Company for the Designer
    const compRes = await c.query(
      'INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1, $2, $3, $4) RETURNING company_id',
      [uids['Interior Designer'], 'My Design Studio', '123 Orchard Road, Singapore', '+65 9123 4567']
    );
    const companyId = compRes.rows[0].company_id;

    // 5. Insert 3 Categories
    const cats = ['Residential', 'Commercial', 'Kitchen'];
    const catIds = [];
    for (const cat of cats) {
      const r = await c.query('INSERT INTO category (category_name) VALUES ($1) RETURNING category_id', [cat]);
      catIds.push(r.rows[0].category_id);
    }

    // 6. Insert a few Starter Projects for the Designer
    const projects = [
      ['Modern BTO Renovation', 'A sleek 4-room BTO flat.', 'Ongoing', '/img/sample.jpg'],
      ['Luxury Condo Makeover', 'High-end interior design.', 'Completed', '/img/sample.jpg'],
      ['Minimalist Office Space', 'Clean lines and ergonomic design.', 'Ongoing', '/img/sample.jpg']
    ];
    for (const [title, desc, status, img] of projects) {
      const p = await c.query(
        'INSERT INTO project (company_id, title, description, status, image_url) VALUES ($1, $2, $3, $4, $5) RETURNING project_id',
        [companyId, title, desc, status, img]
      );
      // Link to a random category
      await c.query('INSERT INTO project_category (project_id, category_id) VALUES ($1, $2)', [p.rows[0].project_id, catIds[Math.floor(Math.random() * catIds.length)]]);
    }
    console.log('✅ Starter company and projects created');

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