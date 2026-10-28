// =============================================================================
// CSIT314 T04: Seven - Database Entity Layer
// =============================================================================
// This file is the "Entity Layer" in the BCE architecture. 
// It contains all the raw SQL queries. It has no knowledge of HTTP requests, 
// sessions, or UI. It simply accepts data, runs a query, and returns rows.

const pool = require('../config/db');

// Helper function to execute a query and return only the rows array
const Q = async (s, a) => (await pool.query(s, a)).rows;

// ==========================================
// USER ACCOUNT ENTITY (US #1 - #7)
// ==========================================
const U = {
  // Fetches a single user by their ID (Used in View and Edit flows)
  findById: async id => Q(`SELECT a.account_id, a.username, a.email, a.status, a.profile_id, p.profile_name FROM user_account a JOIN user_profile p ON p.profile_id=a.profile_id WHERE a.account_id=$1`, [id]).then(r => r[0] || null),
  
  // Fetches a user by email (Used during Login and checking for duplicate emails)
  findByEmail: async e => Q(`SELECT a.*, p.profile_name FROM user_account a JOIN user_profile p ON p.profile_id=a.profile_id WHERE a.email=$1`, [e]).then(r => r[0] || null),
  
  // Inserts a new user into the database (US #3)
  create: async f => Q(`INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1,$2,$3,$4) RETURNING account_id, username, email, status, profile_id`, [f.profile_id, f.username, f.email, f.password_hash]).then(r => r[0]),
  
  // Updates user details (US #4). 
  // COALESCE allows partial updates: if a field is null, it keeps the existing database value.
  // This is crucial for the password: if no new password is provided, it stays the same.
  update: async (id, f) => {
    return Q(`
      UPDATE user_account SET 
        username=COALESCE($2, username), 
        email=COALESCE($3, email), 
        profile_id=COALESCE($4, profile_id), 
        password_hash=COALESCE($5, password_hash), 
        updated_at=now() 
      WHERE account_id=$1 
      RETURNING account_id, username, email, status, profile_id
    `, [id, f.username || null, f.email || null, f.profile_id || null, f.password_hash || null]).then(r => r[0] || null);
  },
  
  // Toggles account status (US #6)
  setStatus: async (id, s) => Q('UPDATE user_account SET status=$2, updated_at=now() WHERE account_id=$1 RETURNING account_id, username, email, status', [id, s]).then(r => r[0] || null),
  
  // Search using ILIKE for case-insensitive matching (US #7)
  search: async t => Q(`SELECT a.account_id, a.username, a.email, a.status, p.profile_name FROM user_account a JOIN user_profile p ON p.profile_id=a.profile_id WHERE a.username ILIKE $1 OR a.email ILIKE $1 ORDER BY a.account_id LIMIT 100`, ['%'+t+'%']),
  
  // Fetches all users for the default table view
  all: async () => Q(`SELECT a.account_id, a.username, a.email, a.status, p.profile_name FROM user_account a JOIN user_profile p ON p.profile_id=a.profile_id ORDER BY a.account_id LIMIT 500`)
};

// ==========================================
// USER PROFILE ENTITY (US #8 - #12)
// ==========================================
const Prof = {
  create: async f => Q('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *', [f.profile_name, f.permissions]).then(r => r[0]),
  all: async () => Q('SELECT * FROM user_profile ORDER BY profile_id'),
  update: async (id, f) => Q(`UPDATE user_profile SET profile_name = COALESCE($2, profile_name), permissions = COALESCE($3, permissions) WHERE profile_id = $1 RETURNING *`, [id, f.profile_name || null, f.permissions || null]).then(r => r[0] || null),
  findById: async id => Q('SELECT * FROM user_profile WHERE profile_id = $1', [id]).then(r => r[0] || null),
  setStatus: async (id, s) => Q('UPDATE user_profile SET status=$2 WHERE profile_id=$1 RETURNING *', [id, s]).then(r => r[0] || null),
  search: async t => Q('SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id', ['%'+t+'%'])
};

// ==========================================
// COMPANY ENTITY (US #15 - #19)
// ==========================================
const C = {
  create: async f => Q('INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1,$2,$3,$4) RETURNING *', [f.owner_id, f.company_name, f.address, f.contact_no]).then(r => r[0]),
  byOwner: async id => Q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [id]),
  findById: async id => Q('SELECT * FROM company WHERE company_id=$1', [id]).then(r => r[0] || null),
  update: async (id, f) => Q('UPDATE company SET company_name=$2, address=$3, contact_no=$4, updated_at=now() WHERE company_id=$1 RETURNING *', [id, f.company_name, f.address, f.contact_no]).then(r => r[0] || null),
  remove: async id => pool.query('DELETE FROM company WHERE company_id=$1', [id]),
  search: async (oid, t) => Q('SELECT * FROM company WHERE owner_id=$1 AND (company_name ILIKE $2 OR address ILIKE $2) ORDER BY company_id LIMIT 100', [oid, '%'+t+'%'])
};

// ==========================================
// PROJECT ENTITY (US #20 - #29, #37, #38)
// ==========================================
const P = {
  create: async f => Q('INSERT INTO project (company_id, title, description, status, image_url) VALUES ($1,$2,$3,$4,$5) RETURNING *', [f.company_id, f.title, f.description, f.status, f.image_url]).then(r => r[0]),
  findById: async id => Q('SELECT * FROM project WHERE project_id=$1', [id]).then(r => r[0] || null),
  byCompany: async (cid, s) => Q('SELECT * FROM project WHERE company_id=$1 AND ($2::text IS NULL OR status=$2) ORDER BY project_id DESC', [cid, s||null]),
  update: async (id, f) => Q('UPDATE project SET title=COALESCE($2,title), description=COALESCE($3,description), status=COALESCE($4,status), image_url=COALESCE($5,image_url), updated_at=now() WHERE project_id=$1 RETURNING *', [id, f.title||null, f.description||null, f.status||null, f.image_url||null]).then(r => r[0] || null),
  remove: async id => pool.query('DELETE FROM project WHERE project_id=$1', [id]),
  searchByCompany: async (cid, t, s) => Q('SELECT * FROM project WHERE company_id=$1 AND ($3::text IS NULL OR status=$3) AND (title ILIKE $2 OR description ILIKE $2) ORDER BY project_id DESC', [cid, '%'+t+'%', s||null]),
  publicSearch: async t => Q('SELECT p.*, c.company_name FROM project p JOIN company c ON c.company_id=p.company_id WHERE p.title ILIKE $1 OR p.description ILIKE $1 ORDER BY p.project_id DESC LIMIT 100', ['%'+t+'%']),
  // Records a view whenever a customer clicks a project. This feeds the Metrics in US #37.
  recordView: async (pid, cid) => pool.query('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [pid, cid||null]),
  // Aggregates Views and Shortlists for the Designer Dashboard (US #37, #38)
  interest: async cid => Q('SELECT p.project_id, p.title, (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id) AS views, (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id=p.project_id) AS shortlists FROM project p WHERE p.company_id=$1 ORDER BY p.project_id DESC', [cid])
};

// ==========================================
// FAVOURITE ENTITY (US #32 - #34)
// ==========================================
const Fv = {
  add: async (cid, pid) => Q('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING RETURNING *', [cid, pid]).then(r => r[0] || null),
  list: async cid => Q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, p.image_url, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 ORDER BY f.saved_at DESC', [cid]),
  search: async (cid, t) => Q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, p.image_url FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)', [cid, '%'+t+'%']),
  remove: async (cid, pid) => pool.query('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [cid, pid])
};

// ==========================================
// CATEGORY ENTITY (US #41 - #45)
// ==========================================
const Cat = {
  create: async f => Q('INSERT INTO category (category_name, description) VALUES ($1,$2) RETURNING *', [f.category_name, f.description]).then(r => r[0]),
  all: async () => Q('SELECT * FROM category ORDER BY category_name'),
  findById: async id => Q('SELECT * FROM category WHERE category_id=$1', [id]).then(r => r[0] || null),
  update: async (id, f) => Q('UPDATE category SET category_name=COALESCE($2,category_name), description=COALESCE($3,description) WHERE category_id=$1 RETURNING *', [id, f.category_name||null, f.description||null]).then(r => r[0] || null),
  remove: async id => pool.query('DELETE FROM category WHERE category_id=$1', [id]),
  search: async t => Q('SELECT * FROM category WHERE category_name ILIKE $1 ORDER BY category_name', ['%'+t+'%'])
};

// ==========================================
// REPORT ENTITY (US #46 - #48)
// ==========================================
const R = {
  generate: async p => {
    const iv = p === 'daily' ? '1 day' : p === 'weekly' ? '7 days' : '30 days';
    const summary = (await Q(`SELECT (SELECT COUNT(*)::int FROM user_account WHERE created_at >= now() - ($1)::interval) AS new_users, (SELECT COUNT(*)::int FROM project_view WHERE viewed_at >= now() - ($1)::interval) AS project_views, (SELECT COUNT(*)::int FROM favourite WHERE saved_at >= now() - ($1)::interval) AS shortlists, (SELECT COUNT(*)::int FROM project WHERE created_at >= now() - ($1)::interval) AS new_projects`, [iv]))[0];
    const topProjects = await Q(`SELECT p.title, (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id AND v.viewed_at >= now() - ($1)::interval) AS views FROM project p ORDER BY views DESC LIMIT 10`, [iv]);
    return { period: p, summary, topProjects };
  }
};

module.exports = { UserAccount: U, UserProfile: Prof, Company: C, Project: P, Favourite: Fv, Category: Cat, Report: R };