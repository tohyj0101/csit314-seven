const router = require('express').Router();
const pool = require('../config/db');
const auth = require('../middleware/authGuard');
const role = require('../middleware/roleGuard');
const { Auth, UserAccountCtrl: U, CompanyCtrl: C, ProjectCtrl: P, FavouriteCtrl: F, CategoryCtrl: Cat, ReportCtrl: R } = require('./controllers');

const A = role('User Admin'), D = role('Interior Designer'), Cu = role('Customer'), M = role('Platform Manager');

router.post('/auth/login', Auth.login);
router.post('/auth/logout', Auth.logout);
router.get('/auth/me', Auth.me);

router.get('/users', auth, A, U.list);
router.get('/users/search', auth, A, U.search);
router.post('/users', auth, A, U.create);
router.get('/users/:id', auth, A, U.get);
router.put('/users/:id', auth, A, U.update);
router.patch('/users/:id/suspend', auth, A, U.suspend);
router.patch('/users/:id/activate', auth, A, U.activate);

router.get('/profiles', auth, A, async (req, res) => {
  res.json({ profiles: (await pool.query('SELECT * FROM user_profile ORDER BY profile_id')).rows });
});

router.get('/companies', auth, D, C.list);
router.get('/companies/search', auth, D, C.search);
router.post('/companies', auth, D, C.create);
router.get('/companies/:id', auth, D, C.get);
router.put('/companies/:id', auth, D, C.update);
router.delete('/companies/:id', auth, D, C.remove);

router.get('/projects/public/search', auth, Cu, P.publicSearch);
router.get('/projects/public/:id', auth, Cu, P.publicGet);
router.post('/projects', auth, D, P.create);
router.get('/projects/company/:companyId', auth, D, P.listByCompany);
router.get('/projects/company/:companyId/search', auth, D, P.searchByCompany);
router.get('/projects/company/:companyId/interest', auth, D, P.interest);
router.put('/projects/:id', auth, D, P.update);
router.delete('/projects/:id', auth, D, P.remove);

router.get('/favourites', auth, Cu, F.list);
router.get('/favourites/search', auth, Cu, F.search);
router.post('/favourites', auth, Cu, F.add);
router.delete('/favourites/:projectId', auth, Cu, F.remove);

router.get('/categories', auth, Cat.list);
router.get('/categories/search', auth, Cat.search);
router.post('/categories', auth, M, Cat.create);
router.get('/categories/:id', auth, Cat.get);
router.put('/categories/:id', auth, M, Cat.update);
router.delete('/categories/:id', auth, M, Cat.remove);

router.get('/reports/:period', auth, M, R.generate);

module.exports = router;