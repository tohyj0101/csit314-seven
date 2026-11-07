<?php
declare(strict_types=1);

/**
 * =============================================================================
 * CONTROL LAYER — src/bce/controllers.php
 * =============================================================================
 * Role in BCE: The "C" layer. Controllers:
 *   1. Read the request (req_body / $_GET / session_user).
 *   2. Validate input (mandatory fields, formats).
 *   3. Enforce ownership / RBAC (defence-in-depth over boundary.php).
 *   4. Call entity methods (SQL lives in entities.php).
 *   5. Emit a JSON response (json_out) or an error (display_error).
 *
 * =============================================================================
 * ORIGINAL-TEAMMATE CONTROLLER NAME PRESERVATION
 * =============================================================================
 * Every controller class below begins with a one-line marker of the form:
 *
 *   /** Current: <ThisClassName>  |  Original teammate: <OriginalClassName> */
 *
 * Rationale: The original Sprint 0/1 design (per the report's BCE diagrams and
 * UML class names, e.g. "LoginController", "createCompletedProj_Controller")
 * used slightly different class names. During the Sprint 3 refactor these were
 * consolidated, but preserving the original teammate class name in the header
 * keeps the codebase traceable back to the report's BCE diagrams and to the
 * Taiga board history. Use the marker to reconcile any diagram discrepancy.
 *
 * =============================================================================
 * DEMO TIP
 * =============================================================================
 * grep for "Original teammate:" to list every renamed controller.
 * grep for "US #" to jump to the user story you want to demonstrate.
 * =============================================================================
 */

/* =============================================================================
 * [SECTION: ERROR HELPERS]  Used by every controller below.
 * =============================================================================
 * display_error() — canonical error shape (consumed by app.js errorDisplay()).
 * handle_error() — exception → error mapping (PDO SQLSTATE translation).
 * ============================================================================= */

/**
 * Emit a display-ready error response.
 *
 * @param int    $status  HTTP status code (400/401/403/404/409/422/500)
 * @param string $message User-facing message
 * @param array  $context Extra keys merged into the payload (e.g. 'field' => 'email')
 */
function display_error(int $status, string $message, array $context = []): void
{
    // Per-status default presentation metadata. The frontend can override this
    // if the controller passed custom 'title'/'icon' in $context.
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

    // Escape user-visible message so the same $message can safely embed in
    // the pre-rendered HTML fragment below.
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    // Build the payload. Every key is included even when redundant (error vs
    // message, html vs message) so old and new frontends both work.
    $payload = array_merge([
        'error'    => $message,     // legacy key — kept for backwards-compatible clients
        'message'  => $message,     // canonical key
        'title'    => $m['title'],
        'severity' => $m['severity'],
        'icon'     => $m['icon'],
        'status'   => $status,
        'display'  => true,
        // Server-rendered HTML so the frontend can inject it directly with
        // el.innerHTML = response.html (see app.js errorHtml()).
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
 *
 * Postgres SQLSTATE prefixes we recognise:
 *   23505 — unique_violation       (e.g. duplicate email on register)
 *   23503 — foreign_key_violation  (e.g. delete company that still has projects)
 *   23502 — not_null_violation     (e.g. required field left blank at DB layer)
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

/* =============================================================================
 * [SECTION: USER ACCOUNT CONTROLLERS]  US #1–#7
 * ============================================================================= */

/** Current: LoginController  |  Original teammate: LoginController */
class LoginController
{
    /**
     * US #1, #13, #35, #39 — Login.
     *
     * Flow:
     *   1. Read email/password → 400 if either missing.
     *   2. UserAcc::authenticate() → null on invalid/expired credentials.
     *   3. Distinguish the null cause:
     *        - 403 if account is Suspended (US #6)
     *        - 403 if profile is Suspended (US #11)
     *        - 401 otherwise
     *   4. On success: regenerate session id (fixation defence), store user +
     *      permissions in $_SESSION, return 200.
     *
     * Permissions arrive as Postgres TEXT[] (e.g. "{user:manage,profile:manage}").
     */
    public static function login(): void
    {
        try {
            $b = req_body();
            $email    = $b['email']    ?? null;
            $password = $b['password'] ?? null;

            // --- 1. Input gate ---
            if (!$email || !$password) {
                display_error(400, 'Please enter both an email and a password.', ['fields' => ['email', 'password']]);
                return;
            }

            // --- 2. Credentials check ---
            $u = UserAcc::authenticate($email, $password);
            if (!$u) {
                // --- 3. Diagnose why ---
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

            // --- 4. Normalise permissions array ---
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

            // Session-fixation defence: rotate id on successful login.
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
    /**
     * US #2, #14, #36, #40 — Logout. Destroys session + expires cookie.
     */
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

    /**
     * Polled by app.js on every route change to rehydrate window.__user.
     * 401 here is expected and triggers the SPA to fall back to login().
     */
    public static function me(): void
    {
        $u = session_user();
        if (!$u) { display_error(401, 'You are not logged in.'); return; }
        json_out(200, ['user' => $u]);
    }
}

/** Current: CreateUserAccController  |  Original teammate: Create_UserAcc_Controller */
class CreateUserAccController {
    /**
     * US #3 — Create a user account.
     *
     * Alternate flows:
     *   A1 — Missing field → 400
     *   A2 — Duplicate email → 409 (pre-check + DB unique constraint)
     *   Invalid email format → 400 with field="email"
     */
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
            json_out(201, ['user' => UserAcc::saveAccount([
                'profile_id'    => (int)$pid,
                'username'      => $username,
                'email'         => $email,
                'password_hash' => hash_password($password),
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: UpdateUserAccController  |  Original teammate: updateUser_Controller */
class UpdateUserAccController {
    /**
     * US #4 — Update a user account.
     *
     * Partial update: only fields present in the request body are applied.
     * If password is empty/blank, the existing hash is kept (so the "reset
     * password" UI doesn't accidentally blank it).
     */
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
            if (!empty($b['password']) && trim($b['password']) !== '') {
                $f['password_hash'] = hash_password($b['password']);
            } else {
                unset($f['password_hash']);
            }
            unset($f['password']);   // never pass plaintext to the entity
            $u = UserAcc::updateUser($id, $f);
            if (!$u) { display_error(404, 'User account not found.'); return; }
            json_out(200, ['user' => $u]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewUserAccController  |  Original teammate: viewUserAccount_Controller */
class ViewUserAccController {
    /** US #5 — Read-only view of a single user account. */
    public static function view(array $params): void {
        $u = UserAcc::findById($params['id']);
        if (!$u) { display_error(404, 'User not found.'); return; }
        json_out(200, ['user' => $u]);
    }
}

/** Current: SuspendUserAccController  |  Original teammate: SuspendUser_Controller */
class SuspendUserAccController {
    /**
     * US #6 — Suspend an account.
     * Idempotency contract: suspending an already-suspended account → 409.
     * This is intentional asymmetry with activate (which is a no-op).
     */
    public static function suspend(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { display_error(404, 'User not found.'); return; }
        if ($status === 'Suspended') { display_error(409, 'This account is already suspended.'); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Suspended')]);
    }

    /** US #6 — Activate an account (idempotent no-op if already active). */
    public static function activate(array $params): void {
        $status = UserAcc::checkActiveStatus($params['id']);
        if ($status === null) { display_error(404, 'User not found.'); return; }
        json_out(200, ['user' => UserAcc::updateStatus($params['id'], 'Active')]);
    }
}

/** Current: SearchUserAccController  |  Original teammate: SearchUser_Controller */
class SearchUserAccController {
    /**
     * US #7 — Search by username or email (ILIKE, case-insensitive).
     * Empty result is NOT an error — 200 with an empty array and a message.
     */
    public static function search(): void {
        $q = $_GET['q'] ?? '';
        $users = UserAcc::findAccounts($q);
        if (count($users) === 0) {
            json_out(200, ['users' => [], 'message' => 'No users found matching the search criteria.']);
            return;
        }
        json_out(200, ['users' => $users]);
    }

    /** US #7 — Full account list (capped at 500 in entity layer). */
    public static function list(): void { json_out(200, ['users' => UserAcc::all()]); }
}

/* =============================================================================
 * [SECTION: USER PROFILE CONTROLLERS]  US #8–#12
 * ============================================================================= */

/** Current: CreateProfileController  |  Original teammate: createProfile_Controller */
class CreateProfileController {
    /** US #8 — Create a user profile (role + permissions). Name must be unique. */
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) {
                display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']);
                return;
            }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']]);
            if ($existing) {
                display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']);
                return;
            }
            json_out(201, ['profile' => UserProfile::createProfile($b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: EditProfileController  |  Original teammate: updateProfileController */
class EditProfileController {
    /** US #9 — Update a user profile (name + permissions). */
    public static function update(array $params): void {
        try {
            $b = req_body();
            if (empty($b['profile_name'])) {
                display_error(400, 'Profile name cannot be empty.', ['field' => 'profile_name']);
                return;
            }
            $existing = q1('SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2', [$b['profile_name'], $params['id']]);
            if ($existing) {
                display_error(409, 'A profile with this name already exists.', ['field' => 'profile_name']);
                return;
            }
            $p = UserProfile::saveProfileSettings($params['id'], $b);
            if (!$p) { display_error(404, 'The requested profile could not be found.'); return; }
            json_out(200, ['profile' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewProfileController  |  Original teammate: viewProfile_Controller */
class ViewProfileController {
    /** US #10 — Read a single profile. */
    public static function view(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'The requested profile could not be found.'); return; }
        json_out(200, ['profile' => $p]);
    }
}

/** Current: SuspendProfileController  |  Original teammate: SuspendUserProfileController */
class SuspendProfileController {
    /**
     * US #11 — Suspend a profile.
     * Suspending a profile blocks login for EVERY account using it. LoginController
     * enforces this via the profile_status check (see authenticate()).
     */
    public static function suspend(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'User profile cannot be found.'); return; }
        if ($p['status'] === 'Suspended') { display_error(409, 'User profile is already suspended.'); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Suspended')]);
    }

    /** US #11 — Activate a profile. */
    public static function activate(array $params): void {
        $p = UserProfile::getProfileDetails($params['id']);
        if (!$p) { display_error(404, 'User profile cannot be found.'); return; }
        json_out(200, ['profile' => UserProfile::setStatus($params['id'], 'Active')]);
    }
}

/** Current: SearchProfileController  |  Original teammate: SearchProfileController */
class SearchProfileController {
    /** US #12 — Search profiles by name (ILIKE). */
    public static function search(): void {
        $results = UserProfile::searchProfiles($_GET['q'] ?? '');
        if (count($results) === 0) {
            json_out(200, ['profiles' => [], 'message' => 'No matching user profiles were found.']);
            return;
        }
        json_out(200, ['profiles' => $results]);
    }

    /** US #12 — List all profiles. */
    public static function list(): void { json_out(200, ['profiles' => UserProfile::all()]); }
}

/* =============================================================================
 * [SECTION: COMPANY CONTROLLERS]  US #15–#19
 * ============================================================================= */

/** Current: CreateCompanyController  |  Original teammate: IDCreate_Cl_Controller */
class CreateCompanyController {
    /**
     * US #15 — Create a company owned by the current designer.
     *
     * Contact hotline: strip non-digits, then require exactly 8. Tolerates
     * input like "9123-4567" or "9123 4567" while rejecting malformed numbers.
     */
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
                'owner_id'     => session_user()['account_id'],
                'company_name' => $b['company_name'],
                'address'      => $b['address'] ?? null,
                'contact_no'   => $b['contact_no'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e, 'Failed to create company profile.'); }
    }
}

/** Current: ViewCompanyController  |  Original teammate: viewCoyinfo_Controller */
class ViewCompanyController {
    /** US #16 — Read a single company (immutable record — no side effects). */
    public static function view(array $params): void {
        $c = CompanyInfo::findById($params['id']);
        if (!$c) { display_error(404, 'No matching results. Company information could not be found.'); return; }
        json_out(200, ['company' => $c]);
    }
}

/** Current: UpdateCompanyController  |  Original teammate: CompanyUpdateController */
class UpdateCompanyController {
    /** US #17 — Update a company; 403 unless caller owns it. */
    public static function update(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { display_error(404, 'Company record not found.'); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) {
                display_error(403, 'You do not have permission to edit this company.');
                return;
            }
            $b = req_body();
            if (empty($b['company_name'])) {
                display_error(400, 'Company Name cannot be left blank.', ['field' => 'company_name']);
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
            json_out(200, ['company' => CompanyInfo::updateCompanyInfo($params['id'], $b)]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: DeleteCompanyController  |  Original teammate: Delete_CompanyInfo_Controller */
class DeleteCompanyController {
    /**
     * US #18 — Delete a company.
     *
     * If the company still has projects, Postgres raises FK 23503 (because
     * project.company_id is ON DELETE RESTRICT). handle_error() translates
     * that to a friendly 400. This is intentional data protection, not a bug.
     */
    public static function remove(array $params): void {
        try {
            $c = CompanyInfo::findById($params['id']);
            if (!$c) { display_error(404, 'Company information could not be found.'); return; }
            if ((int)$c['owner_id'] !== (int)session_user()['account_id']) {
                display_error(403, 'You do not have permission to delete this company.');
                return;
            }
            CompanyInfo::removeCompany($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) {
            handle_error($e, 'Deletion failed.');
        }
    }
}

/** Current: SearchCompanyController  |  Original teammate: searchCoyinfo_Controller */
class SearchCompanyController {
    /** US #19 — Search only the current designer's companies. */
    public static function search(): void {
        $rows = CompanyInfo::findCompanies(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['companies' => [], 'message' => 'No companies found matching the search keyword.']);
            return;
        }
        json_out(200, ['companies' => $rows]);
    }

    /** US #19 — List all companies owned by the current designer. */
    public static function list(): void {
        json_out(200, ['companies' => CompanyInfo::byOwner(session_user()['account_id'])]);
    }
}

/* =============================================================================
 * [SECTION: HELPER — ProjectAuth]  Not a controller; used by project mutations.
 * =============================================================================
 * Centralised ownership check: does the given project belong to a company
 * owned by the given user? Returns the project on success; emits 403/404 on
 * failure and returns null so the caller can early-return.
 * ============================================================================= */
class ProjectAuth {
    public static function assertOwner($pid, $uid): ?array {
        $p = Project::findById($pid);
        if (!$p) { display_error(404, 'Project not found.'); return null; }
        $c = CompanyInfo::findById($p['company_id']);
        if (!$c || (int)$c['owner_id'] !== (int)$uid) {
            display_error(403, 'You do not have permission to modify this project.');
            return null;
        }
        return $p;
    }
}

/* =============================================================================
 * [SECTION: PROJECT CONTROLLERS]  US #20–#31
 * ============================================================================= */

/** Current: CreateProjectController  |  Original teammate: projCreationController */
class CreateProjectController {
    /**
     * US #20 — Create an ongoing (or user-specified status) project.
     *
     * Two defences:
     *   1. company_id must belong to the current designer (403 otherwise).
     *   2. title + description must be non-empty (400 otherwise).
     */
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
                display_error(403, 'Invalid company selected or you do not own it.', ['field' => 'company_id']);
                return;
            }
            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            json_out(201, ['project' => Project::saveProject([
                'company_id'  => (int)$b['company_id'],
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => (($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing',
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: CreateCompletedProjController  |  Original teammate: createCompletedProj_Controller */
class CreateCompletedProjController {
    /**
     * US #25 — Create a COMPLETED project.
     * Same shape as CreateProjectController but forces status='Completed'.
     * Separate endpoint so the "Create Completed Project" form is self-describing.
     */
    public static function create(): void {
        try {
            $b = req_body();
            $c = CompanyInfo::findById($b['company_id'] ?? null);
            if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
                display_error(403, 'Invalid company selected.', ['field' => 'company_id']);
                return;
            }
            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            json_out(201, ['project' => Project::saveProject([
                'company_id'  => (int)$b['company_id'],
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => 'Completed',
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: ViewProjectController  |  Original teammate: viewProjectController */
class ViewProjectController {
    /** US #21 — Read a single project by id. */
    public static function view(array $params): void {
        $p = Project::findById($params['id']);
        if (!$p) { display_error(404, 'Project not found.'); return; }
        json_out(200, ['project' => $p]);
    }

    /** US #21 — List all projects for a company, optional ?status= filter. */
    public static function listByCompany(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            display_error(403, 'You do not have permission.');
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

/** Current: ViewCompletedProjController  |  Original teammate: viewCompletedProj_Controller */
class ViewCompletedProjController {
    /**
     * US #26 — Read a completed project.
     * Returns 404 if the project exists but is not Completed (stale UI defence).
     */
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
    /** US #22 — Update a project. Ownership enforced via ProjectAuth. */
    public static function update(array $params): void {
        try {
            if (!ProjectAuth::assertOwner($params['id'], session_user()['account_id'])) return;
            $b = req_body();

            if (empty($b['title']) || empty($b['description'])) {
                display_error(400, 'Please fill up all fields: Title and Description.');
                return;
            }
            // If moving to a different company, that company must also be owned.
            if (!empty($b['company_id'])) {
                $nc = CompanyInfo::findById((int)$b['company_id']);
                if (!$nc || (int)$nc['owner_id'] !== (int)session_user()['account_id']) {
                    display_error(403, 'You do not own the selected company.', ['field' => 'company_id']);
                    return;
                }
            }

            json_out(200, ['project' => Project::updateProject($params['id'], [
                'company_id'  => $b['company_id'] ?? null,
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => $b['status'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: UpdateCompletedProjController  |  Original teammate: updateCompletedProj_Controller */
class UpdateCompletedProjController {
    /** US #27 — Update a completed project. Same flow as UpdateProjectController. */
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
            json_out(200, ['project' => Project::updateProject($params['id'], [
                'company_id'  => $b['company_id'] ?? null,
                'title'       => $b['title'],
                'description' => $b['description'],
                'status'      => $b['status'] ?? null,
            ])]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/** Current: DeleteProjectController  |  Original teammate: deleteProject_Controller */
class DeleteProjectController {
    /**
     * US #23 — Delete an ongoing project. Cascade removes project_view and
     * favourite rows (schema-level ON DELETE CASCADE).
     */
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
    /** US #28 — Delete a completed project. Same flow as DeleteProjectController. */
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
    /**
     * US #24 — Search projects within a company.
     * Ownership guard runs BEFORE search so a caller can't fingerprint other
     * companies by watching for "no results" vs "forbidden".
     */
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            display_error(403, 'You do not have permission.');
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

/** Current: SearchCompletedController  |  Original teammate: SearchCompeletedController */
class SearchCompletedController {
    /**
     * US #29 — Search completed projects within a company.
     * Same as SearchProjectController but hardcodes status='Completed'.
     *
     * NOTE: The original teammate class name intentionally preserves the
     * historical misspelling "SearchCompeletedController" (Compeleted instead
     * of Completed) — kept as-is for git/report traceability.
     */
    public static function search(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            display_error(403, 'You do not have permission.');
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

/* =============================================================================
 * [SECTION: PUBLIC / CROSS-PROJECT CONTROLLERS]  US #30, #31, #37, #38
 * ============================================================================= */

/** Current: SearchAllInterestController  |  Original teammate: viewCountController */
class SearchAllInterestController {
    /**
     * US #37, #38 (cross-company) — Aggregated views + shortlists for every
     * project the current designer owns across all their companies.
     *
     * Returns COUNTS ONLY. Customer IDs never leave the DB (see Ethical
     * Considerations §Privacy in the report).
     *
     * This is the endpoint exercised by test_37_view_project_views().
     */
    public static function search(): void {
        json_out(200, ['interest' => Project::interestAllByOwner(session_user()['account_id'])]);
    }
}

/** Current: SearchPublicProjectController  |  Original teammate: customerSearchProjectController */
class SearchPublicProjectController {
    /**
     * US #30 — Public project search (Customer Discovery Gallery).
     * No ownership scoping — searches across all designers' projects.
     */
    public static function search(): void {
        $rows = Project::publicSearch($_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['projects' => [], 'message' => 'No matching projects were found.']);
            return;
        }
        json_out(200, ['projects' => $rows]);
    }
}

/** Current: ViewPublicProjectController  |  Original teammate: viewProjController */
class ViewPublicProjectController {
    /**
     * US #31 — View a public project (Customer).
     *
     * CRITICAL SIDE EFFECT: records a row in project_view. This is what drives
     * the designer-facing analytics counters (US #37, #38). It is intentionally
     * the ONLY write path triggered by a Customer browsing action.
     */
    public static function view(array $params): void {
        try {
            $p = Project::findById($params['id']);
            if (!$p) { display_error(404, 'This project is no longer available.'); return; }
            Project::recordView($p['project_id'], session_user()['account_id'] ?? null);
            json_out(200, ['project' => $p]);
        } catch (Throwable $e) { handle_error($e); }
    }
}

/* =============================================================================
 * [SECTION: FAVOURITES CONTROLLERS]  US #32–#34
 * ============================================================================= */

/** Current: ProjectInterestController  |  Original teammate: FavouriteCountController */
class ProjectInterestController {
    /**
     * US #37, #38 (per-company) — Aggregated views + shortlists for one
     * company's projects. Returns COUNTS ONLY.
     */
    public static function interest(array $params): void {
        $c = CompanyInfo::findById($params['companyId']);
        if (!$c || (int)$c['owner_id'] !== (int)session_user()['account_id']) {
            display_error(403, 'You do not have permission.');
            return;
        }
        json_out(200, ['interest' => Project::interest($params['companyId'])]);
    }
}

/** Current: FavouriteController  |  Original teammate: FavouriteController */
class FavouriteController {
    /**
     * US #32 — Save a project to favourites. Idempotent (entity uses
     * ON CONFLICT DO NOTHING) so double-clicking "Save" doesn't error.
     */
    public static function add(): void {
        try {
            $b  = req_body(); $pid = $b['project_id'] ?? null;
            if (!$pid) { display_error(400, 'No project selected to save.', ['field' => 'project_id']); return; }
            if (!Project::findById($pid)) {
                display_error(404, 'The project could not be added because it does not exist.');
                return;
            }
            Favourite::saveFavourite(session_user()['account_id'], (int)$pid);
            json_out(201, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'The project could not be added.'); }
    }

    /** US #34 — List the current customer's saved projects. */
    public static function list(): void {
        json_out(200, ['favourites' => Favourite::viewFavourites(session_user()['account_id'])]);
    }

    /** US #33 — Search within the current customer's favourites. */
    public static function search(): void {
        $rows = Favourite::searchFavourites(session_user()['account_id'], $_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['favourites' => [], 'message' => 'No matching interior design projects found in your favourite list.']);
            return;
        }
        json_out(200, ['favourites' => $rows]);
    }

    /** US #34 — Remove a project from the current customer's favourites. */
    public static function remove(array $params): void {
        Favourite::removeFavourite(session_user()['account_id'], $params['projectId']);
        json_out(200, ['ok' => true]);
    }
}

/** Current: SearchAllProjectsController  |  Original teammate: SearchFavouriteController */
class SearchAllProjectsController {
    /**
     * Cross-company project search scoped to the current designer.
     * Distinct from SearchPublicProjectController (which is customer-facing).
     */
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
    /**
     * Alias controller kept for API stability. Functionally identical to
     * FavouriteController::list(), but retained because earlier client builds
     * referenced it directly. Safe to remove once no external clients depend on it.
     */
    public static function list(): void {
        json_out(200, ['favourites' => Favourite::viewFavourites(session_user()['account_id'])]);
    }
}

/* =============================================================================
 * [SECTION: CATEGORY CONTROLLERS]  US #41–#45 (Platform Manager only)
 * ============================================================================= */

/** Current: CreateCategoryController  |  Original teammate: CreateCategoryController */
class CreateCategoryController {
    /** US #41 — Create a category. Name must be unique. */
    public static function create(): void {
        try {
            $b = req_body();
            if (empty($b['category_name'])) {
                display_error(400, 'Category Name is required.', ['field' => 'category_name']);
                return;
            }
            $existing = q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']]);
            if ($existing) {
                display_error(409, 'A category with this name already exists.', ['field' => 'category_name']);
                return;
            }
            json_out(201, ['category' => Category::saveCategory($b)]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be created.'); }
    }
}

/** Current: ViewCategoryController  |  Original teammate: ViewCategoryController */
class ViewCategoryController {
    /** US #42 — List all categories (alphabetised). */
    public static function list(): void {
        $rows = Category::retrieveCategories();
        if (count($rows) === 0) {
            json_out(200, ['categories' => [], 'message' => 'No IDP categories are available.']);
            return;
        }
        json_out(200, ['categories' => $rows]);
    }

    /** US #42 — Read a single category. */
    public static function view(array $params): void {
        $c = Category::viewCategory($params['id']);
        if (!$c) { display_error(404, 'Category not found.'); return; }
        json_out(200, ['category' => $c]);
    }
}

/** Current: UpdateCategoryController  |  Original teammate: UpdateCategoryController */
class UpdateCategoryController {
    /** US #43 — Update a category (name + description). */
    public static function update(array $params): void {
        try {
            if (empty(req_body()['category_name'])) {
                display_error(400, 'Category Name cannot be empty.', ['field' => 'category_name']);
                return;
            }
            $c = Category::updateCategory($params['id'], req_body());
            if (!$c) {
                display_error(404, 'The category could not be updated because it was not found.');
                return;
            }
            json_out(200, ['category' => $c]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be updated.'); }
    }
}

/** Current: DeleteCategoryController  |  Original teammate: DeleteCategoryController */
class DeleteCategoryController {
    /** US #44 — Delete a category. Cascade at DB level removes project_category links. */
    public static function remove(array $params): void {
        try {
            if (!Category::exists($params['id'])) {
                display_error(404, 'The category could not be deleted because it was not found.');
                return;
            }
            Category::deleteCategory($params['id']);
            json_out(200, ['ok' => true]);
        } catch (Throwable $e) { handle_error($e, 'The category could not be deleted.'); }
    }
}

/** Current: SearchCategoryController  |  Original teammate: SearchCategoryController */
class SearchCategoryController {
    /** US #45 — Search categories by name (ILIKE). */
    public static function search(): void {
        $rows = Category::findCategory($_GET['q'] ?? '');
        if (count($rows) === 0) {
            json_out(200, ['categories' => [], 'message' => 'No matching categories were found.']);
            return;
        }
        json_out(200, ['categories' => $rows]);
    }
}

/* =============================================================================
 * [SECTION: REPORT CONTROLLERS]  US #46–#48 (Platform Manager only)
 * =============================================================================
 * Split from the original single ReportController. Each class delegates to
 * Report::generate($period) — the only difference is the time window.
 * ============================================================================= */

/** Current: DailyReportController  |  Original teammate: DailyReportController */
class DailyReportController {
    /** US #46 — Daily report (rolling 24 hours). */
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('daily'));
    }
}

/** Current: WeeklyReportController  |  Original teammate: WeeklyReportController */
class WeeklyReportController {
    /** US #47 — Weekly report (rolling 7 days). */
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('weekly'));
    }
}

/** Current: MonthlyReportController  |  Original teammate: MonthlyReportController */
class MonthlyReportController {
    /** US #48 — Monthly report (rolling 30 days). */
    public static function generate(array $params = []): void {
        json_out(200, Report::generate('monthly'));
    }
}