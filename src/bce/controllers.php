<?php
declare(strict_types=1);

function display_error(int $status, string $message, array $context = []): void {
    $meta = [400 => ['title' => 'Invalid Input', 'severity' => 'warning', 'icon' => '⚠️'], 401 => ['title' => 'Login Required', 'severity' => 'warning', 'icon' => '🔒'], 403 => ['title' => 'Access Denied', 'severity' => 'error', 'icon' => '⛔'], 404 => ['title' => 'Not Found', 'severity' => 'info', 'icon' => '🔍'], 409 => ['title' => 'Conflict', 'severity' => 'warning', 'icon' => '⚡'], 422 => ['title' => 'Validation Failed', 'severity' => 'warning', 'icon' => '⚠️'], 500 => ['title' => 'Server Error', 'severity' => 'critical', 'icon' => '💥']];
    $m = $meta[$status] ?? ['title' => 'Error', 'severity' => 'error', 'icon' => '❌'];
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $payload = array_merge(['error' => $message, 'message' => $message, 'title' => $m['title'], 'severity' => $m['severity'], 'icon' => $m['icon'], 'status' => $status, 'display' => true, 'html' => '<div class="error-alert" data-severity="' . $m['severity'] . '" data-status="' . $status . '" role="alert"><span class="error-alert__icon" aria-hidden="true">' . $m['icon'] . '</span><div class="error-alert__body"><strong class="error-alert__title">' . $m['title'] . '</strong><p class="error-alert__message">' . $safeMessage . '</p></div></div>'], $context);
    json_out($status, $payload);
}

// DEBUG VERSION: Outputs exact error to Node console
function handle_error(Throwable $e, string $fallback = 'An unexpected system error occurred. Please try again later.'): void {
    json_out(500, [
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);
}

class LoginController { public static function login(): void { try { $b = req_body(); $email = $b['email'] ?? null; $password = $b['password'] ?? null; if (!$email || !$password) { display_error(400, 'Please enter both an email and a password.'); return; } $u = UserAcc::authenticate($email, $password); if (!$u) { $candidate = UserAcc::checkDuplicate($email); if ($candidate && $candidate['status'] === 'Suspended') { display_error(403, 'This account has been suspended.'); return; } if ($candidate && isset($candidate['profile_status']) && $candidate['profile_status'] === 'Suspended') { display_error(403, 'The user profile assigned to this account has been suspended.'); return; } display_error(401, 'Invalid email or password.'); return; } $perms = []; if (isset($u['permissions'])) { $p = $u['permissions']; if (is_string($p)) { $p = trim($p, '{}'); $perms = $p === '' ? [] : explode(',', $p); } elseif (is_array($p)) { $perms = $p; } } session_regenerate_id(true); $_SESSION['user'] = ['account_id' => (int)$u['account_id'], 'username' => $u['username'], 'role' => $u['profile_name'], 'permissions' => $perms]; json_out(200, ['user' => $_SESSION['user']]); } catch (Throwable $e) { handle_error($e); } } }
class LogoutController { public static function LogoutUser(): void { $_SESSION = []; if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); } session_destroy(); json_out(200, ['ok' => true]); } public static function retrieveAuthenticatedUser(): void { $u = session_user(); if (!$u) { display_error(401, 'You are not logged in.'); return; } json_out(200, ['user' => $u]); } }
class CreateUserAccController { public static function validateUserData(): void { try { $b = req_body(); $pid = $b['profile_id'] ?? null; $username = $b['username'] ?? null; $email = $b['email'] ?? null; $password = $b['password'] ?? null; if (!$pid || !$username || !$email || !$password) { display_error(400, 'Required information is missing.'); return; } if (!str_contains($email, '@')) { display_error(400, 'Please provide a valid email address.', ['field' => 'email']); return; } if (UserAcc::checkDuplicate($email)) { display_error(409, 'An account with this email already exists.', ['field' => 'email']); return; } json_out(201, ['user' => UserAcc::createUserAccount(['profile_id' => (int)$pid, 'username' => $username, 'email' => $email, 'password_hash' => hash_password($password)])]); } catch (Throwable $e) { handle_error($e); } } }
class UpdateUserController { public static function UpdateUserAccount(array $params): void { try { $id = $params['id']; $b = req_body(); if (!empty($b['email']) && !str_contains($b['email'], '@')) { display_error(400, 'Required information is incorrectly formatted.', ['field' => 'email']); return; } if (!empty($b['email'])) { $existing = UserAcc::checkDuplicate($b['email']); if ($existing && (int)$existing['account_id'] !== (int)$id) { display_error(409, 'An account with this email already exists.', ['field' => 'email']); return; } } $f = $b; if (!empty($b['password']) && trim($b['password']) !== '') { $f['password_hash'] = hash_password($b['password']); } else { unset($f['password_hash']); } unset($f['password']); $u = UserAcc::UpdateUserAccount($id, $f); if (!$u) { display_error(404, 'User account not found.'); return; } json_out(200, ['user' => $u]); } catch (Throwable $e) { handle_error($e); } } }
class ViewUserAccountController { 
    public static function RetrieveUserAccount(array $params): void { 
        $id = $params['id'] ?? null;
        if ($id === 'search') { SearchUserAccController::searchUserAccount(); return; }
        if ($id !== null && !is_numeric($id)) { display_error(404, 'User not found.'); return; }
        $u = UserAcc::RetrieveUserAccount($id); 
        if (!$u) { display_error(404, 'User not found.'); return; } 
        json_out(200, ['user' => $u]); 
    } 
}
class SuspendUserAccController { public static function suspendUserAccount(array $params): void { $status = UserAcc::checkActiveStatus($params['id']); if ($status === null) { display_error(404, 'User not found.'); return; } if ($status === 'Suspended') { display_error(409, 'This account is already suspended.'); return; } json_out(200, ['user' => UserAcc::suspendUserAccount($params['id'], 'Suspended')]); } public static function activateUserAccount(array $params): void { $status = UserAcc::checkActiveStatus($params['id']); if ($status === null) { display_error(404, 'User not found.'); return; } json_out(200, ['user' => UserAcc::suspendUserAccount($params['id'], 'Active')]); } }
class SearchUserAccController { public static function searchUserAccount(): void { $users = UserAcc::searchUserAccount($_GET['q'] ?? ''); if (count($users) === 0) { json_out(200, ['users' => [], 'message' => 'No users found matching the search criteria.']); return; } json_out(200, ['users' => $users]); } public static function retrieveUserAccounts(): void { json_out(200, ['users' => UserAcc::searchUserAccount('')]); } }
class CreateProfileController { public static function createProfile(): void { try { $b = req_body(); if (empty($b['profile_name'])) { display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']); return; } if (q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']])) { display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']); return; } json_out(201, ['profile' => UserProfile::createProfile($b)]); } catch (Throwable $e) { handle_error($e); } } }
class UpdateUserProfileController { public static function UpdateUserProfile(array $params): void { try { $b = req_body(); if (empty($b['profile_name'])) { display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']); return; } if (q1('SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2', [$b['profile_name'], $params['id']])) { display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']); return; } $p = UserProfile::UpdateUserProfile($params['id'], $b); if (!$p) { display_error(404, 'The requested profile could not be found.'); return; } json_out(200, ['profile' => $p]); } catch (Throwable $e) { handle_error($e); } } }
class ViewProfileController { 
    public static function retrieveProfile(array $params): void { 
        $id = $params['id'] ?? null;
        if ($id === 'search') { SearchProfileController::search(); return; }
        if ($id !== null && !is_numeric($id)) { display_error(404, 'The requested profile could not be found.'); return; }
        $p = UserProfile::retrieveProfile($id); 
        if (!$p) { display_error(404, 'The requested profile could not be found.'); return; } 
        json_out(200, ['profile' => $p]); 
    } 
}
class SuspendUserProfileController { public static function suspendProfile(array $params): void { $p = UserProfile::retrieveProfile($params['id']); if (!$p) { display_error(404, 'User profile cannot be found.'); return; } if ($p['status'] === 'Suspended') { display_error(409, 'User profile is already suspended.'); return; } json_out(200, ['profile' => UserProfile::updateStatus($params['id'], 'Suspended')]); } public static function activateProfile(array $params): void { $p = UserProfile::retrieveProfile($params['id']); if (!$p) { display_error(404, 'User profile cannot be found.'); return; } json_out(200, ['profile' => UserProfile::updateStatus($params['id'], 'Active')]); } }
class SearchProfileController { public static function search(): void { $results = UserProfile::search($_GET['q'] ?? ''); if (count($results) === 0) { json_out(200, ['profiles' => [], 'message' => 'No matching user profiles were found.']); return; } json_out(200, ['profiles' => $results]); } public static function retrieveProfiles(): void { json_out(200, ['profiles' => UserProfile::search('')]); } }

class CreateCompanyController { public static function createCompany(): void { try { $b = req_body(); $name = trim((string)($b['company_name'] ?? '')); $address = trim((string)($b['address'] ?? '')); $contact = trim((string)($b['contact_no'] ?? '')); if ($name === '') { display_error(400, 'Company Name is a mandatory field.', ['field' => 'company_name']); return; } if ($address === '') { display_error(400, 'Address is a mandatory field.', ['field' => 'address']); return; } if ($contact === '') { display_error(400, 'Contact Hotline is a mandatory field.', ['field' => 'contact_no']); return; } $digitsOnly = preg_replace('/\D/', '', $contact); if (strlen($digitsOnly) !== 8) { display_error(400, 'Contact Hotline must be exactly 8 digits (e.g., 91234567).', ['field' => 'contact_no']); return; } json_out(201, ['company' => CompanyInfo::createCompany(['owner_id' => session_user()['account_id'], 'company_name' => $name, 'address' => $address, 'contact_no' => $digitsOnly])]); } catch (Throwable $e) { handle_error($e, 'Failed to create company profile.'); } } }
class ViewCompanyController { 
    public static function viewCompanyInfo(array $params): void { 
        $id = $params['id'] ?? null;
        if ($id === 'search') { SearchCompanyInfoController::searchCompanyInfo(); return; }
        if ($id !== null && !is_numeric($id)) { display_error(404, 'Company information could not be found.'); return; }
        $c = CompanyInfo::viewCompanyInfo($id); 
        if (!$c) { display_error(404, 'No matching results. Company information could not be found.'); return; } 
        json_out(200, ['company' => $c]); 
    } 
}
class CompanyUpdateController { public static function updateCompanyInfo(array $params): void { try { $c = CompanyInfo::viewCompanyInfo($params['id']); if (!$c) { display_error(404, 'Company record not found.'); return; } if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission to edit this company.'); return; } $b = req_body(); $name = trim((string)($b['company_name'] ?? '')); $address = trim((string)($b['address'] ?? '')); $contact = trim((string)($b['contact_no'] ?? '')); if ($name === '') { display_error(400, 'Company Name cannot be left blank.', ['field' => 'company_name']); return; } if ($address === '') { display_error(400, 'Address cannot be left blank.', ['field' => 'address']); return; } if ($contact === '') { display_error(400, 'Contact Hotline cannot be left blank.', ['field' => 'contact_no']); return; } $digitsOnly = preg_replace('/\D/', '', $contact); if (strlen($digitsOnly) !== 8) { display_error(400, 'Contact Hotline must be exactly 8 digits (e.g., 91234567).', ['field' => 'contact_no']); return; } json_out(200, ['company' => CompanyInfo::updateCompanyInfo($params['id'], ['company_name' => $name, 'address' => $address, 'contact_no' => $digitsOnly])]); } catch (Throwable $e) { handle_error($e); } } }
class DeleteCompanyInfoController { public static function deleteCompany(array $params): void { try { $c = CompanyInfo::viewCompanyInfo($params['id']); if (!$c) { display_error(404, 'Company information could not be found.'); return; } if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission to delete this company.'); return; } CompanyInfo::deleteCompany($params['id']); json_out(200, ['ok' => true]); } catch (Throwable $e) { handle_error($e, 'Deletion failed.'); } } }
class SearchCompanyInfoController { public static function searchCompanyInfo(): void { $rows = CompanyInfo::searchCompanyInfo(session_user()['account_id'], $_GET['q'] ?? ''); if (count($rows) === 0) { json_out(200, ['companies' => [], 'message' => 'No companies found matching the search keyword.']); return; } json_out(200, ['companies' => $rows]); } public static function retrieveCompanies(): void { json_out(200, ['companies' => CompanyInfo::retrieveCompaniesByOwner(session_user()['account_id'])]); } }

class ProjectAuth { public static function assertOwner($pid, $uid): ?array { $p = Project::getProject($pid); if (!$p) { display_error(404, 'Project not found.'); return null; } $c = CompanyInfo::viewCompanyInfo($p['company_id']); if (!$c || (int)$c['owner_id'] !== (int)$uid) { display_error(403, 'You do not have permission to modify this project.'); return null; } return $p; } }

class ProjectCreationController { public static function createProject(): void { try { $b = req_body(); $c = CompanyInfo::viewCompanyInfo($b['company_id'] ?? null); if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'Invalid company selected or you do not own it.', ['field' => 'company_id']); return; } if (empty($b['title']) || empty($b['description'])) { display_error(400, 'Please fill up all fields: Title and Description.'); return; } json_out(201, ['project' => Project::createProject(['company_id' => (int)$b['company_id'], 'title' => $b['title'], 'description' => $b['description'], 'status' => (($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing'])]); } catch (Throwable $e) { handle_error($e); } } }
class CreateCompletedProjController { public static function createProject(): void { try { $b = req_body(); $c = CompanyInfo::viewCompanyInfo($b['company_id'] ?? null); if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'Invalid company selected.', ['field' => 'company_id']); return; } if (empty($b['title']) || empty($b['description'])) { display_error(400, 'Please fill up all fields: Title and Description.'); return; } json_out(201, ['project' => Project::createProject(['company_id' => (int)$b['company_id'], 'title' => $b['title'], 'description' => $b['description'], 'status' => 'Completed'])]); } catch (Throwable $e) { handle_error($e); } } }
class ViewProjectController { 
    public static function getProject(array $params): void { 
        $id = $params['id'] ?? null;
        if ($id === 'search') {
            SearchProjectController::SearchProject();
            return;
        }
        if ($id !== null && !is_numeric($id)) { display_error(404, 'Project not found.'); return; }
        $p = Project::getProject($id); 
        if (!$p) { display_error(404, 'Project not found.'); return; } 
        json_out(200, ['project' => $p]); 
    } 
    public static function getProjectsByCompany(array $params): void { $c = CompanyInfo::viewCompanyInfo($params['companyId']); if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; } $rows = Project::getProjectsByCompany($params['companyId'], $_GET['status'] ?? null); if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No projects exist for this company.']); return; } json_out(200, ['projects' => $rows]); } 
}
class ViewCompletedProjController { public static function retrieveCompletedProject(array $params): void { $p = Project::retrieveCompletedProject($params['id']); if (!$p || ($p['status'] ?? null) !== 'Completed') { display_error(404, 'Completed project not found.'); return; } json_out(200, ['project' => $p]); } }
class UpdateProjectController { public static function updateProject(array $params): void { try { if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return; $b = req_body(); if (empty($b['title']) || empty($b['description'])) { display_error(400, 'Please fill up all fields: Title and Description.'); return; } if (!empty($b['company_id'])) { $nc = CompanyInfo::viewCompanyInfo((int)$b['company_id']); if (!$nc || (int)$nc['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not own the selected company.', ['field' => 'company_id']); return; } } json_out(200, ['project' => Project::updateProject($params['id'], ['company_id' => $b['company_id'] ?? null, 'title' => $b['title'], 'description' => $b['description'], 'status' => $b['status'] ?? null])]); } catch (Throwable $e) { handle_error($e); } } }
class UpdateCompletedProjController { public static function updateProject(array $params): void { try { if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return; $b = req_body(); if (empty($b['title']) || empty($b['description'])) { display_error(400, 'Please fill up all fields: Title and Description.'); return; } if (!empty($b['company_id'])) { $nc = CompanyInfo::viewCompanyInfo((int)$b['company_id']); if (!$nc || (int)$nc['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not own the selected company.', ['field' => 'company_id']); return; } } json_out(200, ['project' => Project::updateProject($params['id'], ['company_id' => $b['company_id'] ?? null, 'title' => $b['title'], 'description' => $b['description'], 'status' => $b['status'] ?? null])]); } catch (Throwable $e) { handle_error($e); } } }
class DeleteProjectController { public static function deleteProject(array $params): void { try { if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return; Project::deleteProject($params['id']); json_out(200, ['ok' => true]); } catch (Throwable $e) { handle_error($e); } } }

class SearchProjectController {
    public static function SearchProject($params = null): void {
        try {
            $params = is_array($params) ? $params : [];
            $companyId = $params['companyId'] ?? null;
            $q = $_GET['q'] ?? ''; $status = $_GET['status'] ?? null;
            
            if (!$companyId) {
                $rows = Project::searchAllByOwner(session_user()['account_id'], $q, $status);
                if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
                json_out(200, ['projects' => $rows]); return;
            }
            
            $c = CompanyInfo::viewCompanyInfo($companyId);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
            $rows = Project::SearchProject($companyId, $q, $status);
            if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
            json_out(200, ['projects' => $rows]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class SearchCompeletedController {
    public static function SearchProject($params = null): void {
        try {
            $params = is_array($params) ? $params : [];
            $companyId = $params['companyId'] ?? null;
            if (!$companyId) { display_error(400, 'Company ID is required.'); return; }
            $c = CompanyInfo::viewCompanyInfo($companyId);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
            $rows = Project::SearchProject($companyId, $_GET['q'] ?? '', 'Completed');
            if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching completed projects were found.']); return; }
            json_out(200, ['projects' => $rows]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class SearchAllInterestController { public static function getProjectDetails(): void { json_out(200, ['interest' => Project::getAllProjectDetails(session_user()['account_id'])]); } }
class SearchPublicProjectController { public static function searchPublicProject(): void { $rows = Project::searchPublicProject($_GET['q'] ?? ''); if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; } json_out(200, ['projects' => $rows]); } }
class ViewPublicProjectController { public static function retrieveProject(array $params): void { try { $p = Project::retrieveProject($params['id']); if (!$p) { display_error(404, 'This project is no longer available.'); return; } Project::recordView($p['project_id'], session_user()['account_id'] ?? null); json_out(200, ['project' => $p]); } catch (Throwable $e) { handle_error($e); } } }

class ProjectInterestController { public static function getProjectDetails(array $params): void { $c = CompanyInfo::viewCompanyInfo($params['companyId']); if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; } json_out(200, ['interest' => Project::getProjectDetails($params['companyId'])]); } }
class FavouriteController { public static function saveFavourite(): void { try { $b = req_body(); $pid = $b['project_id'] ?? null; if (!$pid) { display_error(400, 'No project selected to save.', ['field' => 'project_id']); return; } if (!Project::getProject($pid)) { display_error(404, 'The project could not be added because it does not exist.'); return; } Favourite::addFavourite(session_user()['account_id'], (int)$pid); json_out(201, ['ok' => true]); } catch (Throwable $e) { handle_error($e, 'The project could not be added.'); } } public static function getProjectsByCustomerId(): void { json_out(200, ['favourites' => Favourite::getProjectsByCustomerId(session_user()['account_id'])]); } public static function searchFavourite(): void { $rows = Favourite::searchFavourite(session_user()['account_id'], $_GET['q'] ?? ''); if (count($rows) === 0) { json_out(200, ['favourites' => [], 'message' => 'No matching interior design projects found in your favourite list.']); return; } json_out(200, ['favourites' => $rows]); } public static function deleteFavourite(array $params): void { Favourite::removeFavourite(session_user()['account_id'], $params['projectId']); json_out(200, ['ok' => true]); } }
class ViewFavouritesController { public static function getProjectsByCustomerId(): void { json_out(200, ['favourites' => Favourite::getProjectsByCustomerId(session_user()['account_id'])]); } }

class SearchAllProjectsController {
    public static function searchProjects($params = null): void { self::SearchProject($params); }
    public static function SearchProject($params = null): void {
        try {
            $q = $_GET['q'] ?? ''; $status = $_GET['status'] ?? null;
            $rows = Project::searchAllByOwner(session_user()['account_id'], $q, $status);
            if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
            json_out(200, ['projects' => $rows]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class CreateCategoryController { public static function submitCategory(): void { try { $b = req_body(); if (empty($b['category_name'])) { display_error(400, 'Category Name is required.', ['field' => 'category_name']); return; } if (q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']])) { display_error(409, 'A category with this name already exists.', ['field' => 'category_name']); return; } json_out(201, ['category' => Category::saveCategory($b)]); } catch (Throwable $e) { handle_error($e, 'The category could not be created.'); } } }
class ViewCategoryController { 
    public static function getCategories(): void { $rows = Category::retrieveCategories(); if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No IDP categories are available.']); return; } json_out(200, ['categories' => $rows]); } 
    public static function retrieveCategory(array $params): void { 
        $id = $params['id'] ?? null;
        if ($id === 'search') { SearchCategoryController::searchCategory(); return; }
        if ($id !== null && !is_numeric($id)) { display_error(404, 'Category not found.'); return; }
        $c = Category::viewCategory($id); 
        if (!$c) { display_error(404, 'Category not found.'); return; } 
        json_out(200, ['category' => $c]); 
    } 
}
class UpdateCategoryController { public static function updateCategory(array $params): void { try { if (empty(req_body()['category_name'])) { display_error(400, 'Category Name cannot be empty.', ['field' => 'category_name']); return; } $c = Category::updateCategory($params['id'], req_body()); if (!$c) { display_error(404, 'The category could not be updated because it was not found.'); return; } json_out(200, ['category' => $c]); } catch (Throwable $e) { handle_error($e, 'The category could not be updated.'); } } }
class DeleteCategoryController { public static function deleteCategory(array $params): void { try { if (!Category::exists($params['id'])) { display_error(404, 'The category could not be deleted because it was not found.'); return; } Category::deleteCategory($params['id']); json_out(200, ['ok' => true]); } catch (Throwable $e) { handle_error($e, 'The category could not be deleted.'); } } }
class SearchCategoryController { public static function searchCategory(): void { $rows = Category::findCategory($_GET['q'] ?? ''); if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No matching categories were found.']); return; } json_out(200, ['categories' => $rows]); } }

class DailyReportController { public static function generateDailyReport(array $params = []): void { json_out(200, Report::generateDailyReport()); } }
class WeeklyReportController { public static function generateWeeklyReport(array $params = []): void { json_out(200, Report::generateWeeklyReport()); } }
class MonthlyReportController { public static function generateMonthlyReport(array $params = []): void { json_out(200, Report::generateMonthlyReport()); } }