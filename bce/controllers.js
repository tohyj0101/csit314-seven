const { UserAccount: U, Company: C, Project: P, Favourite: Fv, Category: Cat, Report: R } = require('./entities');
const { hashPassword, verifyPassword } = require('../config/auth');

const Auth = {
  login: async (req, res) => {
    const { email, password } = req.body;
    if (!email || !password) return res.status(400).json({ error: 'Email and password required' });
    const u = await U.findByEmail(email);
    if (!u) return res.status(401).json({ error: 'Invalid credentials' });
    if (u.status === 'Suspended') return res.status(403).json({ error: 'Account suspended' });
    if (!(await verifyPassword(password, u.password_hash))) return res.status(401).json({ error: 'Invalid credentials' });
    req.session.user = { account_id: u.account_id, username: u.username, role: u.profile_name };
    res.json({ user: req.session.user });
  },
  logout: (req, res) => req.session.destroy(() => res.json({ ok: true })),
  me: (req, res) => req.session && req.session.user ? res.json({ user: req.session.user }) : res.status(401).json({ error: 'Not authenticated' })
};

const Uc = {
  list: async (req, res) => res.json({ users: await U.all() }),
  get: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'User not found' });
    res.json({ user: u });
  },
  create: async (req, res) => {
    const { profile_id, username, email, password } = req.body;
    if (!profile_id || !username || !email || !password) return res.status(400).json({ error: 'All fields required' });
    if (await U.findByEmail(email)) return res.status(409).json({ error: 'Email already registered' });
    res.status(201).json({ user: await U.create({ profile_id, username, email, password_hash: await hashPassword(password) }) });
  },
  update: async (req, res) => {
    const u = await U.update(req.params.id, req.body);
    if (!u) return res.status(404).json({ error: 'User not found' });
    res.json({ user: u });
  },
  suspend: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'User not found' });
    if (u.status === 'Suspended') return res.status(409).json({ error: 'Already suspended' });
    res.json({ user: await U.setStatus(req.params.id, 'Suspended') });
  },
  activate: async (req, res) => {
    const u = await U.findById(req.params.id);
    if (!u) return res.status(404).json({ error: 'User not found' });
    res.json({ user: await U.setStatus(req.params.id, 'Active') });
  },
  search: async (req, res) => res.json({ users: await U.search(req.query.q || '') })
};

const Cc = {
  list: async (req, res) => res.json({ companies: await C.byOwner(req.session.user.account_id) }),
  get: async (req, res) => {
    const c = await C.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Company not found' });
    res.json({ company: c });
  },
  create: async (req, res) => {
    const { company_name, address, contact_no } = req.body;
    if (!company_name) return res.status(400).json({ error: 'Company name required' });
    res.status(201).json({ company: await C.create({ owner_id: req.session.user.account_id, company_name, address, contact_no }) });
  },
  update: async (req, res) => {
    const c = await C.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Not found' });
    if (c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    res.json({ company: await C.update(req.params.id, req.body) });
  },
  remove: async (req, res) => {
    const c = await C.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Not found' });
    if (c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    await C.remove(req.params.id);
    res.json({ ok: true });
  },
  search: async (req, res) => res.json({ companies: await C.search(req.session.user.account_id, req.query.q || '') })
};

const assertOwner = async (pid, uid) => {
  const p = await P.findById(pid);
  if (!p) return { error: 404 };
  const c = await C.findById(p.company_id);
  if (!c || c.owner_id !== uid) return { error: 403 };
  return { p };
};

const Pc = {
  create: async (req, res) => {
    const { company_id, title, description, status, image_url } = req.body;
    const c = await C.findById(company_id);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    if (!title) return res.status(400).json({ error: 'Title required' });
    res.status(201).json({ project: await P.create({ company_id, title, description, status: status === 'Completed' ? 'Completed' : 'Ongoing', image_url }) });
  },
  listByCompany: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    res.json({ projects: await P.byCompany(req.params.companyId, req.query.status) });
  },
  update: async (req, res) => {
    const x = await assertOwner(req.params.id, req.session.user.account_id);
    if (x.error) return res.status(x.error).json({ error: 'Not found or forbidden' });
    res.json({ project: await P.update(req.params.id, req.body) });
  },
  remove: async (req, res) => {
    const x = await assertOwner(req.params.id, req.session.user.account_id);
    if (x.error) return res.status(x.error).json({ error: 'Not found or forbidden' });
    await P.remove(req.params.id);
    res.json({ ok: true });
  },
  searchByCompany: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    res.json({ projects: await P.searchByCompany(req.params.companyId, req.query.q || '', req.query.status || null) });
  },
  publicSearch: async (req, res) => res.json({ projects: await P.publicSearch(req.query.q || '') }),
  publicGet: async (req, res) => {
    const p = await P.findById(req.params.id);
    if (!p) return res.status(404).json({ error: 'Not found' });
    await P.recordView(p.project_id, req.session.user ? req.session.user.account_id : null);
    res.json({ project: p });
  },
  interest: async (req, res) => {
    const c = await C.findById(req.params.companyId);
    if (!c || c.owner_id !== req.session.user.account_id) return res.status(403).json({ error: 'Forbidden' });
    res.json({ interest: await P.interest(req.params.companyId) });
  }
};

const Fc = {
  add: async (req, res) => {
    if (!req.body.project_id) return res.status(400).json({ error: 'project_id required' });
    await Fv.add(req.session.user.account_id, req.body.project_id);
    res.status(201).json({ ok: true });
  },
  list: async (req, res) => res.json({ favourites: await Fv.list(req.session.user.account_id) }),
  search: async (req, res) => res.json({ favourites: await Fv.search(req.session.user.account_id, req.query.q || '') }),
  remove: async (req, res) => {
    await Fv.remove(req.session.user.account_id, req.params.projectId);
    res.json({ ok: true });
  }
};

const Catc = {
  list: async (req, res) => res.json({ categories: await Cat.all() }),
  get: async (req, res) => {
    const c = await Cat.findById(req.params.id);
    if (!c) return res.status(404).json({ error: 'Not found' });
    res.json({ category: c });
  },
  create: async (req, res) => {
    if (!req.body.category_name) return res.status(400).json({ error: 'Name required' });
    res.status(201).json({ category: await Cat.create(req.body) });
  },
  update: async (req, res) => {
    const c = await Cat.update(req.params.id, req.body);
    if (!c) return res.status(404).json({ error: 'Not found' });
    res.json({ category: c });
  },
  remove: async (req, res) => {
    await Cat.remove(req.params.id);
    res.json({ ok: true });
  },
  search: async (req, res) => res.json({ categories: await Cat.search(req.query.q || '') })
};

const Rc = {
  generate: async (req, res) => {
    const p = (req.params.period || '').toLowerCase();
    if (!['daily', 'weekly', 'monthly'].includes(p)) return res.status(400).json({ error: 'Use daily|weekly|monthly' });
    res.json(await R.generate(p));
  }
};

module.exports = { Auth, UserAccountCtrl: Uc, CompanyCtrl: Cc, ProjectCtrl: Pc, FavouriteCtrl: Fc, CategoryCtrl: Catc, ReportCtrl: Rc };