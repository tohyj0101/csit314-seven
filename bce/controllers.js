// =============================================================================
// CSIT314 T04: Seven - Backend Control Layer
// =============================================================================
// This file acts as the "Control Layer" in the BCE architecture.
// It receives requests from the Boundary (Routes), applies business rules, 
// performs validation, enforces Role-Based Access Control (RBAC), and calls 
// the Entity layer to interact with the database.

const { UserAccount: U, UserProfile: Prof, Company: C, Project: P, Favourite: Fv, Category: Cat, Report: R } = require('./entities');
const { hashPassword, verifyPassword } = require('../config/auth');

// =============================================================================
// CENTRALIZED ERROR HANDLER (Ethical Consideration & UX)
// =============================================================================
/**
 * Translates raw PostgreSQL error codes into user-friendly, non-technical messages.
 * Why? To prevent leaking database schema details (Security) and to improve UX.
 */
function handleError(res, e) {
  console.error("Backend Error Log:", e.message); // Log for developers
  
  // 23505: Unique Violation (e.g., Duplicate Email or Profile Name)
  if (e.code === '23505') {
    return res.status(409).json({ error: 'A record with this name or email already exists. Please use a different one.' });
  }
  // 23503: Foreign Key Violation (e.g., trying to delete a company that still has projects)
  if (e.code === '23503') {
    return res.status(400).json({ error: 'Cannot complete action because this record is currently in use by another part of the system.' });
  }
  // 23502: Not Null Violation (Missing required field)
  if (e.code === '23502') {
    return res.status(400).json({ error: 'A required field was left blank. Please fill in all mandatory fields.' });
  }
  
  // Default fallback for unknown server errors
  res.status(500).json({ error: 'An unexpected system error occurred. Please try again later.' });
}

// =============================================================================
// AUTHENTICATION CONTROLLER (US #1, #13, #35, #39)
// =============================================================================
const Auth = {
  login: async (req, res) => {
    try {
      const { email, password } = req.body;
      if (!email || !password) return res.status(400).json({ error: 'Please enter both an email and a password.' });
      
      const u = await U.findByEmail(email);
      // Security: We use a generic error message to prevent "User Enumeration" attacks.
      if (!u) return res.status(401).json({ error: 'Invalid email or password.' });
      if (u.status === 'Suspended') return res.status(403).json({ error: 'This account has been suspended. Please contact the administrator.' });
      
      if (!(await verifyPassword(password, u.password_hash))) {
        return res.status(401).json({ error: 'Invalid email or password.' });
      }
      
      req.session.user = { account_id: u.account_id, username: u.username, role: u.profile_name };
      
      req.session.save((err) => {
        if (err) return res.status(500).json({ error: 'Failed to save your login session. Please try again.' });
        res.json({ user: req.session.user });
      });
    } catch (e) { handleError(res, e); }
  },
  logout: (req, res) => req.session.destroy(() => res.json({ ok: true })),
  me: (req, res) => req.session && req.session.user ? res.json({ user: req.session.user }) : res.status(401).json({ error: 'You are not logged in.' })
};

// =============================================================================
// USER PROFILE CONTROLLER (US #8 - #12)
// =============================================================================
const ProfileCtrl = {
  list: async (req, res) => res.json({ profiles: await Prof.all() }),
  create: async (req, res) => {
    try {
      const { profile_name, permissions } = req.body;
      if (!profile_name) return res.status(400).json({ error: 'Profile name cannot be empty.' });
      const p = await Prof.create({ profile_name, permissions: permissions || [] });
      res.status(201).json({ profile: p });
    } catch (e) { handleError(res, e); }
  },
  update: async (req, res) => {
    try {
      const { profile_name, permissions } = req.body;
      if (!profile_name) return res.status(400).json({ error: 'Profile name cannot be empty.' });
      const p = await Prof.update(req.params.id, { profile_name, permissions });
      if (!p) return res.status(404).json({ error: 'The requested profile could not be found.' });
      res.json({ profile: p });
    } catch (e) { handleError(res, e); }
  },
  get: async (req, res) => {
    const p = await Prof.findById(req.params.id);
    if (!p) return res.status(404).json({ error: 'The requested profile could not be found.' });
    res.json({ profile: p });
  },
  search: async (req, res) => res.json({ profiles: await Prof.search(req.query.q || '') }),
  suspend: async (req, res) => {
    const p = await Prof.findById(req.params.id);
    if (!p) return res.status(404).json({ error: 'The requested profile could not be found.' });
    if (p.status === 'Suspended') return res.status(409).json({ error: 'This profile is already suspended.' });
    res.json({ profile: await Prof.setStatus(req.params.id, 'Suspended') });
  },
  activate: async (req, res) => {
    const p = await Prof.findById(req.params.id);
    if (!p) return res.status(404).json({ error: 'The requested profile could not be found.' });
    res.json({ profile: await Prof.setStatus(req.params.id, 'Active') });
  }
};

// =============================================================================
// USER ACCOUNT CONTROLLER (US #3 - #7)
// =============================================================================
const Uc = {
  list: async (req, res) => res.json({ users: await U.all() }),
  get: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'The requested user account could not be found.' });
    res.json({ user: u });
  },
  create: async (req, res) => {
    try {
      const { profile_id, username, email, password } = req.body;
      if (!profile_id || !username || !email || !password) {
        return res.status(400).json({ error: 'All fields (Username, Email, Password, Profile) are required.' });
      }
      if (await U.findByEmail(email)) {
        return res.status(409).json({ error: 'This email address is already registered to another account.' });
      }
      res.status(201).json({ user: await U.create({ profile_id, username, email, password_hash: await hashPassword(password) }) });
    } catch (e) { handleError(res, e); }
  },
  update: async (req, res) => {
    try {
      const id = req.params.id;
      const fields = req.body;
      
      if (fields.email && !fields.email.includes('@')) {
        return res.status(400).json({ error: 'Please provide a valid email address format (e.g., user@example.com).' });
      }
      
      if (fields.email) {
        const existing = await U.findByEmail(fields.email);
        if (existing && existing.account_id !== parseInt(id)) {
          return res.status(409).json({ error: 'This email address is already being used by another user.' });
        }
      }

      // If a new password is provided, hash it.
      // If blank, delete the field so the Entity layer keeps the old password.
      if (fields.password && fields.password.trim() !== '') {
        fields.password_hash = await hashPassword(fields.password);
      } else {
        delete fields.password_hash; 
      }
      delete fields.password;

      const u = await U.update(id, fields);
      if (!u) return res.status(404).json({ error: 'The requested user account could not be found.' });
      res.json({ user: u });
    } catch (e) { handleError(res, e); }
  },
  suspend: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'User account not found.' });
    if (u.status === 'Suspended') return res.status(409).json({ error: 'This account is already suspended.' });
    res.json({ user: await U.setStatus(req.params.id, 'Suspended') });
  },
  activate: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'User account not found.' });
    res.json({ user: await U.setStatus(req.params.id, 'Active') });
  },
  search: async (req, res) => res.json({ users: await U.search(req.query.q || '') })
};

// =============================================================================
// COMPANY CONTROLLER (US #15 - #19)
// =============================================================================
const Cc = {
  list: async (req, res) => res.json({ companies: await C.byOwner(req.session.user.account_id) }),
  get: async (req, res) => {
    const c = await C.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Company record not found.' });
    res.json({ company: c });
  },
  create: async (req, res) => {
    try {
      const { company_name, address, contact_no } = req.body;
      if (!company_name) return res.status(400).json({ error: 'Company Name is a mandatory field.' });
      res.status(201).json({ company: await C.create({ owner_id: req.session.user.account_id, company_name, address, contact_no }) });
    } catch (e) { handleError(res, e); }
  },
  update: async (req, res) => {
    try {
      const c = await C.findById(req.params.id);
      if (!c) return res.status(404).json({ error: 'Company record not found.' });
      if (c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'You do not have permission to edit this company.' });
      
      if (!req.body.company_name) return res.status(400).json({ error: 'Company Name cannot be left blank.' });
      
      res.json({ company: await C.update(req.params.id, req.body) });
    } catch (e) { handleError(res, e); }
  },
  remove: async (req, res) => {
    try {
      const c = await C.findById(req.params.id);
      if (!c) return res.status(404).json({ error: 'Company record not found.' });
      if (c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'You do not have permission to delete this company.' });
      
      await C.remove(req.params.id);
      res.json({ ok: true });
    } catch (e) { handleError(res, e); }
  },
  search: async (req, res) => res.json({ companies: await C.search(req.session.user.account_id, req.query.q || '') })
};

const assertOwner = async (pid, uid) => {
  const p = await P.findById(pid);
  if (!p) return { error: 404, msg: 'Project not found.' };
  const c = await C.findById(p.company_id);
  if (!c || c.owner_id !== uid) return { error: 403, msg: 'You do not have permission to modify this project.' };
  return { p };
};

// =============================================================================
// PROJECT CONTROLLER (US #20 - #29, #37, #38)
// =============================================================================
const Pc = {
  create: async (req, res) => {
    try {
      const { company_id, title, description, status, image_url } = req.body;
      const c = await C.findById(company_id);
      if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Invalid company selected or you do not own it.' });
      if (!title) return res.status(400).json({ error: 'Project Title is required.' });
      if (!description) return res.status(400).json({ error: 'Project Description is required.' });
      
      res.status(201).json({ project: await P.create({ company_id, title, description, status: status === 'Completed' ? 'Completed' : 'Ongoing', image_url }) });
    } catch (e) { handleError(res, e); }
  },
  get: async (req, res) => {
    const p = await P.findById(req.params.id);
    if (!p) return res.status(404).json({ error: 'Project not found.' });
    res.json({ project: p });
  },
  listByCompany: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'You do not have permission to view these projects.' });
    res.json({ projects: await P.byCompany(req.params.companyId, req.query.status) });
  },
  update: async (req, res) => {
    try {
      const x = await assertOwner(req.params.id, req.session.user.account_id);
      if (x.error) return res.status(x.error).json({ error: x.msg });
      
      if (!req.body.title || !req.body.description) return res.status(400).json({ error: 'Title and Description cannot be empty.' });
      
      res.json({ project: await P.update(req.params.id, req.body) });
    } catch (e) { handleError(res, e); }
  },
  remove: async (req, res) => {
    try {
      const x = await assertOwner(req.params.id, req.session.user.account_id);
      if (x.error) return res.status(x.error).json({ error: x.msg });
      await P.remove(req.params.id);
      res.json({ ok: true });
    } catch (e) { handleError(res, e); }
  },
  searchByCompany: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'You do not have permission to search these projects.' });
    res.json({ projects: await P.searchByCompany(req.params.companyId, req.query.q || '', req.query.status || null) });
  },
  publicSearch: async (req, res) => res.json({ projects: await P.publicSearch(req.query.q || '') }),
  publicGet: async (req, res) => {
    try {
      const p = await P.findById(req.params.id);
      if (!p) return res.status(404).json({ error: 'This project is no longer available.' });
      await P.recordView(p.project_id, req.session.user ? req.session.user.account_id : null);
      res.json({ project: p });
    } catch (e) { handleError(res, e); }
  },
  interest: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'You do not have permission to view these metrics.' });
    res.json({ interest: await P.interest(req.params.companyId) });
  }
};

// =============================================================================
// FAVOURITE CONTROLLER (US #32 - #34)
// =============================================================================
const Fc = {
  add: async (req, res) => {
    try {
      if (!req.body.project_id) return res.status(400).json({ error: 'No project selected to save.' });
      await Fv.add(req.session.user.account_id, req.body.project_id);
      res.status(201).json({ ok: true });
    } catch (e) { handleError(res, e); }
  },
  list: async (req, res) => res.json({ favourites: await Fv.list(req.session.user.account_id) }),
  search: async (req, res) => res.json({ favourites: await Fv.search(req.session.user.account_id, req.query.q || '') }),
  remove: async (req, res) => {
    await Fv.remove(req.session.user.account_id, req.params.projectId);
    res.json({ ok: true });
  }
};

// =============================================================================
// CATEGORY CONTROLLER (US #41 - #45)
// =============================================================================
const Catc = {
  list: async (req, res) => res.json({ categories: await Cat.all() }),
  get: async (req, res) => {
    const c = await Cat.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Category not found.' });
    res.json({ category: c });
  },
  create: async (req, res) => {
    try {
      if (!req.body.category_name) return res.status(400).json({ error: 'Category Name is required.' });
      res.status(201).json({ category: await Cat.create(req.body) });
    } catch (e) { handleError(res, e); }
  },
  update: async (req, res) => {
    try {
      if (!req.body.category_name) return res.status(400).json({ error: 'Category Name cannot be empty.' });
      const c = await Cat.update(req.params.id, req.body);
      if (!c) return res.status(404).json({ error: 'Category not found.' });
      res.json({ category: c });
    } catch (e) { handleError(res, e); }
  },
  remove: async (req, res) => {
    try {
      await Cat.remove(req.params.id);
      res.json({ ok: true });
    } catch (e) { handleError(res, e); }
  },
  search: async (req, res) => res.json({ categories: await Cat.search(req.query.q || '') })
};

// =============================================================================
// REPORT CONTROLLER (US #46 - #48)
// =============================================================================
const Rc = {
  generate: async (req, res) => {
    const p = (req.params.period || '').toLowerCase();
    if (!['daily', 'weekly', 'monthly'].includes(p)) {
      return res.status(400).json({ error: 'Invalid report type. Please select Daily, Weekly, or Monthly.' });
    }
    res.json(await R.generate(p));
  }
};

module.exports = { Auth, UserAccountCtrl: Uc, UserProfileCtrl: ProfileCtrl, CompanyCtrl: Cc, ProjectCtrl: Pc, FavouriteCtrl: Fc, CategoryCtrl: Catc, ReportCtrl: Rc };