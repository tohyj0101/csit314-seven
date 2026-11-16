<?php
declare(strict_types=1);

class UserAcc
{
    public static function RetrieveUserAccount($id): ?array { return q1('SELECT a.account_id, a.username, a.email, a.status, a.profile_id, p.profile_name, p.permissions FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id WHERE a.account_id = $1', [$id]); }
    public static function checkDuplicate(string $e): ?array { return q1('SELECT a.*, p.profile_name, p.permissions, p.status AS profile_status FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id WHERE a.email = $1', [$e]); }
    public static function authenticate(string $email, string $pw): ?array { $u = self::checkDuplicate($email); if (!$u) return null; if ($u['status'] === 'Suspended') return null; if (isset($u['profile_status']) && $u['profile_status'] === 'Suspended') return null; if (!verify_password($pw, $u['password_hash'])) return null; return $u; }
    public static function createUserAccount(array $f): array { return q1('INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1,$2,$3,$4) RETURNING account_id, username, email, status, profile_id', [$f['profile_id'], $f['username'], $f['email'], $f['password_hash']]); }
    public static function UpdateUserAccount($id, array $f): ?array { return q1('UPDATE user_account SET username = COALESCE($2, username), email = COALESCE($3, email), profile_id = COALESCE($4, profile_id), password_hash = COALESCE($5, password_hash), updated_at = now() WHERE account_id = $1 RETURNING account_id, username, email, status, profile_id', [$id, $f['username'] ?? null, $f['email'] ?? null, $f['profile_id'] ?? null, $f['password_hash'] ?? null]); }
    public static function checkActiveStatus($id): ?string { $row = q1('SELECT status FROM user_account WHERE account_id = $1', [$id]); return $row['status'] ?? null; }
    public static function suspendUserAccount($id, string $s = 'Suspended'): ?array { return q1('UPDATE user_account SET status=$2, updated_at=now() WHERE account_id=$1 RETURNING account_id, username, email, status', [$id, $s]); }
    public static function searchUserAccount(string $t): array { return q('SELECT a.account_id, a.username, a.email, a.status, p.profile_name FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id WHERE a.username ILIKE $1 OR a.email ILIKE $1 ORDER BY a.account_id LIMIT 100', ['%' . $t . '%']); }

    // =========================================================================
    // USE-CASE METHODS: business rules live here. Controllers only pass data in
    // and relay the returned payload. Failures are thrown as AppError.
    // =========================================================================

    /** US #1/#13/#35/#39: validate, authenticate, start the session. */
    public static function login(array $b): array
    {
        $email = $b['email'] ?? null;
        $password = $b['password'] ?? null;
        if (!$email || !$password) throw new AppError(400, 'Please enter both an email and a password.');

        $u = self::authenticate($email, $password);
        if (!$u) {
            $candidate = self::checkDuplicate($email);
            if ($candidate && $candidate['status'] === 'Suspended') throw new AppError(403, 'This account has been suspended.');
            if ($candidate && isset($candidate['profile_status']) && $candidate['profile_status'] === 'Suspended') {
                throw new AppError(403, 'The user profile assigned to this account has been suspended.');
            }
            throw new AppError(401, 'Invalid email or password.');
        }

        // Postgres returns TEXT[] as '{a,b}'; turn it into a PHP array.
        $perms = [];
        if (isset($u['permissions'])) {
            $p = $u['permissions'];
            if (is_string($p)) { $p = trim($p, '{}'); $perms = $p === '' ? [] : explode(',', $p); }
            elseif (is_array($p)) { $perms = $p; }
        }

        return ['user' => session_login([
            'account_id'  => (int)$u['account_id'],
            'username'    => $u['username'],
            'role'        => $u['profile_name'],
            'permissions' => $perms,
        ])];
    }

    /** US #2/#14/#36/#40 */
    public static function logout(): array
    {
        session_logout();
        return ['ok' => true];
    }

    /** GET /auth/me: who is logged in? */
    public static function currentUser(?array $sessionUser): array
    {
        if (!$sessionUser) throw new AppError(401, 'You are not logged in.');
        return ['user' => $sessionUser];
    }

    /** US #3 */
    public static function register(array $b): array
    {
        $pid = $b['profile_id'] ?? null;
        $username = $b['username'] ?? null;
        $email = $b['email'] ?? null;
        $password = $b['password'] ?? null;
        if (!$pid || !$username || !$email || !$password) throw new AppError(400, 'Required information is missing.');
        if (!str_contains($email, '@')) throw new AppError(400, 'Please provide a valid email address.', ['field' => 'email']);
        if (self::checkDuplicate($email)) throw new AppError(409, 'An account with this email already exists.', ['field' => 'email']);

        return ['user' => self::createUserAccount([
            'profile_id'    => (int)$pid,
            'username'      => $username,
            'email'         => $email,
            'password_hash' => hash_password($password),
        ])];
    }

    /** US #4 */
    public static function edit($id, array $b): array
    {
        if (!empty($b['email']) && !str_contains($b['email'], '@')) {
            throw new AppError(400, 'Required information is incorrectly formatted.', ['field' => 'email']);
        }
        if (!empty($b['email'])) {
            $existing = self::checkDuplicate($b['email']);
            if ($existing && (int)$existing['account_id'] !== (int)$id) {
                throw new AppError(409, 'An account with this email already exists.', ['field' => 'email']);
            }
        }
        $f = $b;
        if (!empty($b['password']) && trim($b['password']) !== '') $f['password_hash'] = hash_password($b['password']);
        else unset($f['password_hash']);
        unset($f['password']);

        $u = self::UpdateUserAccount($id, $f);
        if (!$u) throw new AppError(404, 'User account not found.');
        return ['user' => $u];
    }

    /** US #5 */
    public static function view($id): array
    {
        if ($id !== null && !is_numeric($id)) throw new AppError(404, 'User not found.');
        $u = self::RetrieveUserAccount($id);
        if (!$u) throw new AppError(404, 'User not found.');
        return ['user' => $u];
    }

    /** US #6 */
    public static function suspend($id): array
    {
        $status = self::checkActiveStatus($id);
        if ($status === null) throw new AppError(404, 'User not found.');
        if ($status === 'Suspended') throw new AppError(409, 'This account is already suspended.');
        return ['user' => self::suspendUserAccount($id, 'Suspended')];
    }

    public static function activate($id): array
    {
        if (self::checkActiveStatus($id) === null) throw new AppError(404, 'User not found.');
        return ['user' => self::suspendUserAccount($id, 'Active')];
    }

    /** US #7 */
    public static function search(string $t): array
    {
        $users = self::searchUserAccount($t);
        if (count($users) === 0) return ['users' => [], 'message' => 'No users found matching the search criteria.'];
        return ['users' => $users];
    }

    public static function listAll(): array
    {
        return ['users' => self::searchUserAccount('')];
    }
}

class UserProfile
{
    public static function createProfile(array $f): array { $perms = '{' . implode(',', $f['permissions'] ?? []) . '}'; return q1('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *', [$f['profile_name'], $perms]); }
    public static function UpdateUserProfile($id, array $f): ?array { $perms = isset($f['permissions']) ? '{' . implode(',', $f['permissions']) . '}' : null; return q1('UPDATE user_profile SET profile_name = COALESCE($2, profile_name), permissions = COALESCE($3, permissions) WHERE profile_id = $1 RETURNING *', [$id, $f['profile_name'] ?? null, $perms]); }
    public static function retrieveProfile($id): ?array { return q1('SELECT * FROM user_profile WHERE profile_id = $1', [$id]); }
    public static function updateStatus($id, string $s = 'Suspended'): ?array { return q1('UPDATE user_profile SET status=$2 WHERE profile_id=$1 RETURNING *', [$id, $s]); }
    public static function search(string $t): array { return q('SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id', ['%' . $t . '%']); }

    // =========================================================================
    // USE-CASE METHODS (business rules; controllers only relay)
    // =========================================================================

    /** US #8 */
    public static function addProfile(array $b): array
    {
        if (empty($b['profile_name'])) throw new AppError(400, 'Profile name cannot be empty.', ['field' => 'profile_name']);
        if (q1('SELECT 1 FROM user_profile WHERE profile_name=$1', [$b['profile_name']])) {
            throw new AppError(409, 'A profile with this name already exists.', ['field' => 'profile_name']);
        }
        return ['profile' => self::createProfile($b)];
    }

    /** US #9 */
    public static function editProfile($id, array $b): array
    {
        if (empty($b['profile_name'])) throw new AppError(400, 'Profile name cannot be empty.', ['field' => 'profile_name']);
        if (q1('SELECT 1 FROM user_profile WHERE profile_name=$1 AND profile_id<>$2', [$b['profile_name'], $id])) {
            throw new AppError(409, 'A profile with this name already exists.', ['field' => 'profile_name']);
        }
        $p = self::UpdateUserProfile($id, $b);
        if (!$p) throw new AppError(404, 'The requested profile could not be found.');
        return ['profile' => $p];
    }

    /** US #10 */
    public static function showProfile($id): array
    {
        if ($id !== null && !is_numeric($id)) throw new AppError(404, 'The requested profile could not be found.');
        $p = self::retrieveProfile($id);
        if (!$p) throw new AppError(404, 'The requested profile could not be found.');
        return ['profile' => $p];
    }

    /** US #11 */
    public static function suspendProfile($id): array
    {
        $p = self::retrieveProfile($id);
        if (!$p) throw new AppError(404, 'User profile cannot be found.');
        if ($p['status'] === 'Suspended') throw new AppError(409, 'User profile is already suspended.');
        return ['profile' => self::updateStatus($id, 'Suspended')];
    }

    public static function activateProfile($id): array
    {
        if (!self::retrieveProfile($id)) throw new AppError(404, 'User profile cannot be found.');
        return ['profile' => self::updateStatus($id, 'Active')];
    }

    /** US #12 */
    public static function searchProfiles(string $t): array
    {
        $results = self::search($t);
        if (count($results) === 0) return ['profiles' => [], 'message' => 'No matching user profiles were found.'];
        return ['profiles' => $results];
    }

    public static function listProfiles(): array
    {
        return ['profiles' => self::search('')];
    }
}

class CompanyInfo
{
    public static function createCompany(array $f): array { return q1('INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1,$2,$3,$4) RETURNING *', [$f['owner_id'], $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]); }
    public static function retrieveCompaniesByOwner($id): array { return q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [$id]); }
    public static function viewCompanyInfo($id): ?array { return q1('SELECT * FROM company WHERE company_id=$1', [$id]); }
    public static function updateCompanyInfo($id, array $f): ?array { return q1('UPDATE company SET company_name = $2, address = $3, contact_no = $4, updated_at = now() WHERE company_id = $1 RETURNING *', [$id, $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]); }
    public static function deleteCompany($id): void { ex('DELETE FROM company WHERE company_id=$1', [$id]); }
    public static function searchCompanyInfo($oid, string $t): array { return q('SELECT * FROM company WHERE owner_id=$1 AND (company_name ILIKE $2 OR address ILIKE $2) ORDER BY company_id LIMIT 100', [$oid, '%' . $t . '%']); }

    // =========================================================================
    // USE-CASE METHODS (business rules; controllers only relay)
    // =========================================================================

    /** Trim + mandatory + 8-digit contact rules shared by create and update. */
    private static function cleanFields(array $b, string $blankTail): array
    {
        $name    = trim((string)($b['company_name'] ?? ''));
        $address = trim((string)($b['address'] ?? ''));
        $contact = trim((string)($b['contact_no'] ?? ''));
        if ($name === '')    throw new AppError(400, "Company Name $blankTail", ['field' => 'company_name']);
        if ($address === '') throw new AppError(400, "Address $blankTail", ['field' => 'address']);
        if ($contact === '') throw new AppError(400, "Contact Hotline $blankTail", ['field' => 'contact_no']);
        $digitsOnly = preg_replace('/\D/', '', $contact);
        if (strlen($digitsOnly) !== 8) {
            throw new AppError(400, 'Contact Hotline must be exactly 8 digits (e.g., 91234567).', ['field' => 'contact_no']);
        }
        return ['company_name' => $name, 'address' => $address, 'contact_no' => $digitsOnly];
    }

    /** Throws 403 unless the company exists and belongs to $userId. Used by the Project use cases too. */
    public static function assertOwnedBy($companyId, $userId, string $message, array $context = []): array
    {
        $c = self::viewCompanyInfo($companyId);
        if (!$c || (int)$c['owner_id'] !== (int)$userId) throw new AppError(403, $message, $context);
        return $c;
    }

    /** US #15 */
    public static function registerCompany(array $b, $ownerId): array
    {
        $f = self::cleanFields($b, 'is a mandatory field.');
        return ['company' => self::createCompany($f + ['owner_id' => $ownerId])];
    }

    /** US #16 */
    public static function showCompany($id): array
    {
        if ($id !== null && !is_numeric($id)) throw new AppError(404, 'Company information could not be found.');
        $c = self::viewCompanyInfo($id);
        if (!$c) throw new AppError(404, 'No matching results. Company information could not be found.');
        return ['company' => $c];
    }

    /** US #17 */
    public static function editCompany($id, array $b, $userId): array
    {
        $c = self::viewCompanyInfo($id);
        if (!$c) throw new AppError(404, 'Company record not found.');
        if ((int)$c['owner_id'] !== (int)$userId) throw new AppError(403, 'You do not have permission to edit this company.');
        $f = self::cleanFields($b, 'cannot be left blank.');
        return ['company' => self::updateCompanyInfo($id, $f)];
    }

    /** US #18 */
    public static function removeCompany($id, $userId): array
    {
        $c = self::viewCompanyInfo($id);
        if (!$c) throw new AppError(404, 'Company information could not be found.');
        if ((int)$c['owner_id'] !== (int)$userId) throw new AppError(403, 'You do not have permission to delete this company.');
        self::deleteCompany($id);
        return ['ok' => true];
    }

    /** US #19 */
    public static function searchOwned($ownerId, string $t): array
    {
        $rows = self::searchCompanyInfo($ownerId, $t);
        if (count($rows) === 0) return ['companies' => [], 'message' => 'No companies found matching the search keyword.'];
        return ['companies' => $rows];
    }

    public static function listOwned($ownerId): array
    {
        return ['companies' => self::retrieveCompaniesByOwner($ownerId)];
    }
}

class Project
{
    public static function createProject(array $f): array { return q1('INSERT INTO project (company_id, title, description, status) VALUES ($1,$2,$3,$4) RETURNING *', [$f['company_id'], $f['title'], $f['description'], $f['status']]); }
    public static function getProject($id): ?array { return q1('SELECT * FROM project WHERE project_id=$1', [$id]); }
    
    public static function getProjectsByCompany($cid, $s = null): array { 
        if ($s === '') $s = null;
        return q('SELECT * FROM project WHERE company_id=$1 AND ($2::text IS NULL OR status::text=$2) ORDER BY project_id DESC', [$cid, $s]); 
    }
    
    public static function retrieveCompletedProject($id): ?array { return q1("SELECT * FROM project WHERE project_id=$1 AND status='Completed'", [$id]); }
    public static function retrieveProject($id): ?array { return q1('SELECT * FROM project WHERE project_id=$1', [$id]); }
    public static function updateProject($id, array $f): ?array { return q1('UPDATE project SET company_id = COALESCE($2, company_id), title = COALESCE($3, title), description = COALESCE($4, description), status = COALESCE($5, status), updated_at = now() WHERE project_id = $1 RETURNING *', [$id, $f['company_id'] ?? null, $f['title'] ?? null, $f['description'] ?? null, $f['status'] ?? null]); }
    public static function deleteProject($id): void { ex('DELETE FROM project WHERE project_id=$1', [$id]); }
    
    public static function SearchProject($cid, string $t, $s = null): array {
        if ($s === '') $s = null;
        return q('SELECT * FROM project WHERE company_id=$1 AND ($3::text IS NULL OR status::text=$3) AND (title ILIKE $2 OR description ILIKE $2) ORDER BY project_id DESC', [$cid, '%' . $t . '%', $s]);
    }
    
    public static function searchPublicProject(string $t): array { return q('SELECT p.*, c.company_name FROM project p JOIN company c ON c.company_id=p.company_id WHERE p.title ILIKE $1 OR p.description ILIKE $1 ORDER BY p.project_id DESC LIMIT 100', ['%' . $t . '%']); }
    public static function recordView($pid, $cid): void { ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [$pid, $cid]); }
    
    public static function getProjectDetails($cid): array {
        return q('SELECT p.project_id, p.title, (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id) AS views, (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id=p.project_id) AS shortlists FROM project p WHERE p.company_id=$1 ORDER BY p.project_id DESC', [$cid]);
    }
    
    public static function searchAllByOwner($ownerId, string $q, $status = null): array {
        $sql = 'SELECT p.*, c.company_name
                  FROM project p 
                  JOIN company c ON c.company_id = p.company_id
                 WHERE c.owner_id = $1';
        $params = [$ownerId];

        if ($status !== null && $status !== '') {
            $params[] = $status;
            // FIXED: Cast status to text to prevent PostgreSQL ENUM crash
            $sql .= ' AND p.status::text = $' . count($params);
        }
        if ($q !== '') {
            $params[] = '%' . $q . '%';
            $i = count($params);
            $params[] = '%' . $q . '%';
            $j = count($params);
            $sql .= ' AND (p.title ILIKE $' . $i . ' OR p.description ILIKE $' . $j . ')';
        }

        $sql .= ' ORDER BY p.project_id DESC LIMIT 200';
        return q($sql, $params);
    }
    
    public static function getAllProjectDetails($ownerId): array {
        return q('SELECT p.project_id, p.title, (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id = p.project_id) AS views, (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id = p.project_id) AS shortlists FROM project p JOIN company c ON c.company_id = p.company_id WHERE c.owner_id = $1 ORDER BY p.project_id DESC', [$ownerId]);
    }

    // =========================================================================
    // USE-CASE METHODS (business rules; controllers only relay)
    // =========================================================================

    /** Throws 404 if the project is missing, 403 if its company is not owned by $userId. */
    public static function assertOwnedBy($projectId, $userId): array
    {
        $p = self::getProject($projectId);
        if (!$p) throw new AppError(404, 'Project not found.');
        $c = CompanyInfo::viewCompanyInfo($p['company_id']);
        if (!$c || (int)$c['owner_id'] !== (int)$userId) throw new AppError(403, 'You do not have permission to modify this project.');
        return $p;
    }

    private static function newProject(array $b, $userId, ?string $forceStatus, string $notOwnerMsg): array
    {
        CompanyInfo::assertOwnedBy($b['company_id'] ?? null, $userId, $notOwnerMsg, ['field' => 'company_id']);
        if (empty($b['title']) || empty($b['description'])) throw new AppError(400, 'Please fill up all fields: Title and Description.');
        $status = $forceStatus ?? ((($b['status'] ?? '') === 'Completed') ? 'Completed' : 'Ongoing');
        return ['project' => self::createProject([
            'company_id' => (int)$b['company_id'], 'title' => $b['title'], 'description' => $b['description'], 'status' => $status,
        ])];
    }

    /** US #20 */
    public static function addProject(array $b, $userId): array
    {
        return self::newProject($b, $userId, null, 'Invalid company selected or you do not own it.');
    }

    /** US #25 */
    public static function addCompletedProject(array $b, $userId): array
    {
        return self::newProject($b, $userId, 'Completed', 'Invalid company selected.');
    }

    /** US #22 / #27 */
    public static function editProject($id, array $b, $userId): array
    {
        self::assertOwnedBy($id, $userId);
        if (empty($b['title']) || empty($b['description'])) throw new AppError(400, 'Please fill up all fields: Title and Description.');
        if (!empty($b['company_id'])) {
            CompanyInfo::assertOwnedBy((int)$b['company_id'], $userId, 'You do not own the selected company.', ['field' => 'company_id']);
        }
        return ['project' => self::updateProject($id, [
            'company_id' => $b['company_id'] ?? null, 'title' => $b['title'], 'description' => $b['description'], 'status' => $b['status'] ?? null,
        ])];
    }

    /** US #23 / #28 */
    public static function removeProject($id, $userId): array
    {
        self::assertOwnedBy($id, $userId);
        self::deleteProject($id);
        return ['ok' => true];
    }

    /** US #21 */
    public static function showProject($id): array
    {
        if ($id !== null && !is_numeric($id)) throw new AppError(404, 'Project not found.');
        $p = self::getProject($id);
        if (!$p) throw new AppError(404, 'Project not found.');
        return ['project' => $p];
    }

    public static function listByCompany($companyId, $userId, $status): array
    {
        CompanyInfo::assertOwnedBy($companyId, $userId, 'You do not have permission.');
        $rows = self::getProjectsByCompany($companyId, $status);
        if (count($rows) === 0) return ['projects' => [], 'message' => 'No projects exist for this company.'];
        return ['projects' => $rows];
    }

    /** US #26 */
    public static function showCompleted($id): array
    {
        $p = self::retrieveCompletedProject($id);
        if (!$p || ($p['status'] ?? null) !== 'Completed') throw new AppError(404, 'Completed project not found.');
        return ['project' => $p];
    }

    /** US #24: with a company id searches that company, otherwise all of the owner's projects. */
    public static function searchOwned($userId, $companyId, string $q, $status): array
    {
        if (!$companyId) return self::searchAllOwned($userId, $q, $status);
        CompanyInfo::assertOwnedBy($companyId, $userId, 'You do not have permission.');
        $rows = self::SearchProject($companyId, $q, $status);
        if (count($rows) === 0) return ['projects' => [], 'message' => 'No matching projects were found.'];
        return ['projects' => $rows];
    }

    public static function searchAllOwned($userId, string $q, $status): array
    {
        $rows = self::searchAllByOwner($userId, $q, $status);
        if (count($rows) === 0) return ['projects' => [], 'message' => 'No matching projects were found.'];
        return ['projects' => $rows];
    }

    /** US #29 */
    public static function searchCompleted($userId, $companyId, string $q): array
    {
        if (!$companyId) throw new AppError(400, 'Company ID is required.');
        CompanyInfo::assertOwnedBy($companyId, $userId, 'You do not have permission.');
        $rows = self::SearchProject($companyId, $q, 'Completed');
        if (count($rows) === 0) return ['projects' => [], 'message' => 'No matching completed projects were found.'];
        return ['projects' => $rows];
    }

    /** US #30 */
    public static function searchPublic(string $q): array
    {
        $rows = self::searchPublicProject($q);
        if (count($rows) === 0) return ['projects' => [], 'message' => 'No matching projects were found.'];
        return ['projects' => $rows];
    }

    /** US #31: returns the project and records the view. */
    public static function viewPublic($id, $viewerId): array
    {
        $p = self::retrieveProject($id);
        if (!$p) throw new AppError(404, 'This project is no longer available.');
        self::recordView($p['project_id'], $viewerId);
        return ['project' => $p];
    }

    /** US #37 / #38 */
    public static function interestForCompany($companyId, $userId): array
    {
        CompanyInfo::assertOwnedBy($companyId, $userId, 'You do not have permission.');
        return ['interest' => self::getProjectDetails($companyId)];
    }

    public static function interestForOwner($ownerId): array
    {
        return ['interest' => self::getAllProjectDetails($ownerId)];
    }
}

class Favourite
{
    public static function exists($cid, $pid): bool { return q1('SELECT 1 FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]) !== null; }
    public static function addFavourite($cid, $pid): void { ex('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [$cid, $pid]); }
    public static function getProjectsByCustomerId($cid): array { return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 ORDER BY f.saved_at DESC', [$cid]); }
    public static function searchFavourite($cid, string $t): array { return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)', [$cid, '%' . $t . '%']); }
    public static function removeFavourite($cid, $pid): void { ex('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]); }

    // =========================================================================
    // USE-CASE METHODS (business rules; controllers only relay)
    // =========================================================================

    /** US #32 */
    public static function save(array $b, $customerId): array
    {
        $pid = $b['project_id'] ?? null;
        if (!$pid) throw new AppError(400, 'No project selected to save.', ['field' => 'project_id']);
        if (!Project::getProject($pid)) throw new AppError(404, 'The project could not be added because it does not exist.');
        self::addFavourite($customerId, (int)$pid);
        return ['ok' => true];
    }

    /** US #34 */
    public static function listFor($customerId): array
    {
        return ['favourites' => self::getProjectsByCustomerId($customerId)];
    }

    /** US #33 */
    public static function searchFor($customerId, string $t): array
    {
        $rows = self::searchFavourite($customerId, $t);
        if (count($rows) === 0) return ['favourites' => [], 'message' => 'No matching interior design projects found in your favourite list.'];
        return ['favourites' => $rows];
    }

    public static function remove($customerId, $projectId): array
    {
        self::removeFavourite($customerId, $projectId);
        return ['ok' => true];
    }
}

class Category
{
    public static function exists($id): bool { return q1('SELECT 1 FROM category WHERE category_id=$1', [$id]) !== null; }
    public static function saveCategory(array $f): array { return q1('INSERT INTO category (category_name, description) VALUES ($1,$2) RETURNING *', [$f['category_name'], $f['description'] ?? null]); }
    public static function retrieveCategories(): array { return q('SELECT * FROM category ORDER BY category_name'); }
    public static function viewCategory($id): ?array { return q1('SELECT * FROM category WHERE category_id=$1', [$id]); }
    public static function updateCategory($id, array $f): ?array { return q1('UPDATE category SET category_name = COALESCE($2, category_name), description = COALESCE($3, description) WHERE category_id=$1 RETURNING *', [$id, $f['category_name'] ?? null, $f['description'] ?? null]); }
    public static function deleteCategory($id): void { ex('DELETE FROM category WHERE category_id=$1', [$id]); }
    public static function findCategory(string $t): array { return q('SELECT * FROM category WHERE category_name ILIKE $1 ORDER BY category_name', ['%' . $t . '%']); }

    // =========================================================================
    // USE-CASE METHODS (business rules; controllers only relay)
    // =========================================================================

    /** US #41 */
    public static function addCategory(array $b): array
    {
        if (empty($b['category_name'])) throw new AppError(400, 'Category Name is required.', ['field' => 'category_name']);
        if (q1('SELECT 1 FROM category WHERE category_name=$1', [$b['category_name']])) {
            throw new AppError(409, 'A category with this name already exists.', ['field' => 'category_name']);
        }
        return ['category' => self::saveCategory($b)];
    }

    /** US #42 */
    public static function listCategories(): array
    {
        $rows = self::retrieveCategories();
        if (count($rows) === 0) return ['categories' => [], 'message' => 'No IDP categories are available.'];
        return ['categories' => $rows];
    }

    public static function showCategory($id): array
    {
        if ($id !== null && !is_numeric($id)) throw new AppError(404, 'Category not found.');
        $c = self::viewCategory($id);
        if (!$c) throw new AppError(404, 'Category not found.');
        return ['category' => $c];
    }

    /** US #43 */
    public static function editCategory($id, array $b): array
    {
        if (empty($b['category_name'])) throw new AppError(400, 'Category Name cannot be empty.', ['field' => 'category_name']);
        $c = self::updateCategory($id, $b);
        if (!$c) throw new AppError(404, 'The category could not be updated because it was not found.');
        return ['category' => $c];
    }

    /** US #44 */
    public static function removeCategory($id): array
    {
        if (!self::exists($id)) throw new AppError(404, 'The category could not be deleted because it was not found.');
        self::deleteCategory($id);
        return ['ok' => true];
    }

    /** US #45 */
    public static function searchCategories(string $t): array
    {
        $rows = self::findCategory($t);
        if (count($rows) === 0) return ['categories' => [], 'message' => 'No matching categories were found.'];
        return ['categories' => $rows];
    }
}

class Report
{
    public static function generateDailyReport(): array { return self::generateReport('daily'); }
    public static function generateWeeklyReport(): array { return self::generateReport('weekly'); }
    public static function generateMonthlyReport(): array { return self::generateReport('monthly'); }

    private static function generateReport(string $period): array
    {
        $interval = match($period) {
            'daily'   => '1 day',
            'weekly'  => '7 days',
            'monthly' => '30 days',
            default   => '1 day',
        };
        $newUsers    = q1("SELECT COUNT(*) as count FROM user_account WHERE created_at >= NOW() - INTERVAL '$interval'");
        $newProjects = q1("SELECT COUNT(*) as count FROM project      WHERE created_at >= NOW() - INTERVAL '$interval'");
        $views       = q1("SELECT COUNT(*) as count FROM project_view WHERE viewed_at  >= NOW() - INTERVAL '$interval'");
        $saves       = q1("SELECT COUNT(*) as count FROM favourite    WHERE saved_at   >= NOW() - INTERVAL '$interval'");
        $topProjects = q("
            SELECT p.title, c.company_name, COUNT(DISTINCT v.view_id) as views, COUNT(DISTINCT f.favourite_id) as shortlists
            FROM project p JOIN company c ON p.company_id = c.company_id
            LEFT JOIN project_view v ON p.project_id = v.project_id LEFT JOIN favourite f ON p.project_id = f.project_id
            GROUP BY p.project_id, p.title, c.company_name ORDER BY views DESC, shortlists DESC LIMIT 10
        ");
        $topProjects = array_map(function($row) { $row['views'] = (int)$row['views']; $row['shortlists'] = (int)$row['shortlists']; return $row; }, $topProjects);
        return [
            'summary' => ['new_users' => (int)($newUsers['count'] ?? 0), 'project_views' => (int)($views['count'] ?? 0), 'shortlists' => (int)($saves['count'] ?? 0), 'new_projects' => (int)($newProjects['count'] ?? 0)],
            'topProjects' => $topProjects,
        ];
    }

    /** US #46-#48: pick the report from the URL segment (daily / weekly / monthly). */
    public static function generate(string $type): array
    {
        $type = strtolower($type);
        return match ($type) {
            'daily'   => self::generateDailyReport(),
            'weekly'  => self::generateWeeklyReport(),
            'monthly' => self::generateMonthlyReport(),
            default   => throw new AppError(400, 'Invalid report type. Expected daily, weekly or monthly.',
                                            ['type' => $type, 'allowed' => ['daily', 'weekly', 'monthly']]),
        };
    }
}
