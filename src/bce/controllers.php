<?php
declare(strict_types=1);

/**
 * Translate PostgreSQL SQLSTATE codes into user-friendly messages.
 * Every BCE "Alternative Flow" is mapped here.
 */
function handle_error(Throwable $e, string $fallback = 'An unexpected system error occurred. Please try again later.'): void
{
    error_log('[Backend Error] ' . $e->getMessage());

    $code = $e instanceof PDOException ? (string)$e->getCode() : '';
    if ($code === '23505') { json_out(409, ['error' => 'A record with this name or email already exists. Please use a different one.']); return; }
    if ($code === '23503') { json_out(400, ['error' => 'Cannot complete action because this record is currently in use by another part of the system.']); return; }
    if ($code === '23502') { json_out(400, ['error' => 'A required field was left blank. Please fill in all mandatory fields.']); return; }
    json_out(500, ['error' => $fallback]);
}

// =============================================================================
// US #1, #2, #13, #14, #35, #36, #39, #40 – Login / Logout
// =============================================================================
class LoginController
{
    /** US #1/#13/#35/#39 – Alternative Flow: missing fields; invalid creds. */
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
                json_out(401, ['error' => 'Invalid email or password.']);
                return;
            }

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'account_id' => (int)$u['account_id'],
                'username'   => $u['username'],
                'role'       => $u['profile_name'],
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
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
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

// =============================================================================
// US #3–#7 – User Account
// =============================================================================
class CreateUserAccController
{
    public static function create(): void
    {
        try {
            $b = req_body();
            $pid      = $b['profile_id'] ?? null;
            $username = $b['username']   ?? null;
            $email    = $b['email']      ?? null;
            $password = $b['password']   ?? null;

            // #3 – Alternative Flow A1: missing / invalid data
            if (!$pid || !$username || !$email || !$password) {
                json_out(400, ['error' => 'Required information is missing. Please complete all mandatory fields.']);
                return;
            }
            if (!str_contains($email, '@')) {
                json_out(400, ['error' => 'Please provide a valid email address format (e.g., user@example.com).']);
                return;
            }
            // #3 – Alternative Flow A2: duplicate account
            if (UserAcc::findByEmail($email)) {
                json_out(409, ['error' => 'An account with this email already exists. Please use different details.']);
                return;
            }
            $u = UserAcc::saveAccount([
                'profile_id'    => (int)$pid,
                'username'      => $username,
                'email'         => $email,
                'password_hash' => hash_password($password),
            ]);
            json_out(201, ['user' => $u]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class UpdateUserAccController
{
    /** US #4 – Alternative Flow: missing/invalid data. */
    public static function update(array $params): void
    {
        try {
            $id = $params['id'];
            $b  = req_body();

            if (!empty($b['email']) && !str_contains($b['email'], '@')) {
                json_out(400, ['error' => 'Required information is incorrectly formatted. Please correct the email field.']);
                return;
            }
            if (!empty($b['email'])) {
                $existing = UserAcc::findByEmail($b['email']);
                if ($existing && (int)$existing['account_id'] !== (int)$id) {
                    json_out(409, ['error' => 'An account with this email already exists. Please use a different one.']);
                    return;
                }
            }
            if (isset($b['username']) && trim((string)$b['username']) === '') {
                json_out(400, ['error' => 'Required information is missing. Username cannot be left empty.']);
                return;
            }

            $f = $b;
            if (!empty($b['password']) && trim($b['password']) !== '') {
                $f['password_hash'] = hash_password($b['password']);
            } else {
                unset($f['password_hash']);
            }
            unset($f['password']);

            $u = UserAcc::updateUser($id, $f);
            if (!$u) { json_out(404, ['error' => 'User account not found.']); return; }
            json_out(200, ['user' => $u]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewUserAccController
{
    /** US #5 – Alternative Flow A1: user not found. */
    public static function view(array $params): void
    {
        $u = UserAcc::findById($params['id']);
        if (!$u) { json_out(404, ['error' => 'User not found.']); return; }
        json_out(200, ['user' => $u]);
    }
}

class SuspendUserAccController
{
    /** US #6 – Alternative Flow A1: already suspended / not found. */
    public static function suspend(array $params): void
    {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null)        { json_out(404, ['error' => 'User not found.']); return; }
        if ($status === 'Suspended') { json_out(409, ['error' => 'This account is already suspended.']); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Suspended')]);
    }

    public static function activate(array $params): void
    {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { json_out(404, ['error' => 'User not found.']); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Active')]);
    }
}

class SearchUserAccController
{
    /** US #7 – Alternative Flow: no matches. */
    public static function search(): void
    {
        $q = $_GET['q'] ?? '';
        $users = UserAcc::findAccounts($q);
        if (count($users) === 0) {
            json_out(200, ['users' => [], 'message' => 'No users found matching the search criteria.']);
            return;
        }
        json_out(200, ['users' => $users]);
    }

    public static function list(): void
    {
        json_out(200, ['users' => UserAcc::all()]);
    }
}

// =============================================================================
// US #8–#12 – User Profile
// =============================================================================
class CreateProfileController
{
    /** US #8 – Alternative Flow A1: empty or duplicate name. */
    public static function create(): void
    {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) {
                json_out(400, ['error' => 'Profile name cannot be empty. Please enter a valid profile name.']);
                return;
            }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']]);
            if ($existing) {
                json_out(409, ['error' => 'A profile with this name already exists. Please use a different name.']);
                return;
            }
            json_out(201, ['profile' => UserProfile::createProfile($b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class EditProfileController
{
    /** US #9 – Alternative Flow: empty or duplicate. */
    public static function update(array $params): void
    {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) {
                json_out(400, ['error' => 'Profile name cannot be empty.']);
                return;
            }
            $existing = q1(
                'SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2',
                [$b['profile_name'], $params['id']]
            );
            if ($existing) {
                json_out(409, ['error' => 'A profile with this name already exists. Please use a different name.']);
                return;
            }
            $p = UserProfile::saveProfileSettings($params['id'], $b);
            if (!$p) { json_out(404, ['error' => 'The requested profile could not be found.']); return; }
            json_out(200, ['profile' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewProfileController
{
    /** US #10 – Alternative Flow: profile not found. */
    public static function view(array $params): void
    {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'The requested profile could not be found.']); return; }
        json_out(200, ['profile' => $p]);
    }
}

class SuspendProfileController
{
    /** US #11 – Alternative Flow 1: not found; Alternative Flow 2: already suspended. */
    public static function suspend(array $params): void
    {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'User profile cannot be found.']); return; }
        if ($p['status'] === 'Suspended') { json_out(409, ['error' => 'User profile is already suspended.']); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Suspended')]);
    }

    public static function activate(array $params): void
    {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { json_out(404, ['error' => 'User profile cannot be found.']); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Active')]);
    }
}

class SearchProfileController
{
    /** US #12 – Alternative Flow: no matching profiles. */
    public static function search(): void
    {
        $results = UserProfile::searchProfiles($_GET['q'] ?? '');
        if (count($results) === 0) {
            json_out(200, ['profiles' => [], 'message' => 'No matching user profiles were found.']);
            return;
        }
        json_out(200, ['profiles' => $results]);
    }

    public static function list(): void
    {
        json_out(200, ['profiles' => UserProfile::all()]);
    }
}

// =============================================================================
// US #15–#19 – Company Information
// =============================================================================
class CreateCompanyController
{
    /** US #15 – Alternative Flow: save failure message. */
    public static function create(): void
    {
        try {
            $b = req_body();
            if (empty($b['company_name'])) {
                json_out(400, ['error' => 'Company Name is a mandatory field.']);
                return;
            }
            $c = CompanyInfo::saveCompany([
                'owner_id'     => session_user()['account_id'],
                'company_name' => $b['company_name'],
                'address'      => $b['address']    ?? null,
                'contact_no'   => $b['contact_no'] ?? null,
            ]);
            json_out(201, ['company' => $c]);
        } catch (Throwable $e) {
            handle_error($e, 'Failed to create company profile.');
        }
    }
}

class ViewCompanyController
{
    /** US #16 – Alternative Flow: no matching results. */
    public static function view(array $params): void
    {
        $c = CompanyInfo::findById($params['id']);
        if (!$c) { json_out(404, ['error' => 'No matching results. Company information could not be found.']); return; }
        json_out(200, ['company' => $c]);
    }
}

class UpdateCompanyController
{
    /** US #17 – Alternative Flow: invalid or incomplete data. */
    public static function update(array $params): void
    {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { json_out(404, ['error' => 'Company record not found.']); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) {
                json_out(403, ['error' => 'You do not have permission to edit this company.']);
                return;
            }
            $b = req_body();
            if (empty($b['company_name'])) {
                json_out(400, ['error' => 'Company information is invalid or incomplete. Company Name cannot be left blank.']);
                return;
            }
            json_out(200, ['company' => CompanyInfo::updateCompanyInfo($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteCompanyController
{
    /** US #18 – Alternative Flow A1: not found; A2: deletion failed. */
    public static function remove(array $params): void
    {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { json_out(404, ['error' => 'Company information could not be found.']); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) {
                json_out(403, ['error' => 'You do not have permission to delete this company.']);
                return;
            }
            CompanyInfo::removeCompany($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) {
            handle_error($e, 'Deletion failed. Unable to delete the selected company information.');
        }
    }
}

class SearchCompanyController
{
    /** US #19 – Alternative Flow A1: no matches. */
    public static function search(): void
    {
        $rows = CompanyInfo::findCompanies(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['companies' => [], 'message' => 'No companies found matching the search keyword.']);
            return;
        }
        json_out(200, ['companies' => $rows]);
    }

    public static function list(): void
    {
        json_out(200, ['companies' => CompanyInfo::byOwner(session_user()['account_id'])]);
    }
}

// =============================================================================
// US #20–#29, #37, #38 – Projects
// =============================================================================
class ProjectAuth
{
    /** Returns project or exits with 404/403. */
    public static function assertOwner($pid, $uid): ?array
    {
        $p = Project::findById($pid);
        if (!$p) { json_out(404, ['error' => 'Project not found.']); return null; }
        $c = CompanyInfo::findById($p['company_id']);
        if (!$c || (int)$c['owner_id'] !== (int)$uid) {
            json_out(403, ['error' => 'You do not have permission to modify this project.']);
            return null;
        }
        return $p;
    }
}

class CreateProjectController
{
    /** US #20 – Alternative Flow A1: missing fields. */
    public static function create(): void
    {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
                json_out(403, ['error' => 'Invalid company selected or you do not own it.']);
                return;
            }
            if (empty($b['title']) || empty($b['description'])) {
                json_out(400, ['error' => 'Title, description and images are required. The project will not be saved.']);
                return;
            }
            json_out(201, ['project' => Project::saveProject([
                'company_id'  => (int)$b['company_id'],
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => (($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing',
                'image_url'   => $b['image_url'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class CreateCompletedProjController
{
    /** US #25 – Alternative Flow A1: missing fields. */
    public static function create(): void
    {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
                json_out(403, ['error' => 'Invalid company selected or you do not own it.']);
                return;
            }
            if (empty($b['title']) || empty($b['description'])) {
                json_out(400, ['error' => 'Title, description and images are required. The project will not be saved.']);
                return;
            }
            json_out(201, ['project' => Project::saveProject([
                'company_id'  => (int)$b['company_id'],
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => 'Completed',
                'image_url'   => $b['image_url'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ViewProjectController
{
    /** US #21 – Alternative Flow A1: no projects exist. */
    public static function view(array $params): void
    {
        $p = Project::findById($params['id']);
        if (!$p) { json_out(404, ['error' => 'No projects exist. Project could not be found.']); return; }
        json_out(200, ['project' => $p]);
    }

    public static function listByCompany(array $params): void
    {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            json_out(403, ['error' => 'You do not have permission to view these projects.']);
            return;
        }
        $rows = Project::byCompany($params['companyId'], $_GET['status'] ?? null);
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No projects exist for this company.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

class UpdateProjectController
{
    /** US #22 – Alternative Flow A1: empty fields prevent update. */
    public static function update(array $params): void
    {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();
            if (empty($b['title']) || empty($b['description'])) {
                json_out(400, ['error' => 'Please fill up all fields. The project cannot be updated.']);
                return;
            }
            json_out(200, ['project' => Project::updateProject($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class UpdateCompletedProjController
{
    /** US #27 – Alternative Flow A1: empty fields. */
    public static function update(array $params): void
    {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();
            if (empty($b['title']) || empty($b['description'])) {
                json_out(400, ['error' => 'Please fill up all fields. The completed project cannot be updated.']);
                return;
            }
            json_out(200, ['project' => Project::updateProject($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteProjectController
{
    /** US #23 – Alternative Flow A1: deletion cancelled (frontend) / not found. */
    public static function remove(array $params): void
    {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class DeleteCompletedProjController
{
    /** US #28 – Alternative Flow NIL. */
    public static function remove(array $params): void
    {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class SearchProjectController
{
    /** US #24 – Alternative Flow A1: no matching projects. */
    public static function search(array $params): void
    {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            json_out(403, ['error' => 'You do not have permission to search these projects.']);
            return;
        }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', $_GET['status'] ?? null);
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

class SearchCompletedController
{
    /** US #29 – Alternative Flow: no matching completed projects. */
    public static function search(array $params): void
    {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            json_out(403, ['error' => 'You do not have permission to search these projects.']);
            return;
        }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', 'Completed');
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No matching completed projects were found.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

class SearchPublicProjectController
{
    /** US #30 – Alternative Flow: no matching projects found. */
    public static function search(): void
    {
        $rows = Project::publicSearch($_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No matching interior design projects were found.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

class ViewPublicProjectController
{
    /** US #31 / #34 – no alternative flow (NIL). */
    public static function view(array $params): void
    {
        try {
            $p = Project::findById($params['id']);
            if (!$p) { json_out(404, ['error' => 'This project is no longer available.']); return; }
            Project::recordView($p['project_id'], session_user()['account_id'] ?? null);
            json_out(200, ['project' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

class ProjectInterestController
{
    /** US #37, #38. */
    public static function interest(array $params): void
    {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            json_out(403, ['error' => 'You do not have permission to view these metrics.']);
            return;
        }
        json_out(200, ['interest' => Project::interest($params['companyId'])]);
    }
}

// =============================================================================
// US #32–#34 – Favourites
// =============================================================================
class FavouriteController
{
    /** US #32 – Alternative Flow: save failed. */
    public static function add(): void
    {
        try {
            $b  = req_body();
            $pid = $b['project_id'] ?? null;
            if (!$pid) {
                json_out(400, ['error' => 'No project selected to save.']);
                return;
            }
            if (!Project::findById($pid)) {
                json_out(404, ['error' => 'The project could not be added to the favourite list because it does not exist.']);
                return;
            }
            Favourite::saveFavourite(session_user()['account_id'], (int)$pid);
            json_out(201, ['ok' => true]);
        } catch (Throwable $e) {
            handle_error($e, 'The project could not be added to the favourite list.');
        }
    }

    /** US #34 – view all favourites. */
    public static function list(): void
    {
        json_out(200, ['favourites' => Favourite::viewFavourites(session_user()['account_id'])]);
    }

    /** US #33 – Alternative Flow: no matching favourites. */
    public static function search(): void
    {
        $rows = Favourite::searchFavourites(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, [
                'favourites' => [],
                'message'    => 'No matching interior design projects found in your favourite list.',
            ]);
            return;
        }
        json_out(200, ['favourites' => $rows]);
    }

    public static function remove(array $params): void
    {
        Favourite::removeFavourite(session_user()['account_id'], $params['projectId']);
        json_out(200, ['ok' => true]);
    }
}

// =============================================================================
// US #41–#45 – Categories
// =============================================================================
class CreateCategoryController
{
    /** US #41 – Alternative Flow: save failure. */
    public static function create(): void
    {
        try {
            $b = req_body();
            if (empty($b['category_name'])) {
                json_out(400, ['error' => 'Category Name is required. The category could not be created.']);
                return;
            }
            $existing = q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']]);
            if ($existing) {
                json_out(409, ['error' => 'A category with this name already exists.']);
                return;
            }
            json_out(201, ['category' => Category::saveCategory($b)]);
        } catch (Throwable $e) {
            handle_error($e, 'The category could not be created.');
        }
    }
}

class ViewCategoryController
{
    /** US #42 – Alternative Flow: no categories available. */
    public static function list(): void
    {
        $rows = Category::retrieveCategories();
        if (count($rows) === 0) {
            json_out(200, ['categories' => [], 'message' => 'No IDP categories are available.']);
            return;
        }
        json_out(200, ['categories' => $rows]);
    }

    public static function view(array $params): void
    {
        $c = Category::viewCategory($params['id']);
        if (!$c) { json_out(404, ['error' => 'Category not found.']); return; }
        json_out(200, ['category' => $c]);
    }
}

class UpdateCategoryController
{
    /** US #43 – Alternative Flow: update failed. */
    public static function update(array $params): void
    {
        try {
            if (empty(req_body()['category_name'])) {
                json_out(400, ['error' => 'Category Name cannot be empty. The category could not be updated.']);
                return;
            }
            $c = Category::updateCategory($params['id'], req_body());
            if (!$c) { json_out(404, ['error' => 'The category could not be updated because it was not found.']); return; }
            json_out(200, ['category' => $c]);
        } catch (Throwable $e) {
            handle_error($e, 'The category could not be updated.');
        }
    }
}

class DeleteCategoryController
{
    /** US #44 – Alternative Flow: delete failed / not found. */
    public static function remove(array $params): void
    {
        try {
            if (!Category::exists($params['id'])) {
                json_out(404, ['error' => 'The category could not be deleted because it was not found.']);
                return;
            }
            Category::deleteCategory($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) {
            handle_error($e, 'The category could not be deleted.');
        }
    }
}

class SearchCategoryController
{
    /** US #45 – Alternative Flow: no matches. */
    public static function search(): void
    {
        $rows = Category::findCategory($_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['categories' => [], 'message' => 'No matching categories were found.']);
            return;
        }
        json_out(200, ['categories' => $rows]);
    }
}

// =============================================================================
// US #46–#48 – Reports
// =============================================================================
class ReportController
{
    public static function generate(array $params): void
    {
        $p = strtolower($params['period'] ?? '');
        if (!in_array($p, ['daily', 'weekly', 'monthly'], true)) {
            json_out(400, ['error' => 'Invalid report type. Please select Daily, Weekly, or Monthly.']);
            return;
        }
        json_out(200, Report::generate($p));
    }
}