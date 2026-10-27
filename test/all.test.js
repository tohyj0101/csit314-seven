const request = require('supertest');
const app = require('../server');
const pool = require('../config/db');
const { hashPassword } = require('../config/auth');

let dAgent, cAgent, aAgent, mAgent;

beforeAll(async () => {
  // 0. Targeted cleanup: ONLY delete the accounts created by these tests
  const testEmails = [
    'idtest@example.test', 'custtest@example.test', 'admintest@example.test', 'pmtest@example.test',
    'user+a@example.test', 'user+b@example.test', 'user+c@example.test', 'user+d@example.test'
  ];
  
  for (const email of testEmails) {
    await pool.query("DELETE FROM user_account WHERE email = $1", [email]);
  }
  await pool.query("DELETE FROM company WHERE company_name IN ('Test Studio', 'Test Studio v2', 'ToDel', 'ProjCo')");
  await pool.query("DELETE FROM category WHERE category_name LIKE 'TC%'");

  // 1. Ensure the 4 base profiles exist
  const profiles = [
    ['User Admin', ['user:manage','profile:manage']],
    ['Interior Designer', ['company:manage','project:manage','interest:view']],
    ['Customer', ['project:view','favourite:manage']],
    ['Platform Manager', ['category:manage','report:generate']]
  ];
  for (const [name, perms] of profiles) {
    await pool.query(
      'INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) ON CONFLICT DO NOTHING', 
      [name, perms]
    );
  }

  // 2. Create the test accounts
  const h = await hashPassword('Password123!');
  const P = (await pool.query('SELECT profile_name,profile_id FROM user_profile')).rows.reduce((m, r) => (m[r.profile_name] = r.profile_id, m), {});
  
  for (const [n, u] of [['Interior Designer', 'idtest'], ['Customer', 'custtest'], ['User Admin', 'admintest'], ['Platform Manager', 'pmtest']]) {
    await pool.query('INSERT INTO user_account(profile_id,username,email,password_hash) VALUES ($1,$2,$3,$4)', [P[n], u, u + '@example.test', h]);
  }
  
  // 3. Log in the agents and assign them to the local variables
  dAgent = request.agent(app);
  await dAgent.post('/api/auth/login').send({ email: 'idtest@example.test', password: 'Password123!' });

  cAgent = request.agent(app);
  await cAgent.post('/api/auth/login').send({ email: 'custtest@example.test', password: 'Password123!' });

  aAgent = request.agent(app);
  await aAgent.post('/api/auth/login').send({ email: 'admintest@example.test', password: 'Password123!' });

  mAgent = request.agent(app);
  await mAgent.post('/api/auth/login').send({ email: 'pmtest@example.test', password: 'Password123!' });
});

afterAll(async () => {
  // Targeted cleanup: Leave the seeded users intact!
  const testEmails = [
    'idtest@example.test', 'custtest@example.test', 'admintest@example.test', 'pmtest@example.test',
    'user+a@example.test', 'user+b@example.test', 'user+c@example.test', 'user+d@example.test'
  ];
  
  for (const email of testEmails) {
    await pool.query("DELETE FROM user_account WHERE email = $1", [email]);
  }
  await pool.query("DELETE FROM company WHERE company_name IN ('Test Studio', 'Test Studio v2', 'ToDel', 'ProjCo')");
  await pool.query("DELETE FROM category WHERE category_name LIKE 'TC%'");
  await pool.end();
});

describe('auth (#1-14,#35,#36,#39,#40)', () => {
  test('missing→400', async () => { expect((await request(app).post('/api/auth/login').send({})).status).toBe(400) });
  test('wrong→401', async () => { expect((await request(app).post('/api/auth/login').send({ email: 'idtest@example.test', password: 'x' })).status).toBe(401) });
  test('login+me', async () => { const ag = request.agent(app); await ag.post('/api/auth/login').send({ email: 'idtest@example.test', password: 'Password123!' }); expect((await ag.get('/api/auth/me')).body.user.username).toBe('idtest') });
  test('logout', async () => { const ag = request.agent(app); await ag.post('/api/auth/login').send({ email: 'idtest@example.test', password: 'Password123!' }); await ag.post('/api/auth/logout'); expect((await ag.get('/api/auth/me')).status).toBe(401) });
});

describe('user account (#3-7)', () => {
  let custProfileId;
  beforeAll(async () => {
    custProfileId = (await pool.query("SELECT profile_id FROM user_profile WHERE profile_name = 'Customer'")).rows[0].profile_id;
  });

  test('#3 create', async () => { expect((await aAgent.post('/api/users').send({ profile_id: custProfileId, username: 'user+a', email: 'user+a@example.test', password: 'Password123!' })).status).toBe(201) });
  test('#3 dup→409', async () => { expect((await aAgent.post('/api/users').send({ profile_id: custProfileId, username: 'user+b', email: 'user+a@example.test', password: 'x' })).status).toBe(409) });
  test('#3 missing→400', async () => { expect((await aAgent.post('/api/users').send({ username: 'x' })).status).toBe(400) });
  // FIXED: Using .query() to properly encode the '+' sign
  test('#5/#7 search', async () => { expect((await aAgent.get('/api/users/search').query({ q: 'user+a' })).body.users.length).toBeGreaterThanOrEqual(1) });
  test('#6 double suspend→409', async () => { const id = (await aAgent.post('/api/users').send({ profile_id: custProfileId, username: 'user+c', email: 'user+c@example.test', password: 'Password123!' })).body.user.account_id; expect((await aAgent.patch('/api/users/' + id + '/suspend')).status).toBe(200); expect((await aAgent.patch('/api/users/' + id + '/suspend')).status).toBe(409) });
  test('#4 update', async () => { const id = (await aAgent.post('/api/users').send({ profile_id: custProfileId, username: 'user+d', email: 'user+d@example.test', password: 'Password123!' })).body.user.account_id; expect((await aAgent.put('/api/users/' + id).send({ username: 'user+d-up' })).body.user.username).toBe('user+d-up') });
});

describe('company (#15-19)', () => {
  let companyId;
  test('#15 create', async () => { const r = await dAgent.post('/api/companies').send({ company_name: 'Test Studio', address: '1 St', contact_no: '+65' }); expect(r.status).toBe(201); companyId = r.body.company.company_id });
  test('#15 missing→400', async () => { expect((await dAgent.post('/api/companies').send({ address: 'x' })).status).toBe(400) });
  test('#16 get', async () => { expect((await dAgent.get('/api/companies/' + companyId)).status).toBe(200) });
  test('#17 update', async () => { expect((await dAgent.put('/api/companies/' + companyId).send({ company_name: 'Test Studio v2', address: '2', contact_no: '3' })).body.company.company_name).toBe('Test Studio v2') });
  test('#19 search', async () => { expect((await dAgent.get('/api/companies/search?q=Test Studio v2')).body.companies.length).toBeGreaterThanOrEqual(1); expect((await dAgent.get('/api/companies/search?q=zzznomatch')).body.companies.length).toBe(0) });
  test('#18 delete', async () => { const id = (await dAgent.post('/api/companies').send({ company_name: 'ToDel' })).body.company.company_id; expect((await dAgent.delete('/api/companies/' + id)).status).toBe(200) });
});

describe('projects/favourites (#20-38)', () => {
  let companyId, oId, cId;
  beforeAll(async () => { companyId = (await dAgent.post('/api/companies').send({ company_name: 'ProjCo' })).body.company.company_id; });
  test('#20 create ongoing', async () => { const r = await dAgent.post('/api/projects').send({ company_id: companyId, title: 'Living Room Revamp', description: 'd', status: 'Ongoing' }); expect(r.status).toBe(201); oId = r.body.project.project_id });
  test('#25 create completed', async () => { const r = await dAgent.post('/api/projects').send({ company_id: companyId, title: 'Past Kitchen', description: 'd', status: 'Completed' }); expect(r.status).toBe(201); cId = r.body.project.project_id });
  test('#21/#26 filter', async () => { const o = (await dAgent.get('/api/projects/company/' + companyId + '?status=Ongoing')).body.projects; expect(o.every(p => p.status === 'Ongoing')).toBe(true) });
  test('#22 update', async () => { expect((await dAgent.put('/api/projects/' + oId).send({ title: 'Living Room Revamp v2' })).body.project.title).toBe('Living Room Revamp v2') });
  test('#22 non-owner→403', async () => { expect((await cAgent.put('/api/projects/' + oId).send({ title: 'hack' })).status).toBe(403) });
  test('#24/#29 search', async () => { expect((await dAgent.get('/api/projects/company/' + companyId + '/search?q=Revamp&status=Ongoing')).body.projects.length).toBeGreaterThanOrEqual(1) });
  test('#30 public search', async () => { expect((await cAgent.get('/api/projects/public/search?q=Revamp')).body.projects.length).toBeGreaterThanOrEqual(1) });
  test('#31/#37 view recorded', async () => { const b = (await dAgent.get('/api/projects/company/' + companyId + '/interest')).body.interest.find(i => i.project_id === oId).views; await cAgent.get('/api/projects/public/' + oId); const a = (await dAgent.get('/api/projects/company/' + companyId + '/interest')).body.interest.find(i => i.project_id === oId).views; expect(a).toBe(b + 1) });
  test('#32 save favourite (idempotent)', async () => { expect((await cAgent.post('/api/favourites').send({ project_id: oId })).status).toBe(201); expect((await cAgent.post('/api/favourites').send({ project_id: oId })).status).toBe(201) });
  test('#34 list & #33 search', async () => { expect((await cAgent.get('/api/favourites')).body.favourites.length).toBe(1); expect((await cAgent.get('/api/favourites/search?q=Revamp')).body.favourites.length).toBe(1) });
  test('#38 shortlist count', async () => { const r = (await dAgent.get('/api/projects/company/' + companyId + '/interest')).body.interest.find(i => i.project_id === oId); expect(r.shortlists).toBe(1) });
  test('#34 remove', async () => { expect((await cAgent.delete('/api/favourites/' + oId)).status).toBe(200) });
  test('#23 delete completed', async () => { expect((await dAgent.delete('/api/projects/' + cId)).status).toBe(200) });
});

describe('categories/reports (#41-48)', () => {
  let cid;
  test('#41 create', async () => { const r = await mAgent.post('/api/categories').send({ category_name: 'TC One', description: 'x' }); expect(r.status).toBe(201); cid = r.body.category.category_id });
  test('#42 view', async () => { expect((await mAgent.get('/api/categories/' + cid)).status).toBe(200) });
  test('#43 update', async () => { expect((await mAgent.put('/api/categories/' + cid).send({ category_name: 'TC One v2', description: 'y' })).body.category.category_name).toBe('TC One v2') });
  test('#45 search', async () => { expect((await mAgent.get('/api/categories/search?q=TC One')).body.categories.length).toBeGreaterThanOrEqual(1) });
  test('#44 delete', async () => { expect((await mAgent.delete('/api/categories/' + cid)).status).toBe(200) });
  ['daily', 'weekly', 'monthly'].forEach(p => test('#46/47/48 ' + p, async () => { const r = await mAgent.get('/api/reports/' + p); expect(r.status).toBe(200); expect(r.body.summary).toHaveProperty('project_views') }));
  test('invalid period→400', async () => { expect((await mAgent.get('/api/reports/yearly')).status).toBe(400) });
});