<?php
declare(strict_types=1);

/**
 * =============================================================================
 * BOUNDARY LAYER — HTTP Routing (src/bce/boundary.php)
 * =============================================================================
 * Role in BCE: The "B" layer. Maps HTTP requests to controllers. Contains no
 * class definitions — only a Router instance and route registrations — so it
 * has no "class name" to preserve as original-teammate metadata.
 *
 * Where the original teammate's controller name is relevant, it is noted as a
 * trailing comment on the specific route that calls it:
 *     // was: <OriginalTeammateClassName>
 *
 * Only routes whose controllers were renamed during the Sprint 3 refactor
 * carry a "// was:" comment. Routes that already pointed at the same class
 * name (LoginController, LogoutController, etc.) do not.
 *
 * =============================================================================
 * DEMO TIP
 * =============================================================================
 * grep for "// was:" to see every route whose controller class was renamed.
 * grep for "[US #" to jump to a specific user story during the demo.
 * =============================================================================
 */

$r = new Router();

// Pre-built middleware for each role. Passing these into route arrays avoids
// re-instantiating closures on every request.
$A    = role_guard('User Admin');
$D    = role_guard('Interior Designer');
$Cu   = role_guard('Customer');
$M    = role_guard('Platform Manager');
$auth = auth_guard();

// =============================================================================
// [SECTION: AUTH]  US #1, #2, #13, #14, #35, #36, #39, #40
// =============================================================================
// Public — no role guard, no auth guard. All four user roles share these.
// =============================================================================
$r->post('/auth/login',  [], fn() => LoginController::login());       // same class name
$r->post('/auth/logout', [], fn() => LogoutController::logout());     // was: Logout_Controller
$r->get ('/auth/me',     [], fn() => LogoutController::me());         // was: Logout_Controller

// =============================================================================
// [SECTION: PROFILES]  US #8–#12 — User Profiles (Admin only)
// =============================================================================
$r->get   ('/profiles',              [$auth, $A], fn() => SearchProfileController::list());
$r->get   ('/profiles/search',       [$auth, $A], fn() => SearchProfileController::search());
$r->post  ('/profiles',              [$auth, $A], fn() => CreateProfileController::create());        // was: createProfile_Controller
$r->get   ('/profiles/:id',          [$auth, $A], fn($p) => ViewProfileController::view($p));         // was: viewProfile_Controller
$r->put   ('/profiles/:id',          [$auth, $A], fn($p) => EditProfileController::update($p));       // was: updateProfileController
$r->patch ('/profiles/:id/suspend',  [$auth, $A], fn($p) => SuspendProfileController::suspend($p));   // was: SuspendUserProfileController
$r->patch ('/profiles/:id/activate', [$auth, $A], fn($p) => SuspendProfileController::activate($p));  // was: SuspendUserProfileController

// =============================================================================
// [SECTION: USERS]  US #3–#7 — User Accounts (Admin only)
// =============================================================================
$r->get   ('/users',              [$auth, $A], fn() => SearchUserAccController::list());              // was: SearchUser_Controller
$r->get   ('/users/search',       [$auth, $A], fn() => SearchUserAccController::search());            // was: SearchUser_Controller
$r->post  ('/users',              [$auth, $A], fn() => CreateUserAccController::create());            // was: Create_UserAcc_Controller
$r->get   ('/users/:id',          [$auth, $A], fn($p) => ViewUserAccController::view($p));            // was: viewUserAccount_Controller
$r->put   ('/users/:id',          [$auth, $A], fn($p) => UpdateUserAccController::update($p));        // was: updateUser_Controller
$r->patch ('/users/:id/suspend',  [$auth, $A], fn($p) => SuspendUserAccController::suspend($p));      // was: SuspendUser_Controller
$r->patch ('/users/:id/activate', [$auth, $A], fn($p) => SuspendUserAccController::activate($p));     // was: SuspendUser_Controller

// =============================================================================
// [SECTION: COMPANIES]  US #15–#19 — Company Information (Designer only)
// =============================================================================
$r->get   ('/companies',        [$auth, $D], fn() => SearchCompanyController::list());                // was: searchCoyinfo_Controller
$r->get   ('/companies/search', [$auth, $D], fn() => SearchCompanyController::search());              // was: searchCoyinfo_Controller
$r->post  ('/companies',        [$auth, $D], fn() => CreateCompanyController::create());              // was: IDCreate_Cl_Controller
$r->get   ('/companies/:id',    [$auth, $D], fn($p) => ViewCompanyController::view($p));              // was: viewCoyinfo_Controller
$r->put   ('/companies/:id',    [$auth, $D], fn($p) => UpdateCompanyController::update($p));          // was: CompanyUpdateController
$r->delete('/companies/:id',    [$auth, $D], fn($p) => DeleteCompanyController::remove($p));          // was: Delete_CompanyInfo_Controller

// =============================================================================
// [SECTION: PROJECTS — PUBLIC]  US #30, #31 — Customer-facing
// =============================================================================
$r->get   ('/projects/public/search', [$auth, $Cu], fn() => SearchPublicProjectController::search()); // was: customerSearchProjectController
$r->get   ('/projects/public/:id',    [$auth, $Cu], fn($p) => ViewPublicProjectController::view($p)); // was: viewProjController

// =============================================================================
// [SECTION: PROJECTS — DESIGNER WRITE/CREATE]
// =============================================================================
$r->post  ('/projects',           [$auth, $D], fn() => CreateProjectController::create());            // was: projCreationController
$r->post  ('/projects/completed', [$auth, $D], fn() => CreateCompletedProjController::create());      // was: createCompletedProj_Controller

// =============================================================================
// [SECTION: PROJECTS — COMPANY-SCOPED QUERIES]
// =============================================================================
$r->get   ('/projects/company/:companyId',                  [$auth, $D], fn($p) => ViewProjectController::listByCompany($p));       // was: viewProjectController
$r->get   ('/projects/company/:companyId/search',           [$auth, $D], fn($p) => SearchProjectController::search($p));
$r->get   ('/projects/company/:companyId/completed/search', [$auth, $D], fn($p) => SearchCompletedController::search($p));        // was: SearchCompeletedController
$r->get   ('/projects/company/:companyId/interest',         [$auth, $D], fn($p) => ProjectInterestController::interest($p));       // was: FavouriteCountController

// =============================================================================
// [SECTION: PROJECTS — CROSS-COMPANY QUERIES]
// =============================================================================
$r->get   ('/projects/search',          [$auth, $D], fn() => SearchAllProjectsController::search());   // was: SearchFavouriteController
$r->get   ('/projects/search/interest', [$auth, $D], fn() => SearchAllInterestController::search());   // was: viewCountController

// =============================================================================
// [SECTION: PROJECTS — SINGLE-RESOURCE OPERATIONS]  US #21, #26, #27
// =============================================================================
$r->get   ('/projects/:id',            [$auth, $D], fn($p) => ViewProjectController::view($p));            // was: viewProjectController
$r->get   ('/projects/:id/completed',  [$auth, $D], fn($p) => ViewCompletedProjController::view($p));      // was: viewCompletedProj_Controller
$r->put   ('/projects/:id',            [$auth, $D], fn($p) => UpdateProjectController::update($p));        // was: updateProject_Controller
$r->put   ('/projects/:id/completed',  [$auth, $D], fn($p) => UpdateCompletedProjController::update($p));  // was: updateCompletedProj_Controller
$r->delete('/projects/:id',            [$auth, $D], fn($p) => DeleteProjectController::remove($p));        // was: deleteProject_Controller
$r->delete('/projects/:id/completed',  [$auth, $D], fn($p) => DeleteCompletedProjController::remove($p));  // was: deleteProj_controller

// =============================================================================
// [SECTION: FAVOURITES]  US #32–#34 — Customer only
// =============================================================================
$r->get   ('/favourites',            [$auth, $Cu], fn() => FavouriteController::list());               // same class name
$r->get   ('/favourites/search',     [$auth, $Cu], fn() => FavouriteController::search());             // same class name
$r->post  ('/favourites',            [$auth, $Cu], fn() => FavouriteController::add());                // same class name
$r->delete('/favourites/:projectId', [$auth, $Cu], fn($p) => FavouriteController::remove($p));         // same class name

// =============================================================================
// [SECTION: CATEGORIES]  US #41–#45 — Platform Manager only
// =============================================================================
$r->get   ('/categories',        [$auth, $M], fn() => ViewCategoryController::list());                 // same class name
$r->get   ('/categories/search', [$auth, $M], fn() => SearchCategoryController::search());             // same class name
$r->get   ('/categories/:id',    [$auth, $M], fn($p) => ViewCategoryController::view($p));             // same class name
$r->post  ('/categories',        [$auth, $M], fn() => CreateCategoryController::create());             // same class name
$r->put   ('/categories/:id',    [$auth, $M], fn($p) => UpdateCategoryController::update($p));         // same class name
$r->delete('/categories/:id',    [$auth, $M], fn($p) => DeleteCategoryController::remove($p));         // same class name

// =============================================================================
// [SECTION: REPORTS]  US #46–#48 — Platform Manager only
// =============================================================================
$r->get ('/reports/:type', [$auth, $M], function (array $p) {
    $type = strtolower((string)($p['type'] ?? ''));
    switch ($type) {
        case 'daily':   DailyReportController::generate();   return;    // same class name
        case 'weekly':  WeeklyReportController::generate();  return;    // same class name
        case 'monthly': MonthlyReportController::generate(); return;    // same class name
    }
    // Fallback for invalid path — e.g. /api/reports/yearly
    display_error(
        400,
        'Invalid report type. Expected daily, weekly or monthly.',
        ['type' => $type, 'allowed' => ['daily', 'weekly', 'monthly']]
    );
});

return $r;