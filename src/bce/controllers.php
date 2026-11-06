<?php
declare(strict_types=1);

/**
 * controllers.php — 42 controllers.
 *
 * Annotations show:  Current class name  |  Original teammate class name
 *
 * DISPLAY ERRORS
 * --------------
 * All error responses now go through display_error(), which returns:
 *   {
 *     "error":    "…plain string (legacy, unchanged)…",
 *     "message":  "…same string…",
 *     "title":    "Invalid Input",
 *     "severity": "warning",
 *     "icon":     "⚠️",
 *     "status":   400,
 *     "display":  true,
 *     "html":     "<div class=\"error-alert\" …>…</div>",
 *     …any extra context passed in
 *   }
 * Frontend can render via `data.html` directly, or build its own UI from
 * `title` / `message` / `severity`.
 */

/* =====================================================================
 * ERROR / DISPLAY HELPERS  (not controllers)
 * ===================================================================== */

/**
 * Emit a display-ready error response.
 *
 * @param int    $status  HTTP status code (400/401/403/404/409/422/500)
 * @param string $message User-facing message
 * @param array  $context Extra keys merged into the payload (e.g. 'field' => 'email')
 */
function display_error(int $status, string $message, array $context = []): void
{
    $meta = [
        400 => ['title' => 'Invalid Input',     'severity' => 'warning',  'icon' => '⚠️'],
        401 => ['title' => 'Login Required',    'severity' => 'warning',  'icon' => '🔒'],
        403 => ['title' => 'Access Denied',     'severity' => 'error',    'icon' => '⛔'],
        404 => ['title' => 'Not Found',         'severity' => 'info',     'icon' => '🔍'],
        409 => ['title' => 'Conflict',          'severity' => 'warning',  'icon' => '⚡'],
        422 => ['title' => 'Validation Failed', 'severity' => 'warning',  'icon' => '⚠️'],
        500 => ['title' => 'Server Error',      'severity' => 'critical', 'icon' => '💥'],
    ];
    $m = $meta[$status] ?? ['title' => 'Error', 'severity' => 'error', 'icon' => '❌'];

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    $payload = array_merge([
        'error'    => $message,
        'message'  => $message,
        'title'    => $m['title'],
        'severity' => $m['severity'],
        'icon'     => $m['icon'],
        'status'   => $status,
        'display'  => true,
        'html'     =>
            '<div class="error-alert" data-severity="' . $m['severity'] . '" data-status="' . $status . '" role="alert">'
          .   '<span class="error-alert__icon" aria-hidden="true">' . $m['icon'] . '</span>'
          .   '<div class="error-alert__body">'
          .     '<strong class="error-alert__title">' . $m['title'] . '</strong>'
          .     '<p class="error-alert__message">' . $safeMessage . '</p>'
          .   '</div>'
          . '</div>',
    ], $context);

    json_out($status, $payload);
}

/**
 * Convert a thrown exception into a display-ready error.
 */
function handle_error(Throwable $e, string $fallback = 'An unexpected system error occurred. Please try again later.'): void
{
    error_log('[Backend Error] ' . $e->getMessage());
    $code = $e instanceof PDOException ? (string)$e->getCode() : '';
    if ($code === '23505') { display_error(409, 'A record with this name or email already exists. Please use a different one.'); return; }
    if ($code === '23503') { display_error(400, 'Cannot complete action because this record is currently in use by another part of the system.'); return; }
    if ($code === '23502') { display_error(400, 'A required field was left blank. Please fill in all mandatory fields.'); return; }
    display_error(500, $fallback);
}

/* =====================================================================
 * USER ACCOUNT CONTROLLERS
 * ===================================================================== */

/** Current: LoginController  |  Original teammate: LoginController */
class LoginController
{
    public static function login(): void
    {
        try {
            $b = req_body();
            $email    = $b['email']    ?? null;
            $password = $b['password'] ?? null;

            if (!$email || !$password) {
                display_error(400, 'Please enter both an email and a password.', ['fields' => ['email', 'password']]);
                return;
            }

            $u = UserAcc::authenticate($email, $password);
            if (!$u) {
                $candidate = UserAcc::findByEmail($email);
                if ($candidate && $candidate['status'] === 'Suspended') {
                    display_error(403, 'This account has been suspended. Please contact the administrator.');
                    return;
                }
                if ($candidate && isset($candidate['profile_status']) && $candidate['profile_status'] === 'Suspended') {
                    display_error(403, 'The user profile assigned to this account has been suspended.');
                    return;
                }
                display_error(401, 'Invalid email or password.');
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

/** Current: LogoutController  |  Original teammate: Logout_Controller */
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
        if (!$u) { display_error(401, 'You are not logged in.'); return; }
        json_out(200, ['user' => $u]);
    }
}

/** Current: CreateUserAccController  |  Original teammate: Create_UserAcc_Controller */
class CreateUserAccController {
    public static function create(): void {
        try {
            $b = req_body();
            $pid = $b['profile_id'] ?? null; $username = $b['username'] ?? null;
            $email = $b['email'] ?? null; $password = $b['password'] ?? null;
            if (!$pid || !$username || !$email || !$password) {
                display_error(400, 'Required information is missing.');
                return;
            }
            if (!str_contains($email, '@')) {
                display_error(400, 'Please provide a valid email address.', ['field' => 'email']);
                return;
            }
            if (UserAcc::findByEmail($email)) {
                display_error(409, 'An account with this email already exists.', ['field' => 'email']);
                return;
            }
            json_out(201, ['user' => UserAcc::saveAccount(['profile_id' => (int)$pid, 'username' => $username, 'email' => $email, 'password_hash' => hash_password($password)])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: UpdateUserAccController  |  Original teammate: updateUser_Controller */
class UpdateUserAccController {
    public static function update(array $params): void {
        try {
            $id = $params['id']; $b = req_body();
            if (!empty($b['email']) && !str_contains($b['email'], '@')) {
                display_error(400, 'Required information is incorrectly formatted.', ['field' => 'email']);
                return;
            }
            if (!empty($b['email'])) {
                $existing = UserAcc::findByEmail($b['email']);
                if ($existing && (int)$existing['account_id'] !== (int)$id) {
                    display_error(409, 'An account with this email already exists.', ['field' => 'email']);
                    return;
                }
            }
            $f = $b;
            if (!empty($b['password']) && trim($b['password']) !== '') { $f['password_hash'] = hash_password($b['password']); } else { unset($f['password_hash']); }
            unset($f['password']);
            $u = UserAcc::updateUser($id, $f);
            if (!$u) { display_error(404, 'User account not found.'); return; }
            json_out(200, ['user' => $u]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewUserAccController  |  Original teammate: viewUserAccount_Controller */
class ViewUserAccController {
    public static function view(array $params): void {
        $u = UserAcc::findById($params['id']);
        if (!$u) { display_error(404, 'User not found.'); return; }
        json_out(200, ['user' => $u]);
    }
}

/** Current: SuspendUserAccController  |  Original teammate: SuspendUser_Controller */
class SuspendUserAccController {
    public static function suspend(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { display_error(404, 'User not found.'); return; }
        if ($status === 'Suspended') { display_error(409, 'This account is already suspended.'); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Suspended')]);
    }
    public static function activate(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { display_error(404, 'User not found.'); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Active')]);
    }
}

/** Current: SearchUserAccController  |  Original teammate: SearchUser_Controller */
class SearchUserAccController {
    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $users = UserAcc::findAccounts($q);
        if (count($users) === 0) { json_out(200, ['users' => [], 'message' => 'No users found matching the search criteria.']); return; }
        json_out(200, ['users' => $users]);
    }
    public static function list(): void { json_out(200, ['users' => UserAcc::all()]); }
}

/* =====================================================================
 * USER PROFILE CONTROLLERS
 * ===================================================================== */

/** Current: CreateProfileController  |  Original teammate: createProfile_Controller */
class CreateProfileController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) { display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']); return; }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']]);
            if ($existing) { display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']); return; }
            json_out(201, ['profile' => UserProfile::createProfile($b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: EditProfileController  |  Original teammate: updateProfileController */
class EditProfileController {
    public static function update(array $params): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) { display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']); return; }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2', [$b['profile_name'], $params['id']]);
            if ($existing) { display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']); return; }
            $p = UserProfile::saveProfileSettings($params['id'], $b);
            if (!$p) { display_error(404, 'The requested profile could not be found.'); return; }
            json_out(200, ['profile' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewProfileController  |  Original teammate: viewProfile_Controller */
class ViewProfileController {
    public static function view(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'The requested profile could not be found.'); return; }
        json_out(200, ['profile' => $p]);
    }
}

/** Current: SuspendProfileController  |  Original teammate: SuspendUserProfileController */
class SuspendProfileController {
    public static function suspend(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'User profile cannot be found.'); return; }
        if ($p['status'] === 'Suspended') { display_error(409, 'User profile is already suspended.'); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Suspended')]);
    }
    public static function activate(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'User profile cannot be found.'); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Active')]);
    }
}

/** Current: SearchProfileController  |  Original teammate: SearchProfileController */
class SearchProfileController {
    public static function search(): void {
        $results = UserProfile::searchProfiles($_GET['q'] ?? '');
        if (count($results) === 0) { json_out(200, ['profiles' => [], 'message' => 'No matching user profiles were found.']); return; }
        json_out(200, ['profiles' => $results]);
    }
    public static function list(): void { json_out(200, ['profiles' => UserProfile::all()]); }
}

/* =====================================================================
 * COMPANY CONTROLLERS
 * ===================================================================== */

/** Current: CreateCompanyController  |  Original teammate: IDCreate_Cl_Controller */
class CreateCompanyController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['company_name'])) {
                display_error(400, 'Company Name is a mandatory field.', ['field' => 'company_name']);
                return;
            }

            $contactNo = $b['contact_no'] ?? '';
            if (!empty($contactNo)) {
                $digitsOnly = preg_replace('/\D/', '', $contactNo);
                if (strlen($digitsOnly) !== 8) {
                    display_error(400, 'Contact Hotline must be exactly 8 digits (e.g., 91234567).', ['field' => 'contact_no']);
                    return;
                }
                $b['contact_no'] = $digitsOnly;
            }

            json_out(201, ['company' => CompanyInfo::saveCompany([
                'owner_id'       => session_user()['account_id'],
                'company_name'   => $b['company_name'],
                'contact_person' => $b['contact_person'] ?? null,
                'address'        => $b['address'] ?? null,
                'contact_no'     => $b['contact_no'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e, 'Failed to create company profile.'); }
    }
}

/** Current: ViewCompanyController  |  Original teammate: viewCoyinfo_Controller */
class ViewCompanyController {
    public static function view(array $params): void {
        $c = CompanyInfo::findById($params['id']);
        if (!$c) { display_error(404, 'No matching results. Company information could not be found.'); return; }
        json_out(200, ['company' => $c]);
    }
}

/** Current: UpdateCompanyController  |  Original teammate: CompanyUpdateController */
class UpdateCompanyController {
    public static function update(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { display_error(404, 'Company record not found.'); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission to edit this company.'); return; }

            $b = req_body();
            if (empty($b['company_name'])) { display_error(400, 'Company Name cannot be left blank.', ['field' => 'company_name']); return; }

            $contactNo = $b['contact_no'] ?? '';
            if (!empty($contactNo)) {
                $digitsOnly = preg_replace('/\D/', '', $contactNo);
                if (strlen($digitsOnly) !== 8) {
                    display_error(400, 'Contact Hotline must be exactly 8 digits (e.g., 91234567).', ['field' => 'contact_no']);
                    return;
                }
                $b['contact_no'] = $digitsOnly;
            }

            json_out(200, ['company' => CompanyInfo::updateCompanyInfo($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: DeleteCompanyController  |  Original teammate: Delete_CompanyInfo_Controller */
class DeleteCompanyController {
    public static function remove(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { display_error(404, 'Company information could not be found.'); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission to delete this company.'); return; }
            CompanyInfo::removeCompany($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) {
            // 23503 now maps to a friendly 400: "This record is currently in use"
            handle_error($e, 'Deletion failed.');
        }
    }
}

/** Current: SearchCompanyController  |  Original teammate: searchCoyinfo_Controller */
class SearchCompanyController {
    public static function search(): void {
        $rows = CompanyInfo::findCompanies(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['companies' => [], 'message' => 'No companies found matching the search keyword.']); return; }
        json_out(200, ['companies' => $rows]);
    }
    public static function list(): void { json_out(200, ['companies' => CompanyInfo::byOwner(session_user()['account_id'])]); }
}

/* =====================================================================
 * HELPER — NOT A CONTROLLER
 * ===================================================================== */

class ProjectAuth {
    public static function assertOwner($pid, $uid): ?array {
        $p = Project::findById($pid);
        if (!$p) { display_error(404, 'Project not found.'); return null; }
        $c = CompanyInfo::findById($p['company_id']);
        if (!$c || (int)$c['owner_id'] !== (int)$uid) { display_error(403, 'You do not have permission to modify this project.'); return null; }
        return $p;
    }
}

/* =====================================================================
 * PROJECT CONTROLLERS
 * ===================================================================== */

/** Current: CreateProjectController  |  Original teammate: projCreationController */
class CreateProjectController {
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'Invalid company selected or you do not own it.', ['field' => 'company_id']); return; }

            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            if (isset($b['number_of_rooms']) && $b['number_of_rooms'] !== '' && (!is_numeric($b['number_of_rooms']) || (int)$b['number_of_rooms'] < 1)) {
                display_error(400, 'Number of rooms must be a positive integer.', ['field' => 'number_of_rooms']);
                return;
            }

            json_out(201, ['project' => Project::saveProject([
                'company_id'      => (int)$b['company_id'],
                'title'           => $b['title'],
                'description'     => $b['description'],
                'status'          => (($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing',
                'property_size'   => $b['property_size'] ?? null,
                'number_of_rooms' => $b['number_of_rooms'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: CreateCompletedProjController  |  Original teammate: createCompletedProj_Controller */
class CreateCompletedProjController {
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'Invalid company selected.', ['field' => 'company_id']); return; }

            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            if (isset($b['number_of_rooms']) && $b['number_of_rooms'] !== '' && (!is_numeric($b['number_of_rooms']) || (int)$b['number_of_rooms'] < 1)) {
                display_error(400, 'Number of rooms must be a positive integer.', ['field' => 'number_of_rooms']);
                return;
            }

            json_out(201, ['project' => Project::saveProject([
                'company_id'      => (int)$b['company_id'],
                'title'           => $b['title'],
                'description'     => $b['description'],
                'status'          => 'Completed',
                'property_size'   => $b['property_size'] ?? null,
                'number_of_rooms' => $b['number_of_rooms'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewProjectController  |  Original teammate: viewProjectController */
class ViewProjectController {
    public static function view(array $params): void {
        $p = Project::findById($params['id']);
        if (!$p) { display_error(404, 'Project not found.'); return; }
        json_out(200, ['project' => $p]);
    }
    public static function listByCompany(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
        $rows = Project::byCompany($params['companyId'], $_GET['status'] ?? null);
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No projects exist for this company.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

/** Current: ViewCompletedProjController  |  Original teammate: viewCompletedProj_Controller */
class ViewCompletedProjController {
    public static function view(array $params): void {
        $p = Project::findById($params['id']);
        if (!$p || ($p['status'] ?? null) !== 'Completed') {
            display_error(404, 'Completed project not found.');
            return;
        }
        json_out(200, ['project' => $p]);
    }
}

/** Current: UpdateProjectController  |  Original teammate: updateProject_Controller */
class UpdateProjectController {
    public static function update(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();

            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            // If the caller is reassigning the project to another company,
            // that target company must ALSO belong to this designer.
            if (!empty($b['company_id'])) {
                $nc = CompanyInfo::findById((int)$b['company_id']);
                if (!$nc || (int)$nc['owner_id'] !== (int)session_user()['account_id']) {
                    display_error(403, 'You do not own the selected company.', ['field' => 'company_id']);
                    return;
                }
            }
            if (isset($b['number_of_rooms']) && $b['number_of_rooms'] !== '' && (!is_numeric($b['number_of_rooms']) || (int)$b['number_of_rooms'] < 1)) {
                display_error(400, 'Number of rooms must be a positive integer.', ['field' => 'number_of_rooms']);
                return;
            }

            json_out(200, ['project' => Project::updateProject($params['id'], [
                'company_id'      => $b['company_id'] ?? null,
                'title'           => $b['title'],
                'description'     => $b['description'],
                'status'          => $b['status'] ?? null,
                'property_size'   => $b['property_size'] ?? null,
                'number_of_rooms' => $b['number_of_rooms'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: UpdateCompletedProjController  |  Original teammate: updateCompletedProj_Controller */
class UpdateCompletedProjController {
    public static function update(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();

            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            if (!empty($b['company_id'])) {
                $nc = CompanyInfo::findById((int)$b['company_id']);
                if (!$nc || (int)$nc['owner_id'] !== (int)session_user()['account_id']) {
                    display_error(403, 'You do not own the selected company.', ['field' => 'company_id']);
                    return;
                }
            }
            if (isset($b['number_of_rooms']) && $b['number_of_rooms'] !== '' && (!is_numeric($b['number_of_rooms']) || (int)$b['number_of_rooms'] < 1)) {
                display_error(400, 'Number of rooms must be a positive integer.', ['field' => 'number_of_rooms']);
                return;
            }

            json_out(200, ['project' => Project::updateProject($params['id'], [
                'company_id'      => $b['company_id'] ?? null,
                'title'           => $b['title'],
                'description'     => $b['description'],
                'status'          => $b['status'] ?? null,
                'property_size'   => $b['property_size'] ?? null,
                'number_of_rooms' => $b['number_of_rooms'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: DeleteProjectController  |  Original teammate: deleteProject_Controller */
class DeleteProjectController {
    public static function remove(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: DeleteCompletedProjController  |  Original teammate: deleteProj_controller */
class DeleteCompletedProjController {
    public static function remove(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            Project::deleteProject($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: SearchProjectController  |  Original teammate: SearchProjectController */
class SearchProjectController {
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', $_GET['status'] ?? null);
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

/** Current: SearchCompletedController  |  Original teammate: SearchCompeletedController */
class SearchCompletedController {
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
        $rows = Project::searchProject($params['companyId'], $_GET['q'] ?? '', 'Completed');
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching completed projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

/* =====================================================================
 * PUBLIC / CROSS-PROJECT CONTROLLERS
 * ===================================================================== */

/** Current: SearchAllInterestController  |  Original teammate: viewCountController */
class SearchAllInterestController {
    public static function search(): void {
        json_out(200, ['interest' => Project::interestAllByOwner(session_user()['account_id'])]);
    }
}

/** Current: SearchPublicProjectController  |  Original teammate: customerSearchProjectController */
class SearchPublicProjectController {
    public static function search(): void {
        $rows = Project::publicSearch($_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']); return; }
        json_out(200, ['projects' => $rows]);
    }
}

/** Current: ViewPublicProjectController  |  Original teammate: viewProjController */
class ViewPublicProjectController {
    public static function view(array $params): void {
        try {
            $p = Project::findById($params['id']);
            if (!$p) { display_error(404, 'This project is no longer available.'); return; }
            Project::recordView($p['project_id'], session_user()['account_id'] ?? null);
            json_out(200, ['project' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/* =====================================================================
 * FAVOURITES CONTROLLERS
 * ===================================================================== */

/** Current: ProjectInterestController  |  Original teammate: FavouriteCountController */
class ProjectInterestController {
    public static function interest(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) { display_error(403, 'You do not have permission.'); return; }
        json_out(200, ['interest' => Project::interest($params['companyId'])]);
    }
}

/** Current: FavouriteController  |  Original teammate: FavouriteController */
class FavouriteController {
    public static function add(): void {
        try {
            $b  = req_body(); $pid = $b['project_id'] ?? null;
            if (!$pid) { display_error(400, 'No project selected to save.', ['field' => 'project_id']); return; }
            if (!Project::findById($pid)) { display_error(404, 'The project could not be added because it does not exist.'); return; }
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

/** Current: SearchAllProjectsController  |  Original teammate: SearchFavouriteController */
class SearchAllProjectsController {
    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $status = $_GET['status'] ?? null;
        $rows = Project::searchAllByOwner(session_user()['account_id'], $q, $status);
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

/** Current: ViewFavouritesController  |  Original teammate: viewFavouritesController */
class ViewFavouritesController {
    public static function list(): void {
        json_out(200, ['favourites' => Favourite::viewFavourites(session_user()['account_id'])]);
    }
}

/* =====================================================================
 * CATEGORY CONTROLLERS
 * ===================================================================== */

/** Current: CreateCategoryController  |  Original teammate: CreateCategoryController */
class CreateCategoryController {
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['category_name'])) { display_error(400, 'Category Name is required.', ['field' => 'category_name']); return; }
            $existing = q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']]);
            if ($existing) { display_error(409, 'A category with this name already exists.', ['field' => 'category_name']); return; }
            json_out(201, ['category' => Category::saveCategory($b)]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be created.'); }
    }
}

/** Current: ViewCategoryController  |  Original teammate: ViewCategoryController */
class ViewCategoryController {
    public static function list(): void {
        $rows = Category::retrieveCategories();
        if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No IDP categories are available.']); return; }
        json_out(200, ['categories' => $rows]);
    }
    public static function view(array $params): void {
        $c = Category::viewCategory($params['id']);
        if (!$c) { display_error(404, 'Category not found.'); return; }
        json_out(200, ['category' => $c]);
    }
}

/** Current: UpdateCategoryController  |  Original teammate: UpdateCategoryController */
class UpdateCategoryController {
    public static function update(array $params): void {
        try {
            if (empty(req_body()['category_name'])) { display_error(400, 'Category Name cannot be empty.', ['field' => 'category_name']); return; }
            $c = Category::updateCategory($params['id'], req_body());
            if (!$c) { display_error(404, 'The category could not be updated because it was not found.'); return; }
            json_out(200, ['category' => $c]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be updated.'); }
    }
}

/** Current: DeleteCategoryController  |  Original teammate: DeleteCategoryController */
class DeleteCategoryController {
    public static function remove(array $params): void {
        try {
            if (!Category::exists($params['id'])) { display_error(404, 'The category could not be deleted because it was not found.'); return; }
            Category::deleteCategory($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be deleted.'); }
    }
}

/** Current: SearchCategoryController  |  Original teammate: SearchCategoryController */
class SearchCategoryController {
    public static function search(): void {
        $rows = Category::findCategory($_GET['q'] ?? '');
        if (count($rows) === 0) { json_out(200, ['categories' => [], 'message' => 'No matching categories were found.']); return; }
        json_out(200, ['categories' => $rows]);
    }
}

/* =====================================================================
 * REPORT CONTROLLERS  (split from original single ReportController)
 * ===================================================================== */

/** Current: DailyReportController  |  Original teammate: DailyReportController */
class DailyReportController {
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('daily'));
    }
}

/** Current: WeeklyReportController  |  Original teammate: WeeklyReportController */
class WeeklyReportController {
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('weekly'));
    }
}

/** Current: MonthlyReportController  |  Original teammate: MonthlyReportController */
class MonthlyReportController {
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('monthly'));
    }
}