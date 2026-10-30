<?php
declare(strict_types=1);

// =============================================================================
// USER ACCOUNT ENTITY (US #1 – #7)
// =============================================================================
class UserAcc
{
    public static function findById($id): ?array
    {
        return q1(
            'SELECT a.account_id, a.username, a.email, a.status, a.profile_id, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.account_id = $1',
            [$id]
        );
    }

    public static function findByEmail(string $e): ?array
    {
        return q1(
            'SELECT a.*, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.email = $1',
            [$e]
        );
    }

    /** US #1/#13/#35/#39 – combined "authenticate" (email + password + status). */
    public static function authenticate(string $email, string $pw): ?array
    {
        $u = self::findByEmail($email);
        if (!$u)                    return null;
        if ($u['status'] === 'Suspended') return null;
        if (!verify_password($pw, $u['password_hash'])) return null;
        return $u;
    }

    public static function saveAccount(array $f): array
    {
        return q1(
            'INSERT INTO user_account (profile_id, username, email, password_hash)
             VALUES ($1,$2,$3,$4)
             RETURNING account_id, username, email, status, profile_id',
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
            [
                $id,
                $f['username']      ?? null,
                $f['email']         ?? null,
                $f['profile_id']    ?? null,
                $f['password_hash'] ?? null,
            ]
        );
    }

    /** US #6 – helper per BCE `CheckActiveStatus`. */
    public static function checkActiveStatus($id): ?string
    {
        $row = q1('SELECT status FROM user_account WHERE account_id = $1', [$id]);
        return $row['status'] ?? null;
    }

    /** US #6 – helper per BCE `UpdateStatus`. */
    public static function updateStatus($id, string $s): ?array
    {
        return q1(
            'UPDATE user_account SET status=$2, updated_at=now()
              WHERE account_id=$1
              RETURNING account_id, username, email, status',
            [$id, $s]
        );
    }

    public static function findAccounts(string $t): array
    {
        return q(
            'SELECT a.account_id, a.username, a.email, a.status, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.username ILIKE $1 OR a.email ILIKE $1
              ORDER BY a.account_id LIMIT 100',
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

// =============================================================================
// USER PROFILE ENTITY (US #8 – #12)
// =============================================================================
class UserProfile
{
    public static function createProfile(array $f): array
    {
        return q1(
            'INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *',
            [$f['profile_name'], '{' . implode(',', $f['permissions'] ?? []) . '}']
        );
    }

    public static function all(): array
    {
        return q('SELECT * FROM user_profile ORDER BY profile_id');
    }

    public static function saveProfileSettings($id, array $f): ?array
    {
        $perms = isset($f['permissions'])
            ? '{' . implode(',', $f['permissions']) . '}'
            : null;

        return q1(
            'UPDATE user_profile
                SET profile_name = COALESCE($2, profile_name),
                    permissions  = COALESCE($3, permissions)
              WHERE profile_id = $1
              RETURNING *',
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
        return q(
            'SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id',
            ['%' . $t . '%']
        );
    }
}

// =============================================================================
// COMPANY / COMPANY-INFO ENTITY (US #15 – #19)
// =============================================================================
class CompanyInfo
{
    public static function saveCompany(array $f): array
    {
        return q1(
            'INSERT INTO company (owner_id, company_name, address, contact_no)
             VALUES ($1,$2,$3,$4) RETURNING *',
            [$f['owner_id'], $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]
        );
    }

    public static function byOwner($id): array
    {
        return q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [$id]);
    }

    public static function findById($id): ?array
    {
        return q1('SELECT * FROM company WHERE company_id=$1', [$id]);
    }

    public static function updateCompanyInfo($id, array $f): ?array
    {
        return q1(
            'UPDATE company
                SET company_name=$2, address=$3, contact_no=$4, updated_at=now()
              WHERE company_id=$1
              RETURNING *',
            [$id, $f['company_name'], $f['address'] ?? null, $f['contact_no'] ?? null]
        );
    }

    public static function removeCompany($id): void
    {
        ex('DELETE FROM company WHERE company_id=$1', [$id]);
    }

    public static function findCompanies($oid, string $t): array
    {
        return q(
            'SELECT * FROM company
              WHERE owner_id=$1 AND (company_name ILIKE $2 OR address ILIKE $2)
              ORDER BY company_id LIMIT 100',
            [$oid, '%' . $t . '%']
        );
    }
}

// =============================================================================
// PROJECT ENTITY (US #20 – #31, #37, #38)
// =============================================================================
class Project
{
    public static function saveProject(array $f): array
    {
        return q1(
            'INSERT INTO project (company_id, title, description, status, image_url)
             VALUES ($1,$2,$3,$4,$5) RETURNING *',
            [$f['company_id'], $f['title'], $f['description'], $f['status'], $f['image_url'] ?? null]
        );
    }

    public static function findById($id): ?array
    {
        return q1('SELECT * FROM project WHERE project_id=$1', [$id]);
    }

    public static function byCompany($cid, $s = null): array
    {
        return q(
            'SELECT * FROM project
              WHERE company_id=$1 AND ($2::text IS NULL OR status=$2)
              ORDER BY project_id DESC',
            [$cid, $s]
        );
    }

    public static function updateProject($id, array $f): ?array
    {
        return q1(
            'UPDATE project
                SET title=COALESCE($2,title),
                    description=COALESCE($3,description),
                    status=COALESCE($4,status),
                    image_url=COALESCE($5,image_url),
                    updated_at=now()
              WHERE project_id=$1
              RETURNING *',
            [$id, $f['title'] ?? null, $f['description'] ?? null, $f['status'] ?? null, $f['image_url'] ?? null]
        );
    }

    public static function deleteProject($id): void
    {
        ex('DELETE FROM project WHERE project_id=$1', [$id]);
    }

    public static function searchProject($cid, string $t, $s = null): array
    {
        return q(
            'SELECT * FROM project
              WHERE company_id=$1
                AND ($3::text IS NULL OR status=$3)
                AND (title ILIKE $2 OR description ILIKE $2)
              ORDER BY project_id DESC',
            [$cid, '%' . $t . '%', $s]
        );
    }

    /** US #30 / #31 – public search of current or previous IDPs. */
    public static function publicSearch(string $t): array
    {
        return q(
            'SELECT p.*, c.company_name
               FROM project p JOIN company c ON c.company_id=p.company_id
              WHERE p.title ILIKE $1 OR p.description ILIKE $1
              ORDER BY p.project_id DESC LIMIT 100',
            ['%' . $t . '%']
        );
    }

    public static function recordView($pid, $cid): void
    {
        ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [$pid, $cid]);
    }

    public static function interest($cid): array
    {
        return q(
            'SELECT p.project_id, p.title,
                    (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id) AS views,
                    (SELECT COUNT(*)::int FROM favourite    f WHERE f.project_id=p.project_id) AS shortlists
               FROM project p
              WHERE p.company_id=$1
              ORDER BY p.project_id DESC',
            [$cid]
        );
    }
}

// =============================================================================
// FAVOURITE ENTITY (US #32 – #34)
// =============================================================================
class Favourite
{
    public static function exists($cid, $pid): bool
    {
        return q1(
            'SELECT 1 FROM favourite WHERE customer_id=$1 AND project_id=$2',
            [$cid, $pid]
        ) !== null;
    }

    public static function saveFavourite($cid, $pid): void
    {
        ex(
            'INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING',
            [$cid, $pid]
        );
    }

    public static function viewFavourites($cid): array
    {
        return q(
            'SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, p.image_url, f.saved_at
               FROM favourite f JOIN project p ON p.project_id=f.project_id
              WHERE f.customer_id=$1
              ORDER BY f.saved_at DESC',
            [$cid]
        );
    }

    public static function searchFavourites($cid, string $t): array
    {
        return q(
            'SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, p.image_url, f.saved_at
               FROM favourite f JOIN project p ON p.project_id=f.project_id
              WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)',
            [$cid, '%' . $t . '%']
        );
    }

    public static function removeFavourite($cid, $pid): void
    {
        ex('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]);
    }
}

// =============================================================================
// CATEGORY ENTITY (US #41 – #45)
// =============================================================================
class Category
{
    public static function exists($id): bool
    {
        return q1('SELECT 1 FROM category WHERE category_id=$1', [$id]) !== null;
    }

    public static function saveCategory(array $f): array
    {
        return q1(
            'INSERT INTO category (category_name, description) VALUES ($1,$2) RETURNING *',
            [$f['category_name'], $f['description'] ?? null]
        );
    }

    public static function retrieveCategories(): array
    {
        return q('SELECT * FROM category ORDER BY category_name');
    }

    public static function viewCategory($id): ?array
    {
        return q1('SELECT * FROM category WHERE category_id=$1', [$id]);
    }

    public static function updateCategory($id, array $f): ?array
    {
        return q1(
            'UPDATE category
                SET category_name = COALESCE($2, category_name),
                    description   = COALESCE($3, description)
              WHERE category_id=$1
              RETURNING *',
            [$id, $f['category_name'] ?? null, $f['description'] ?? null]
        );
    }

    public static function deleteCategory($id): void
    {
        ex('DELETE FROM category WHERE category_id=$1', [$id]);
    }

    public static function findCategory(string $t): array
    {
        return q(
            'SELECT * FROM category WHERE category_name ILIKE $1 ORDER BY category_name',
            ['%' . $t . '%']
        );
    }
}

// =============================================================================
// REPORT ENTITY (US #46 – #48)
// =============================================================================
class Report
{
    public static function generate(string $p): array
    {
        $iv = $p === 'daily' ? '1 day' : ($p === 'weekly' ? '7 days' : '30 days');

        $summary = q1(
            'SELECT
                (SELECT COUNT(*)::int FROM user_account WHERE created_at >= now() - ($1)::interval) AS new_users,
                (SELECT COUNT(*)::int FROM project_view WHERE viewed_at >= now() - ($1)::interval) AS project_views,
                (SELECT COUNT(*)::int FROM favourite    WHERE saved_at  >= now() - ($1)::interval) AS shortlists,
                (SELECT COUNT(*)::int FROM project      WHERE created_at >= now() - ($1)::interval) AS new_projects',
            [$iv]
        );

        $topProjects = q(
            'SELECT
                p.title,
                c.company_name,
                (SELECT COUNT(*)::int FROM project_view v
                   WHERE v.project_id=p.project_id AND v.viewed_at >= now() - ($1)::interval) AS views,
                (SELECT COUNT(*)::int FROM favourite f
                   WHERE f.project_id=p.project_id AND f.saved_at  >= now() - ($1)::interval) AS shortlists
               FROM project p
               JOIN company c ON p.company_id = c.company_id
              ORDER BY views DESC
              LIMIT 10',
            [$iv]
        );

        return ['period' => $p, 'summary' => $summary, 'topProjects' => $topProjects];
    }
}