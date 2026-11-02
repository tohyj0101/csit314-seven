<?php
declare(strict_types=1);

function handle_error(Throwable $e, string $fallback = 'An unexpected system error occurred. Please try again later.'): void
{
    error_log('[Backend Error] ' . $e->getMessage());
    $code = $e instanceof PDOException ? (string)$e->getCode() : '';
    if ($code === '23505') { json_out(409, ['error' => 'A record with this name or email already exists. Please use a different one.']); return; }
    if ($code === '23503') { json_out(400, ['error' => 'Cannot complete action because this record is currently in use by another part of the system.']); return; }
    if ($code === '23502') { json_out(400, ['error' => 'A required field was left blank. Please fill in all mandatory fields.']); return; }
    json_out(500, ['error' => $fallback]);
}

class LoginController
{
    public static function login(): void
    {
        try {
            $b = req_body();
            $email    = $b['email']    ?? null;
            $password = $b['password'] ?? null;

            if (!$email || !$password) {
                json_out(400, ['error' => 'Please enter both an email and a password.']);
                return;
            }

            $u = UserAcc::authenticate($email, $password);
            if (!$u) {
                $candidate = UserAcc::findByEmail($email);
                if ($candidate && $candidate['status'] === 'Suspended') {
                    json_out(403, ['error' => 'This account has been suspended. Please contact the administrator.']);
                    return;
                }
                if ($candidate && isset($candidate['profile_status']) && $candidate['profile_status'] === 'Suspended') {
                    json_out(403, ['error' => 'The user profile assigned to this account has been suspended.']);
                    return;
                }
                json_out(401, ['error' => 'Invalid email or password.']);
                return;
            }

            $perms = [];
            if (isset($u['permissions'])) {
                $p = $u['permissions'];
                if (is_string($p)) {
                    $p = trim($p, '{}');
                    $perms = $p === '' ? [] : explode(',', $p);
                } elseif (is_array($p)) {
                    $perms = $p;
                }
            }

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'account_id'  => (int)$u['account_id'],
                'username'    => $u['username'],
                'role'        => $u['profile_name'],
                'permissions' => $perms,
            ];
            json_out(200, ['user' => $_SESSION['user']]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class LogoutController
{
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(200, ['ok' => true]);
    }

    public static function me(): void
    {
        $u = session_user();
        if (!$u) { json_out(401, ['error' => 'You are not logged in.']); return; }
        json_out(200, ['user' => $u]);
    }
}

class CreateUserAccController {
    public static function create(): void {
        try {
            $b = req_body();
            $pid = $b['profile_id'] ?? null; $username = $b['username'] ?? null;
            $email = $b['email'] ?? null; $password = $b['password'] ?? null;
            if (!$pid || !$username || !$email || !$password) { json_out(400, ['error' => 'Required information is missing.']); return; }
            if (!str_contains($email, '@')) { json_out(400, ['error' => 'Please provide a valid email address.']); return; }
            if (UserAcc::findByEmail($email)) { json_out(409, ['error' => 'An account with this email already exists.']); return; }
            json_out(201, ['user' => UserAcc::saveAccount(['profile_id' => (int)$pid, 'username' => $username, 'email' => $email, 'password_hash' => hash_password($password)])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class UpdateUserAccController {
    public static function update(array $params): void {
        try {
            $id = $params['id']; $b = req_body();
            if (!empty($b['email']) && !str_contains($b['email'], '@')) { json_out(400, ['error' => 'Required information is incorrectly formatted.']); return; }
            if (!empty($b['email'])) {
                $existing = UserAcc::findByEmail($b['email']);
                if ($existing && (int)$existing['account_id'] !== (int)$id) { json_out(409, ['error' => 'An account with this email already exists.']); return; }
            }
            $f = $b;
            if (!empty($b['password']) && trim($b['password']) !== '') { $f['password_hash'] = hash_password($b['password']); } else { unset($f['password_hash']); }
            unset($f['password']);
            $u = UserAcc::updateUser($id, $f);
            if (!$u) { json_out(404, ['error' => 'User account not found.']); return; }
            json_out(200, ['user' => $u]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewUserAccController {
    public static function view(array $params): void {
        $u = UserAcc::findById($params['id']);
        if (!$u) { json_out(404, ['error' => 'User not found.']); return; }
        json_out(200, ['user' => $u]);
    }
}

class SuspendUserAccController {
    public static function suspend(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { json_out(404, ['error' => 'User not found.']); return; }
        if ($status === 'Suspended') { json_out(409, ['error' => 'This account is already suspended.']); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Suspended')]);
    }
    public static function activate(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { json_out(404, ['error' => 'User not found.']); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Active')]);
    }
}

class SearchUserAccController {
    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $users = UserAcc::findAccounts($q);
        if (count($users) === 0) { json_out(200, ['users' => [], 'message' => 'No users found matching the search criteria.']); return; }
        json_out(200, ['users' => $users]);
    }
    public static function list(): void { json_out(200, ['users' => UserAcc::all()]); }
}

class CreateProfileController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) { json_out(400, ['error' => 'Profile name cannot be empty.']); return; }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']]);
            if ($existing) { json_out(409, ['error' => 'A profile with this name already exists.']); return; }
            json_out(201, ['profile' => UserProfile::createProfile($b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class EditProfileController {
    public static function update(array $params): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) { json_out(400, ['error' => 'Profile name cannot be empty.']); return; }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2', [$b['profile_name'], $params['id']]);
            if ($existing) { json_out(409, ['error' => 'A profile with this name already exists.']); return; }
            $p = UserProfile::saveProfileSettings($params['id'], $b);
            if (!$p) { json_out(404, ['error' => 'The requested profile could not be found.']); return; }
            json_out(200, ['profile' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewProfileController {
    public static function view(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'The requested profile could not be found.']); return; }
        json_out(200, ['profile' => $p]);
    }
}

class SuspendProfileController {
    public static function suspend(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'User profile cannot be found.']); return; }
        if ($p['status'] === 'Suspended') { json_out(409, ['error' => 'User profile is already suspended.']); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Suspended')]);
    }
    public static function activate(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'User profile cannot be found.']); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Active')]);
    }
}

class SearchProfileController {
    public static function search(): void {
        $results = UserProfile::searchProfiles($_GET['q'] ?? '');
        if (count($results) === 0) { json_out(200, ['profiles' => [], 'message' => 'No matching user profiles were found.']); return; }
        json_out(200, ['profiles' => $results]);
    }
    public static function list(): void { json_out(200, ['profiles' => UserProfile::all()]); }
}

class CreateCompanyController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['company_name'])) { json_out(400, ['error' => 'Company Name is a mandatory field.']); return; }
            
            // Validate Contact Number (Must be exactly 8 digits if provided)
            $contactNo = $b['contact_no'] ?? '';
            if (!empty($contactNo)) {
                $digitsOnly = preg_replace('/\D/', '', $contactNo); // Strip out +65, spaces, etc.
                if (strlen($digitsOnly) !== 8) {
                    json_out(400, ['error' => 'Contact Hotline must be exactly 8 digits (e.g., 91234567).']);
                    return;
                }
                $b['contact_no'] = $digitsOnly; // Standardize to just 8 digits
            }

            json_out(201, ['company' => CompanyInfo::saveCompany([
                'owner_id' => session_user()['account_id'], 
                'company_name' => $b['company_name'], 
                'address' => $b['address'] ?? null, 
                'contact_no' => $b['contact_no'] ?? null
            ])]);
        } catch (Throwable $e) { handle_error($e, 'Failed to create company profile.'); }
    }
}

class ViewCompanyController {
    public static function view(array $params): void {
        $c = CompanyInfo::findById($params['id']);
        if (!$c) { json_out(404, ['error' => 'No matching results. Company information could not be found.']); return; }
        json_out(200, ['company' => $c]);
    }
}

class UpdateCompanyController {
    public static function update(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { json_out(404, ['error' => 'Company record not found.']); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission to edit this company.']); return; }
            
            $b = req_body();
            if (empty($b['company_name'])) { json_out(400, ['error' => 'Company Name cannot be left blank.']); return; }

            // Validate Contact Number
            $contactNo = $b['contact_no'] ?? '';
            if (!empty($contactNo)) {
                $digitsOnly = preg_replace('/\D/', '', $contactNo);
                if (strlen($digitsOnly) !== 8) {
                    json_out(400, ['error' => 'Contact Hotline must be exactly 8 digits (e.g., 91234567).']);
                    return;
                }
                $b['contact_no'] = $digitsOnly;
            }

            json_out(200, ['company' => CompanyInfo::updateCompanyInfo($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteCompanyController {
    public static function remove(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { json_out(404, ['error' => 'Company information could not be found.']); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission to delete this company.']); return; }
            CompanyInfo::removeCompany($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'Deletion failed.'); }
    }
}

class SearchCompanyController {
    public static function search(): void {
        $rows = CompanyInfo::findCompanies(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['companies' => [], 'message' => 'No companies found matching the search keyword.']); return; }
        json_out(200, ['companies' => $rows]);
    }
    public static function list(): void { json_out(200, ['companies' => CompanyInfo::byOwner(session_user()['account_id'])]); }
}

class ProjectAuth {
    public static function assertOwner($pid, $uid): ?array {
        $p = Project::findById($pid);
        if (!$p) { json_out(404, ['error' => 'Project not found.']); return null; }
        $c = CompanyInfo::findById($p['company_id']);
        if (!$c || (int)$c['owner_id'] !== (int)$uid) { json_out(403, ['error' => 'You do not have permission to modify this project.']); return null; }
        return $p;
    }
}

class CreateProjectController {
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'Invalid company selected or you do not own it.']); return; }
            if (empty($b['title']) || empty($b['description'])) { json_out(400, ['error' => 'Title and description are required.']); return; }
            json_out(201, ['project' => Project::saveProject(['company_id' => (int)$b['company_id'], 'title' => $b['title'], 'description' => $b['description'], 'status' => (($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing', 'image_url' => $b['image_url'] ?? null])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class CreateCompletedProjController {
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'Invalid company selected.']); return; }
            if (empty($b['title']) || empty($b['description'])) { json_out(400, ['error' => 'Title and description are required.']); return; }
            json_out(201, ['project' => Project::saveProject(['company_id' => (int)$b['company_id'], 'title' => $b['title'], 'description' => $b['description'], 'status' => 'Completed', 'image_url' => $b['image_url'] ?? null])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewProjectController {
    public static function view(array $params): void {
        $p = Project::findById($params['id']);
        if (!$p) { json_out(404, ['error' => 'Project not found.']); return; }
        json_out(200, ['project' => $p]);
    }
    public static function listByCompany(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission.']); return; }
        $rows = Project::byCompany($params['companyId'], $_GET['status'] ?? null);
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No projects exist for this company.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

class UpdateProjectController {
    public static function update(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();
            if (empty($b['title']) || empty($b['description'])) { json_out(400, ['error' => 'Please fill up all fields.']); return; }
            json_out(200, ['project' => Project::updateProject($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class UpdateCompletedProjController {
    public static function update(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();
            if (empty($b['title']) || empty($b['description'])) { json_out(400, ['error' => 'Please fill up all fields.']); return; }
            json_out(200, ['project' => Project::updateProject($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteProjectController {
    public static function remove(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteCompletedProjController {
    public static function remove(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class SearchProjectController {
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission.']); return; }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', $_GET['status'] ?? null);
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

class SearchCompletedController {
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission.']); return; }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', 'Completed');
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching completed projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

class SearchPublicProjectController {
    public static function search(): void {
        $rows = Project::publicSearch($_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

class ViewPublicProjectController {
    public static function view(array $params): void {
        try {
            $p = Project::findById($params['id']);
            if (!$p) { json_out(404, ['error' => 'This project is no longer available.']); return; }
            Project::recordView($p['project_id'], session_user()['account_id'] ?? null);
            json_out(200, ['project' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ProjectInterestController {
    public static function interest(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { json_out(403, ['error' => 'You do not have permission.']); return; }
        json_out(200, ['interest' => Project::interest($params['companyId'])]);
    }
}

class FavouriteController {
    public static function add(): void {
        try {
            $b  = req_body(); $pid = $b['project_id'] ?? null;
            if (!$pid) { json_out(400, ['error' => 'No project selected to save.']); return; }
            if (!Project::findById($pid)) { json_out(404, ['error' => 'The project could not be added because it does not exist.']); return; }
            Favourite::saveFavourite(session_user()['account_id'], (int)$pid);
            json_out(201, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'The project could not be added.'); }
    }
    public static function list(): void { json_out(200, ['favourites' => Favourite::viewFavourites(session_user()['account_id'])]); }
    public static function search(): void {
        $rows = Favourite::searchFavourites(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['favourites' => [], 'message' => 'No matching interior design projects found in your favourite list.']); return; }
        json_out(200, ['favourites' => $rows]);
    }
    public static function remove(array $params): void {
        Favourite::removeFavourite(session_user()['account_id'], $params['projectId']);
        json_out(200, ['ok' => true]);
    }
}

class CreateCategoryController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['category_name'])) { json_out(400, ['error' => 'Category Name is required.']); return; }
            $existing = q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']]);
            if ($existing) { json_out(409, ['error' => 'A category with this name already exists.']); return; }
            json_out(201, ['category' => Category::saveCategory($b)]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be created.'); }
    }
}

class ViewCategoryController {
    public static function list(): void {
        $rows = Category::retrieveCategories();
        if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No IDP categories are available.']); return; }
        json_out(200, ['categories' => $rows]);
    }
    public static function view(array $params): void {
        $c = Category::viewCategory($params['id']);
        if (!$c) { json_out(404, ['error' => 'Category not found.']); return; }
        json_out(200, ['category' => $c]);
    }
}

class UpdateCategoryController {
    public static function update(array $params): void {
        try {
            if (empty(req_body()['category_name'])) { json_out(400, ['error' => 'Category Name cannot be empty.']); return; }
            $c = Category::updateCategory($params['id'], req_body());
            if (!$c) { json_out(404, ['error' => 'The category could not be updated because it was not found.']); return; }
            json_out(200, ['category' => $c]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be updated.'); }
    }
}

class DeleteCategoryController {
    public static function remove(array $params): void {
        try {
            if (!Category::exists($params['id'])) { json_out(404, ['error' => 'The category could not be deleted because it was not found.']); return; }
            Category::deleteCategory($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be deleted.'); }
    }
}

class SearchCategoryController {
    public static function search(): void {
        $rows = Category::findCategory($_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No matching categories were found.']); return; }
        json_out(200, ['categories' => $rows]);
    }
}

class ReportController {
    public static function generate(array $params): void {
        $p = strtolower($params['period'] ?? '');
        if (!in_array($p, ['daily', 'weekly', 'monthly'], true)) { json_out(400, ['error' => 'Invalid report type.']); return; }
        json_out(200, Report::generate($p));
    }
}