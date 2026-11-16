<?php
declare(strict_types=1);

/**
 * =============================================================================
 * CONTROLLERS (BCE "Control" layer)
 * =============================================================================
 * Controllers ONLY PASS: each method takes the request data (body, URL params,
 * query string, session user id), hands it to the matching Entity use-case
 * method, and relays the returned payload as JSON.
 *
 * They contain no validation, no ownership checks, no SQL and no status-code
 * decisions for errors. All of that lives in the Entity layer, which throws
 * AppError on a rule failure; Router::dispatch() renders it.
 * =============================================================================
 */

// ---- Authentication --------------------------------------------------------
class LoginController
{
    public static function login(): void { json_out(200, UserAcc::login(req_body())); }
}
class LogoutController
{
    public static function LogoutUser(): void { json_out(200, UserAcc::logout()); }
    public static function retrieveAuthenticatedUser(): void { json_out(200, UserAcc::currentUser(session_user())); }
}

// ---- User accounts (User Admin) -------------------------------------------
class CreateUserAccController
{
    public static function validateUserData(): void { json_out(201, UserAcc::register(req_body())); }
}
class UpdateUserController
{
    public static function UpdateUserAccount(array $params): void { json_out(200, UserAcc::edit($params['id'], req_body())); }
}
class ViewUserAccountController
{
    public static function RetrieveUserAccount(array $params): void { json_out(200, UserAcc::view($params['id'] ?? null)); }
}
class SuspendUserAccController
{
    public static function suspendUserAccount(array $params): void { json_out(200, UserAcc::suspend($params['id'])); }
    public static function activateUserAccount(array $params): void { json_out(200, UserAcc::activate($params['id'])); }
}
class SearchUserAccController
{
    public static function searchUserAccount(): void { json_out(200, UserAcc::search($_GET['q'] ?? '')); }
    public static function retrieveUserAccounts(): void { json_out(200, UserAcc::listAll()); }
}

// ---- User profiles (User Admin) -------------------------------------------
class CreateProfileController
{
    public static function createProfile(): void { json_out(201, UserProfile::addProfile(req_body())); }
}
class UpdateUserProfileController
{
    public static function UpdateUserProfile(array $params): void { json_out(200, UserProfile::editProfile($params['id'], req_body())); }
}
class ViewProfileController
{
    public static function retrieveProfile(array $params): void { json_out(200, UserProfile::showProfile($params['id'] ?? null)); }
}
class SuspendUserProfileController
{
    public static function suspendProfile(array $params): void { json_out(200, UserProfile::suspendProfile($params['id'])); }
    public static function activateProfile(array $params): void { json_out(200, UserProfile::activateProfile($params['id'])); }
}
class SearchProfileController
{
    public static function search(): void { json_out(200, UserProfile::searchProfiles($_GET['q'] ?? '')); }
    public static function retrieveProfiles(): void { json_out(200, UserProfile::listProfiles()); }
}

// ---- Companies (Interior Designer) ----------------------------------------
class CreateCompanyController
{
    public static function createCompany(): void { json_out(201, CompanyInfo::registerCompany(req_body(), session_user()['account_id'])); }
}
class ViewCompanyController
{
    public static function viewCompanyInfo(array $params): void { json_out(200, CompanyInfo::showCompany($params['id'] ?? null)); }
}
class CompanyUpdateController
{
    public static function updateCompanyInfo(array $params): void { json_out(200, CompanyInfo::editCompany($params['id'], req_body(), session_user()['account_id'])); }
}
class DeleteCompanyInfoController
{
    public static function deleteCompany(array $params): void { json_out(200, CompanyInfo::removeCompany($params['id'], session_user()['account_id'])); }
}
class SearchCompanyInfoController
{
    public static function searchCompanyInfo(): void { json_out(200, CompanyInfo::searchOwned(session_user()['account_id'], $_GET['q'] ?? '')); }
    public static function retrieveCompanies(): void { json_out(200, CompanyInfo::listOwned(session_user()['account_id'])); }
}

// ---- Projects (Interior Designer) -----------------------------------------
class ProjectCreationController
{
    public static function createProject(): void { json_out(201, Project::addProject(req_body(), session_user()['account_id'])); }
}
class CreateCompletedProjController
{
    public static function createProject(): void { json_out(201, Project::addCompletedProject(req_body(), session_user()['account_id'])); }
}
class ViewProjectController
{
    public static function getProject(array $params): void { json_out(200, Project::showProject($params['id'] ?? null)); }
    public static function getProjectsByCompany(array $params): void { json_out(200, Project::listByCompany($params['companyId'], session_user()['account_id'], $_GET['status'] ?? null)); }
}
class ViewCompletedProjController
{
    public static function retrieveCompletedProject(array $params): void { json_out(200, Project::showCompleted($params['id'])); }
}
class UpdateProjectController
{
    public static function updateProject(array $params): void { json_out(200, Project::editProject($params['id'], req_body(), session_user()['account_id'])); }
}
class UpdateCompletedProjController
{
    public static function updateProject(array $params): void { json_out(200, Project::editProject($params['id'], req_body(), session_user()['account_id'])); }
}
class DeleteProjectController
{
    public static function deleteProject(array $params): void { json_out(200, Project::removeProject($params['id'], session_user()['account_id'])); }
}
class SearchProjectController
{
    public static function SearchProject($params = null): void { json_out(200, Project::searchOwned(session_user()['account_id'], is_array($params) ? ($params['companyId'] ?? null) : null, $_GET['q'] ?? '', $_GET['status'] ?? null)); }
}
class SearchCompeletedController
{
    public static function SearchProject($params = null): void { json_out(200, Project::searchCompleted(session_user()['account_id'], is_array($params) ? ($params['companyId'] ?? null) : null, $_GET['q'] ?? '')); }
}
class SearchAllProjectsController
{
    public static function searchProjects($params = null): void { self::SearchProject($params); }
    public static function SearchProject($params = null): void { json_out(200, Project::searchAllOwned(session_user()['account_id'], $_GET['q'] ?? '', $_GET['status'] ?? null)); }
}

// ---- Project interest: views and shortlists (Interior Designer) ------------
class SearchAllInterestController
{
    public static function getProjectDetails(): void { json_out(200, Project::interestForOwner(session_user()['account_id'])); }
}
class ProjectInterestController
{
    public static function getProjectDetails(array $params): void { json_out(200, Project::interestForCompany($params['companyId'], session_user()['account_id'])); }
}

// ---- Public projects and favourites (Customer) -----------------------------
class SearchPublicProjectController
{
    public static function searchPublicProject(): void { json_out(200, Project::searchPublic($_GET['q'] ?? '')); }
}
class ViewPublicProjectController
{
    public static function retrieveProject(array $params): void { json_out(200, Project::viewPublic($params['id'], session_user()['account_id'] ?? null)); }
}
class FavouriteController
{
    public static function saveFavourite(): void { json_out(201, Favourite::save(req_body(), session_user()['account_id'])); }
    public static function getProjectsByCustomerId(): void { json_out(200, Favourite::listFor(session_user()['account_id'])); }
    public static function searchFavourite(): void { json_out(200, Favourite::searchFor(session_user()['account_id'], $_GET['q'] ?? '')); }
    public static function deleteFavourite(array $params): void { json_out(200, Favourite::remove(session_user()['account_id'], $params['projectId'])); }
}
class ViewFavouritesController
{
    public static function getProjectsByCustomerId(): void { json_out(200, Favourite::listFor(session_user()['account_id'])); }
}

// ---- Categories (Platform Manager) -----------------------------------------
class CreateCategoryController
{
    public static function submitCategory(): void { json_out(201, Category::addCategory(req_body())); }
}
class ViewCategoryController
{
    public static function getCategories(): void { json_out(200, Category::listCategories()); }
    public static function retrieveCategory(array $params): void { json_out(200, Category::showCategory($params['id'] ?? null)); }
}
class UpdateCategoryController
{
    public static function updateCategory(array $params): void { json_out(200, Category::editCategory($params['id'], req_body())); }
}
class DeleteCategoryController
{
    public static function deleteCategory(array $params): void { json_out(200, Category::removeCategory($params['id'])); }
}
class SearchCategoryController
{
    public static function searchCategory(): void { json_out(200, Category::searchCategories($_GET['q'] ?? '')); }
}

// ---- Reports (Platform Manager) --------------------------------------------
class ReportController
{
    public static function generate(array $params): void { json_out(200, Report::generate((string)($params['type'] ?? ''))); }
}
class DailyReportController
{
    public static function generateDailyReport(array $params = []): void { json_out(200, Report::generateDailyReport()); }
}
class WeeklyReportController
{
    public static function generateWeeklyReport(array $params = []): void { json_out(200, Report::generateWeeklyReport()); }
}
class MonthlyReportController
{
    public static function generateMonthlyReport(array $params = []): void { json_out(200, Report::generateMonthlyReport()); }
}