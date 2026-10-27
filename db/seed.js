const pool = require('../config/db');
const { hashPassword } = require('../config/auth');

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
    let userCount = 0;
    for (const role of Object.keys(pids)) {
      for (let i = 1; i <= 30; i++) {
        const u = role.toLowerCase().replace(/\s+/g, '.') + '.' + i;
        await c.query(
          'INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1,$2,$3,$4) ON CONFLICT DO NOTHING',
          [pids[role], u, u + '@example.test', hash]
        );
        userCount++;
      }
    }
    console.log(`✅ Users inserted: ${userCount}`);

    // 3. Companies
    const adminId = (await c.query("SELECT account_id FROM user_account WHERE email='interior.designer.1@example.test'")).rows[0].account_id;
    for (let i = 1; i <= 50; i++) {
      await c.query('INSERT INTO company (owner_id, company_name, address) VALUES ($1,$2,$3)', [adminId, 'Studio ' + i, 'Address ' + i]);
    }

    // 4. Categories
    for (const cat of ['Residential', 'Commercial', 'Kitchen']) {
      await c.query('INSERT INTO category (category_name) VALUES ($1) ON CONFLICT DO NOTHING', [cat]);
    }

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