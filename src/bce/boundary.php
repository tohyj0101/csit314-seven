<?php
declare(strict_types=1);

$r = new Router();

$A   = role_guard('User Admin');
$D   = role_guard('Interior Designer');
$Cu  = role_guard('Customer');
$M   = role_guard('Platform Manager');
$auth = auth_guard();

// -------- US #1, #2, #13, #14, #35, #36, #39, #40 – Auth --------
$r->post('/auth/login',  [], fn() => LoginController::login());
$r->post('/auth/logout', [], fn() => LogoutController::logout());
$r->get ('/auth/me',     [], fn() => LogoutController::me());

// -------- US #8–#12 – User Profiles (Admin) --------
$r->get   ('/profiles',             [$auth, $A], fn() => SearchProfileController::list());
$r->get   ('/profiles/search',      [$auth, $A], fn() => SearchProfileController::search());
$r->post  ('/profiles',             [$auth, $A], fn() => CreateProfileController::create());
$r->get   ('/profiles/:id',         [$auth, $A], fn($p) => ViewProfileController::view($p));
$r->put   ('/profiles/:id',         [$auth, $A], fn($p) => EditProfileController::update($p));
$r->patch ('/profiles/:id/suspend', [$auth, $A], fn($p) => SuspendProfileController::suspend($p));
$r->patch ('/profiles/:id/activate',[$auth, $A], fn($p) => SuspendProfileController::activate($p));

// -------- US #3–#7 – User Accounts (Admin) --------
$r->get   ('/users',                [$auth, $A], fn() => SearchUserAccController::list());
$r->get   ('/users/search',         [$auth, $A], fn() => SearchUserAccController::search());
$r->post  ('/users',                [$auth, $A], fn() => CreateUserAccController::create());
$r->get   ('/users/:id',            [$auth, $A], fn($p) => ViewUserAccController::view($p));
$r->put   ('/users/:id',            [$auth, $A], fn($p) => UpdateUserAccController::update($p));
$r->patch ('/users/:id/suspend',    [$auth, $A], fn($p) => SuspendUserAccController::suspend($p));
$r->patch ('/users/:id/activate',   [$auth, $A], fn($p) => SuspendUserAccController::activate($p));

// -------- US #15–#19 – Company Information (Designer) --------
$r->get   ('/companies',            [$auth, $D], fn() => SearchCompanyController::list());
$r->get   ('/companies/search',     [$auth, $D], fn() => SearchCompanyController::search());
$r->post  ('/companies',            [$auth, $D], fn() => CreateCompanyController::create());
$r->get   ('/companies/:id',        [$auth, $D], fn($p) => ViewCompanyController::view($p));
$r->put   ('/companies/:id',        [$auth, $D], fn($p) => UpdateCompanyController::update($p));
$r->delete('/companies/:id',        [$auth, $D], fn($p) => DeleteCompanyController::remove($p));

// -------- US #20–#31, #37, #38 – Projects --------
$r->get   ('/projects/public/search',              [$auth, $Cu], fn() => SearchPublicProjectController::search());
$r->get   ('/projects/public/:id',                 [$auth, $Cu], fn($p) => ViewPublicProjectController::view($p));
$r->post  ('/projects',                            [$auth, $D],  fn() => CreateProjectController::create());
$r->get   ('/projects/company/:companyId',         [$auth, $D],  fn($p) => ViewProjectController::listByCompany($p));
$r->get   ('/projects/company/:companyId/search',  [$auth, $D],  fn($p) => SearchProjectController::search($p));
$r->get   ('/projects/company/:companyId/completed/search', [$auth, $D], fn($p) => SearchCompletedController::search($p));
$r->get   ('/projects/company/:companyId/interest',[$auth, $D],  fn($p) => ProjectInterestController::interest($p));
$r->post  ('/projects/completed',                  [$auth, $D],  fn() => CreateCompletedProjController::create());
$r->get   ('/projects/:id',                        [$auth, $D],  fn($p) => ViewProjectController::view($p));
$r->put   ('/projects/:id',                        [$auth, $D],  fn($p) => UpdateProjectController::update($p));
$r->put   ('/projects/:id/completed',              [$auth, $D],  fn($p) => UpdateCompletedProjController::update($p));
$r->delete('/projects/:id',                        [$auth, $D],  fn($p) => DeleteProjectController::remove($p));
$r->delete('/projects/:id/completed',              [$auth, $D],  fn($p) => DeleteCompletedProjController::remove($p));

// -------- US #32–#34 – Favourites (Customer) --------
$r->get   ('/favourites',           [$auth, $Cu], fn() => FavouriteController::list());
$r->get   ('/favourites/search',    [$auth, $Cu], fn() => FavouriteController::search());
$r->post  ('/favourites',           [$auth, $Cu], fn() => FavouriteController::add());
$r->delete('/favourites/:projectId',[$auth, $Cu], fn($p) => FavouriteController::remove($p));

// -------- US #41–#45 – Categories (Manager) --------
$r->get   ('/categories',           [$auth], fn() => ViewCategoryController::list());
$r->get   ('/categories/search',    [$auth], fn() => SearchCategoryController::search());
$r->get   ('/categories/:id',       [$auth], fn($p) => ViewCategoryController::view($p));
$r->post  ('/categories',           [$auth, $M], fn() => CreateCategoryController::create());
$r->put   ('/categories/:id',       [$auth, $M], fn($p) => UpdateCategoryController::update($p));
$r->delete('/categories/:id',       [$auth, $M], fn($p) => DeleteCategoryController::remove($p));

// -------- US #46–#48 – Reports (Manager) --------
$r->get   ('/reports/:period', [$auth, $M], fn($p) => ReportController::generate($p));

return $r;