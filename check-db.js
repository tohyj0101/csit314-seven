const pool = require('./config/db');

async function check() {
  console.log('--- DATABASE DIAGNOSTIC ---');
  console.log('Connecting to Database:', process.env.DB_NAME);
  console.log('Connecting as User:', process.env.DB_USER);
  
  try {
    const tables = await pool.query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    console.log('Tables found:', tables.rows.map(t => t.table_name).join(', '));
    
    const u = await pool.query('SELECT COUNT(*) FROM user_account');
    console.log('Total users in user_account:', u.rows[0].count);
    
    const p = await pool.query('SELECT COUNT(*) FROM user_profile');
    console.log('Total profiles in user_profile:', p.rows[0].count);
  } catch (e) {
    console.log('❌ SQL Error:', e.message);
  }
  process.exit();
}
check();