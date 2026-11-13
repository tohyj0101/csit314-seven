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
}

class UserProfile
{
    public static function createProfile(array $f): array { $perms = '{' . implode(',', $f['permissions'] ?? []) . '}'; return q1('INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *', [$f['profile_name'], $perms]); }
    public static function UpdateUserProfile($id, array $f): ?array { $perms = isset($f['permissions']) ? '{' . implode(',', $f['permissions']) . '}' : null; return q1('UPDATE user_profile SET profile_name = COALESCE($2, profile_name), permissions = COALESCE($3, permissions) WHERE profile_id = $1 RETURNING *', [$id, $f['profile_name'] ?? null, $perms]); }
    public static function retrieveProfile($id): ?array { return q1('SELECT * FROM user_profile WHERE profile_id = $1', [$id]); }
    public static function updateStatus($id, string $s = 'Suspended'): ?array { return q1('UPDATE user_profile SET status=$2 WHERE profile_id=$1 RETURNING *', [$id, $s]); }
    public static function search(string $t): array { return q('SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id', ['%' . $t . '%']); }
}

class CompanyInfo
{
    public static function createCompany(array $f): array { return q1('INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1,$2,$3,$4) RETURNING *', [$f['owner_id'], $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]); }
    public static function retrieveCompaniesByOwner($id): array { return q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [$id]); }
    public static function viewCompanyInfo($id): ?array { return q1('SELECT * FROM company WHERE company_id=$1', [$id]); }
    public static function updateCompanyInfo($id, array $f): ?array { return q1('UPDATE company SET company_name = $2, address = $3, contact_no = $4, updated_at = now() WHERE company_id = $1 RETURNING *', [$id, $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]); }
    public static function deleteCompany($id): void { ex('DELETE FROM company WHERE company_id=$1', [$id]); }
    public static function searchCompanyInfo($oid, string $t): array { return q('SELECT * FROM company WHERE owner_id=$1 AND (company_name ILIKE $2 OR address ILIKE $2) ORDER BY company_id LIMIT 100', [$oid, '%' . $t . '%']); }
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
}

class Favourite
{
    public static function exists($cid, $pid): bool { return q1('SELECT 1 FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]) !== null; }
    public static function addFavourite($cid, $pid): void { ex('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [$cid, $pid]); }
    public static function getProjectsByCustomerId($cid): array { return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 ORDER BY f.saved_at DESC', [$cid]); }
    public static function searchFavourite($cid, string $t): array { return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)', [$cid, '%' . $t . '%']); }
    public static function removeFavourite($cid, $pid): void { ex('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]); }
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
}