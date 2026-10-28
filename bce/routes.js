// =============================================================================
// CSIT314 T04: Seven - API Boundary Layer (Routes)
// =============================================================================
// This file defines all API endpoints. It acts as the gatekeeper:
// 1. It intercepts HTTP requests.
// 2. It applies security middleware (authGuard, roleGuard) to enforce RBAC.
// 3. It delegates the request to the correct Controller method.

const router = require('express').Router();
const auth = require('../middleware/authGuard');
const role = require('../middleware/roleGuard');

// Import Controllers
const { Auth, UserAccountCtrl: U, UserProfileCtrl: Prof, CompanyCtrl: C, ProjectCtrl: P, FavouriteCtrl: F, CategoryCtrl: Cat, ReportCtrl: R } = require('./controllers');

// Define Role Guards for readability
const A = role('User Admin'), D = role('Interior Designer'), Cu = role('Customer'), M = role('Platform Manager');

// Authentication Routes
router.post('/auth/login', Auth.login);
router.post('/auth/logout', Auth.logout);
router.get('/auth/me', Auth.me);

// Profile Routes (Admin Only)
router.get('/profiles', auth, A, Prof.list);
router.get('/profiles/search', auth, A, Prof.search);
router.post('/profiles', auth, A, Prof.create);
router.get('/profiles/:id', auth, A, Prof.get);
router.put('/profiles/:id', auth, A, Prof.update);
router.patch('/profiles/:id/suspend', auth, A, Prof.suspend);
router.patch('/profiles/:id/activate', auth, A, Prof.activate);

// User Account Routes (Admin Only)
router.get('/users', auth, A, U.list);
router.get('/users/search', auth, A, U.search);
router.post('/users', auth, A, U.create);
router.get('/users/:id', auth, A, U.get);
router.put('/users/:id', auth, A, U.update);
router.patch('/users/:id/suspend', auth, A, U.suspend);
router.patch('/users/:id/activate', auth, A, U.activate);

// Company Routes (Designer Only)
router.get('/companies', auth, D, C.list);
router.get('/companies/search', auth, D, C.search);
router.post('/companies', auth, D, C.create);
router.get('/companies/:id', auth, D, C.get);
router.put('/companies/:id', auth, D, C.update);
router.delete('/companies/:id', auth, D, C.remove);

// Project Routes (Customer & Designer)
router.get('/projects/public/search', auth, Cu, P.publicSearch);
router.get('/projects/public/:id', auth, Cu, P.publicGet);
router.post('/projects', auth, D, P.create);
router.get('/projects/company/:companyId', auth, D, P.listByCompany);
router.get('/projects/company/:companyId/search', auth, D, P.searchByCompany);
router.get('/projects/company/:companyId/interest', auth, D, P.interest);
router.get('/projects/:id', auth, D, P.get);
router.put('/projects/:id', auth, D, P.update);
router.delete('/projects/:id', auth, D, P.remove);

// Favourite Routes (Customer Only)
router.get('/favourites', auth, Cu, F.list);
router.get('/favourites/search', auth, Cu, F.search);
router.post('/favourites', auth, Cu, F.add);
router.delete('/favourites/:projectId', auth, Cu, F.remove);

// Category Routes (Open to all logged in, but only Manager can modify)
router.get('/categories', auth, Cat.list);
router.get('/categories/search', auth, Cat.search);
router.get('/categories/:id', auth, Cat.get);
router.post('/categories', auth, M, Cat.create);
router.put('/categories/:id', auth, M, Cat.update);
router.delete('/categories/:id', auth, M, Cat.remove);

// Report Routes (Manager Only)
router.get('/reports/:period', auth, M, R.generate);

module.exports = router;