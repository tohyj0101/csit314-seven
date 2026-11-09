<?php
declare(strict_types=1);

/** BCE-pure boundary routes. */
$r = new Router();
$A = role_guard('User Admin');
$D = role_guard('Interior Designer');
$Cu = role_guard('Customer');
$M = role_guard('Platform Manager');
$auth = auth_guard();

$r->post('/auth/login', [], fn() => LoginController::login());
$r->post('/auth/logout', [], fn() => LogoutController::LogoutUser());
$r->get('/auth/me', [], fn() => LogoutController::retrieveAuthenticatedUser());

$r->get('/profiles', [$auth, $A], fn() => SearchProfileController::retrieveProfiles());
$r->get('/profiles/search', [$auth, $A], fn() => SearchProfileController::search());
$r->post('/profiles', [$auth, $A], fn() => CreateProfileController::createProfile());
$r->get('/profiles/:id', [$auth, $A], fn($p) => ViewProfileController::retrieveProfile($p));
$r->put('/profiles/:id', [$auth, $A], fn($p) => UpdateUserProfileController::UpdateUserProfile($p));
$r->patch('/profiles/:id/suspend', [$auth, $A], fn($p) => SuspendUserProfileController::suspendProfile($p));
$r->patch('/profiles/:id/activate', [$auth, $A], fn($p) => SuspendUserProfileController::activateProfile($p));

$r->get('/users', [$auth, $A], fn() => SearchUserAccController::retrieveUserAccounts());
$r->get('/users/search', [$auth, $A], fn() => SearchUserAccController::searchUserAccount());
$r->post('/users', [$auth, $A], fn() => CreateUserAccController::validateUserData());
$r->get('/users/:id', [$auth, $A], fn($p) => ViewUserAccountController::RetrieveUserAccount($p));
$r->put('/users/:id', [$auth, $A], fn($p) => UpdateUserController::UpdateUserAccount($p));
$r->patch('/users/:id/suspend', [$auth, $A], fn($p) => SuspendUserAccController::suspendUserAccount($p));
$r->patch('/users/:id/activate', [$auth, $A], fn($p) => SuspendUserAccController::activateUserAccount($p));

$r->get('/companies', [$auth, $D], fn() => SearchCompanyInfoController::retrieveCompanies());
$r->get('/companies/search', [$auth, $D], fn() => SearchCompanyInfoController::searchCompanyInfo());
$r->post('/companies', [$auth, $D], fn() => CreateCompanyController::createCompany());
$r->get('/companies/:id', [$auth, $D], fn($p) => ViewCompanyController::viewCompanyInfo($p));
$r->put('/companies/:id', [$auth, $D], fn($p) => CompanyUpdateController::updateCompanyInfo($p));
$r->delete('/companies/:id', [$auth, $D], fn($p) => DeleteCompanyInfoController::deleteCompany($p));

$r->post('/projects', [$auth, $D], fn() => ProjectCreationController::createProject());
$r->post('/projects/completed', [$auth, $D], fn() => CreateCompletedProjController::createProject());
$r->get('/projects/search', [$auth, $D], fn() => SearchAllProjectsController::searchFavourite());
$r->get('/projects/company/:companyId', [$auth, $D], fn($p) => ViewProjectController::getProjectsByCompany($p));
$r->get('/projects/company/:companyId/search', [$auth, $D], fn($p) => SearchProjectController::SearchProject($p));
$r->get('/projects/company/:companyId/completed/search', [$auth, $D], fn($p) => SearchCompeletedController::SearchProject($p));
$r->get('/projects/:id', [$auth, $D], fn($p) => ViewProjectController::getProject($p));
$r->get('/projects/:id/completed', [$auth, $D], fn($p) => ViewCompletedProjController::retrieveCompletedProject($p));
$r->put('/projects/:id', [$auth, $D], fn($p) => UpdateProjectController::updateProject($p));
$r->put('/projects/:id/completed', [$auth, $D], fn($p) => UpdateCompletedProjController::updateProject($p));
$r->delete('/projects/:id', [$auth, $D], fn($p) => DeleteProjectController::deleteProject($p));
$r->delete('/projects/:id/completed', [$auth, $D], fn($p) => DeleteProjectController::deleteProject($p));

$r->get('/favourites', [$auth, $Cu], fn() => ViewFavouritesController::getProjectsByCustomerId());
$r->get('/favourites/search', [$auth, $Cu], fn() => FavouriteController::searchFavourite());
$r->post('/favourites', [$auth, $Cu], fn() => FavouriteController::saveFavourite());
$r->delete('/favourites/:projectId', [$auth, $Cu], fn($p) => FavouriteController::deleteFavourite($p));
$r->get('/projects/public/search', [$auth, $Cu], fn() => SearchPublicProjectController::searchPublicProject());
$r->get('/projects/public/:id', [$auth, $Cu], fn($p) => ViewPublicProjectController::retrieveProject($p));

$r->get('/projects/search/interest', [$auth, $D], fn() => SearchAllInterestController::getProjectDetails());
$r->get('/projects/company/:companyId/interest', [$auth, $D], fn($p) => ProjectInterestController::getProjectDetails($p));

$r->get('/categories', [$auth, $M], fn() => ViewCategoryController::getCategories());
$r->get('/categories/search', [$auth, $M], fn() => SearchCategoryController::searchCategory());
$r->post('/categories', [$auth, $M], fn() => CreateCategoryController::submitCategory());
$r->get('/categories/:id', [$auth, $M], fn($p) => ViewCategoryController::retrieveCategory($p));
$r->put('/categories/:id', [$auth, $M], fn($p) => UpdateCategoryController::updateCategory($p));
$r->delete('/categories/:id', [$auth, $M], fn($p) => DeleteCategoryController::deleteCategory($p));

$r->get('/reports/daily', [$auth, $M], fn() => DailyReportController::generateDailyReport());
$r->get('/reports/weekly', [$auth, $M], fn() => WeeklyReportController::generateWeeklyReport());
$r->get('/reports/monthly', [$auth, $M], fn() => MonthlyReportController::generateMonthlyReport());

return $r;