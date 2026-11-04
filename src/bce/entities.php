<?php
declare(strict_types=1);

class UserAcc
{
    public static function findById($id): ?array
    {
        return q1(
            'SELECT a.account_id, a.username, a.email, a.status, a.profile_id, p.profile_name, p.permissions
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.account_id = $1',
            [$id]
        );
    }

    public static function findByEmail(string $e): ?array
    {
        return q1(
            'SELECT a.*, p.profile_name, p.permissions, p.status AS profile_status
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.email = $1',
            [$e]
        );
    }

    public static function authenticate(string $email, string $pw): ?array
    {
        $u = self::findByEmail($email);
        if (!$u) return null;
        if ($u['status'] === 'Suspended') return null;
        if (isset($u['profile_status']) && $u['profile_status'] === 'Suspended') return null;
        if (!verify_password($pw, $u['password_hash'])) return null;
        return $u;
    }

    public static function saveAccount(array $f): array
    {
        return q1(
            'INSERT INTO user_account (profile_id, username, email, password_hash)
             VALUES ($1,$2,$3,$4) RETURNING account_id, username, email, status, profile_id',
            [$f['profile_id'], $f['username'], $f['email'], $f['password_hash']]
        );
    }

    public static function updateUser($id, array $f): ?array
    {
        return q1(
            'UPDATE user_account SET
                username      = COALESCE($2, username),
                email         = COALESCE($3, email),
                profile_id    = COALESCE($4, profile_id),
                password_hash = COALESCE($5, password_hash),
                updated_at    = now()
             WHERE account_id = $1
             RETURNING account_id, username, email, status, profile_id',
            [$id, $f['username'] ?? null, $f['email'] ?? null, $f['profile_id'] ?? null, $f['password_hash'] ?? null]
        );
    }

    public static function checkActiveStatus($id): ?string
    {
        $row = q1('SELECT status FROM user_account WHERE account_id = $1', [$id]);
        return $row['status'] ?? null;
    }

    public static function updateStatus($id, string $s): ?array
    {
        return q1(
            'UPDATE user_account SET status=$2, updated_at=now() WHERE account_id=$1 RETURNING account_id, username, email, status',
            [$id, $s]
        );
    }

    public static function findAccounts(string $t): array
    {
        return q(
            'SELECT a.account_id, a.username, a.email, a.status, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.username ILIKE $1 OR a.email ILIKE $1 ORDER BY a.account_id LIMIT 100',
            ['%' . $t . '%']
        );
    }

    public static function all(): array
    {
        return q(
            'SELECT a.account_id, a.username, a.email, a.status, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              ORDER BY a.account_id LIMIT 500'
        );
    }
}

class UserProfile
{
    public static function createProfile(array $f): array
    {
        $perms = '{' . implode(',', $f['permissions'] ?? []) . '}';
        return q1(
            'INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *',
            [$f['profile_name'], $perms]
        );
    }

    public static function all(): array
    {
        return q('SELECT * FROM user_profile ORDER BY profile_id');
    }

    public static function saveProfileSettings($id, array $f): ?array
    {
        $perms = isset($f['permissions']) ? '{' . implode(',', $f['permissions']) . '}' : null;
        return q1(
            'UPDATE user_profile SET profile_name = COALESCE($2, profile_name), permissions = COALESCE($3, permissions)
              WHERE profile_id = $1 RETURNING *',
            [$id, $f['profile_name'] ?? null, $perms]
        );
    }

    public static function getProfileDetails($id): ?array
    {
        return q1('SELECT * FROM user_profile WHERE profile_id = $1', [$id]);
    }

    public static function setStatus($id, string $s): ?array
    {
        return q1('UPDATE user_profile SET status=$2 WHERE profile_id=$1 RETURNING *', [$id, $s]);
    }

    public static function searchProfiles(string $t): array
    {
        return q('SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id', ['%' . $t . '%']);
    }
}

class CompanyInfo
{
    public static function saveCompany(array $f): array
    {
        return q1(
            'INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1,$2,$3,$4) RETURNING *',
            [$f['owner_id'], $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]
        );
    }
    public static function byOwner($id): array { return q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [$id]); }
    public static function findById($id): ?array { return q1('SELECT * FROM company WHERE company_id=$1', [$id]); }
    public static function updateCompanyInfo($id, array $f): ?array {
        return q1('UPDATE company SET company_name=$2, address=$3, contact_no=$4, updated_at=now() WHERE company_id=$1 RETURNING *', [$id, $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]);
    }
    public static function removeCompany($id): void { ex('DELETE FROM company WHERE company_id=$1', [$id]); }
    public static function findCompanies($oid, string $t): array {
        return q('SELECT * FROM company WHERE owner_id=$1 AND (company_name ILIKE $2 OR address ILIKE $2) ORDER BY company_id LIMIT 100', [$oid, '%' . $t . '%']);
    }
}

class Project
{
    public static function saveProject(array $f): array {
        return q1('INSERT INTO project (company_id, title, description, status) VALUES ($1,$2,$3,$4) RETURNING *', [$f['company_id'], $f['title'], $f['description'], $f['status']]);
    }
    
    public static function findById($id): ?array { return q1('SELECT * FROM project WHERE project_id=$1', [$id]); }
    
    public static function byCompany($cid, $s = null): array {
        return q('SELECT * FROM project WHERE company_id=$1 AND ($2::text IS NULL OR status=$2) ORDER BY project_id DESC', [$cid, $s]);
    }
    
    // [ITEM 6 / ITEM 16] company_id is no longer part of the update payload
    public static function updateProject($id, array $f): ?array {
        return q1(
            'UPDATE project 
             SET title = COALESCE($2, title), 
                 description = COALESCE($3, description), 
                 status = COALESCE($4, status), 
                 updated_at = now() 
             WHERE project_id = $1 RETURNING *', 
            [
                $id, 
                $f['title'] ?? null, 
                $f['description'] ?? null, 
                $f['status'] ?? null
            ]
        );
    }
    
    public static function deleteProject($id): void { ex('DELETE FROM project WHERE project_id=$1', [$id]); }
    
    public static function searchProject($cid, string $t, $s = null): array {
        return q('SELECT * FROM project WHERE company_id=$1 AND ($3::text IS NULL OR status=$3) AND (title ILIKE $2 OR description ILIKE $2) ORDER BY project_id DESC', [$cid, '%' . $t . '%', $s]);
    }
    
    public static function publicSearch(string $t): array {
        return q('SELECT p.*, c.company_name FROM project p JOIN company c ON c.company_id=p.company_id WHERE p.title ILIKE $1 OR p.description ILIKE $1 ORDER BY p.project_id DESC LIMIT 100', ['%' . $t . '%']);
    }
    
    public static function recordView($pid, $cid): void { ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [$pid, $cid]); }
    
    public static function interest($cid): array {
        return q('SELECT p.project_id, p.title, (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id) AS views, (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id=p.project_id) AS shortlists FROM project p WHERE p.company_id=$1 ORDER BY p.project_id DESC', [$cid]);
    }

    // [ITEMS 8 & 9] Cross-company search for the designer's own projects
    public static function searchAllByOwner($ownerId, string $q, $status = null): array {
        $sql = 'SELECT p.*, c.company_name
                  FROM project p
                  JOIN company c ON c.company_id = p.company_id
                 WHERE c.owner_id = $1';
        $params = [$ownerId];
        if ($status !== null && $status !== '') {
            $params[] = $status;
            $sql .= ' AND p.status = $' . count($params);
        }
        if ($q !== '') {
            $params[] = '%' . $q . '%';
            $i = count($params);
            $sql .= ' AND (p.title ILIKE $' . $i . ' OR p.description ILIKE $' . $i . ')';
        }
        $sql .= ' ORDER BY p.project_id DESC LIMIT 200';
        return q($sql, $params);
    }

    // [ITEMS 8 & 9] Interest metrics across all of the designer's projects
    public static function interestAllByOwner($ownerId): array {
        return q(
            'SELECT p.project_id, p.title,
                    (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id = p.project_id) AS views,
                    (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id = p.project_id) AS shortlists
               FROM project p
               JOIN company c ON c.company_id = p.company_id
              WHERE c.owner_id = $1
              ORDER BY p.project_id DESC',
            [$ownerId]
        );
    }
}

class Favourite
{
    public static function exists($cid, $pid): bool { return q1('SELECT 1 FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]) !== null; }
    
    public static function saveFavourite($cid, $pid): void { ex('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [$cid, $pid]); }
    
    public static function viewFavourites($cid): array {
        return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 ORDER BY f.saved_at DESC', [$cid]);
    }
    
    public static function searchFavourites($cid, string $t): array {
        return q('SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at FROM favourite f JOIN project p ON p.project_id=f.project_id WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)', [$cid, '%' . $t . '%']);
    }
    
    public static function removeFavourite($cid, $pid): void { ex('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]); }
}

class Category
{
    public static function exists($id): bool { return q1('SELECT 1 FROM category WHERE category_id=$1', [$id]) !== null; }
    
    public static function saveCategory(array $f): array {
        return q1('INSERT INTO category (category_name, description) VALUES ($1,$2) RETURNING *', [$f['category_name'], $f['description'] ?? null]);
    }
    
    public static function retrieveCategories(): array { return q('SELECT * FROM category ORDER BY category_name'); }
    
    public static function viewCategory($id): ?array { return q1('SELECT * FROM category WHERE category_id=$1', [$id]); }
    
    public static function updateCategory($id, array $f): ?array {
        return q1('UPDATE category SET category_name = COALESCE($2, category_name), description = COALESCE($3, description) WHERE category_id=$1 RETURNING *', [$id, $f['category_name'] ?? null, $f['description'] ?? null]);
    }
    
    public static function deleteCategory($id): void { ex('DELETE FROM category WHERE category_id=$1', [$id]); }
    
    public static function findCategory(string $t): array { return q('SELECT * FROM category WHERE category_name ILIKE $1 ORDER BY category_name', ['%' . $t . '%']); }
}

class Report
{
    public static function generate(string $p): array
    {
        $tz = 'Asia/Singapore';

        // 1. Build the exact time conditions based on the requested period
        if ($p === 'daily') {
            $condCreated = "(created_at AT TIME ZONE '$tz')::date = (now() AT TIME ZONE '$tz')::date";
            $condViewed  = "(viewed_at AT TIME ZONE '$tz')::date = (now() AT TIME ZONE '$tz')::date";
            $condSaved   = "(saved_at AT TIME ZONE '$tz')::date = (now() AT TIME ZONE '$tz')::date";
        } elseif ($p === 'weekly') {
            $condCreated = "(created_at AT TIME ZONE '$tz') >= date_trunc('week', now() AT TIME ZONE '$tz')";
            $condViewed  = "(viewed_at AT TIME ZONE '$tz') >= date_trunc('week', now() AT TIME ZONE '$tz')";
            $condSaved   = "(saved_at AT TIME ZONE '$tz') >= date_trunc('week', now() AT TIME ZONE '$tz')";
        } else { // monthly
            $condCreated = "(created_at AT TIME ZONE '$tz') >= date_trunc('month', now() AT TIME ZONE '$tz')";
            $condViewed  = "(viewed_at AT TIME ZONE '$tz') >= date_trunc('month', now() AT TIME ZONE '$tz')";
            $condSaved   = "(saved_at AT TIME ZONE '$tz') >= date_trunc('month', now() AT TIME ZONE '$tz')";
        }

        // 2. Fetch the Summary Cards (New Users, Total Views, Total Saves, New Projects)
        $summary = q1(
            "SELECT
                (SELECT COUNT(*)::int FROM user_account WHERE $condCreated) AS new_users,
                (SELECT COUNT(*)::int FROM project_view WHERE $condViewed)  AS project_views,
                (SELECT COUNT(*)::int FROM favourite    WHERE $condSaved)   AS shortlists,
                (SELECT COUNT(*)::int FROM project      WHERE $condCreated) AS new_projects",
            []
        );

        // 3. Fetch the Top Projects Leaderboard
        $topProjects = q(
            "SELECT p.title, c.company_name,
                (SELECT COUNT(*)::int FROM project_view v 
                  WHERE v.project_id = p.project_id AND $condViewed) AS views,
                  
                (SELECT COUNT(*)::int FROM favourite f 
                  WHERE f.project_id = p.project_id AND $condSaved) AS shortlists
               FROM project p 
               JOIN company c ON p.company_id = c.company_id 
               ORDER BY views DESC 
               LIMIT 10",
            []
        );
        
        return [
            'period' => $p, 
            'summary' => $summary, 
            'topProjects' => $topProjects
        ];
    }
}