<?php
declare(strict_types=1);

/**
 * =============================================================================
 * ENTITY LAYER — src/bce/entities.php
 * =============================================================================
 * Role in BCE: The "E" layer. The ONLY place where SQL lives.
 *
 * =============================================================================
 * ORIGINAL-TEAMMATE CLASS NAME STATUS
 * =============================================================================
 * Unlike the controller layer, every entity class name was kept as originally
 * designed — no renames occurred during the Sprint 3 refactor. The mapping
 * to the report's BCE diagrams is therefore one-to-one, with ONE exception
 * documented below.
 *
 * Per-class convention:
 *     /** Entity: <ClassName>  |  Original teammate: <ClassName> */
 *
 * The exception: The report is internally inconsistent about the company
 * entity — page 68 labels it <<Entity>> Company, while page 72 labels it
 * <<Entity>> CompanyInfo. The code consolidated on CompanyInfo. See the
 * class-level marker for details.
 * =============================================================================
 */

/* =============================================================================
 * [ENTITY: UserAcc]  Backs US #1–#7 — user account storage
 * ============================================================================= */

/** Entity: UserAcc  |  Original teammate: UserAcc  (no rename; matches report p.14) */
class UserAcc
{
    /** US #5 — Read a single account joined with its profile. */
    public static function findById($id): ?array
    {
        return q1(
            'SELECT a.account_id, a.username, a.email, a.status, a.profile_id, p.profile_name, p.permissions
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.account_id = $1',
            [$id]
        );
    }

    /**
     * Duplicate-check for US #3 / #4. Also used by LoginController to
     * differentiate 401 from 403 (profile_status tells "account suspended"
     * apart from "profile suspended").
     */
    public static function findByEmail(string $e): ?array
    {
        return q1(
            'SELECT a.*, p.profile_name, p.permissions, p.status AS profile_status
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              WHERE a.email = $1',
            [$e]
        );
    }

    /**
     * US #1 / #6 / #11 — Centralised credential check.
     * Returns null on ANY failure (missing account, Suspended account,
     * Suspended profile, wrong password). The caller inspects find_by_email
     * afterwards to pick the right HTTP status — the entity itself remains
     * free of HTTP concerns.
     */
    public static function authenticate(string $email, string $pw): ?array
    {
        $u = self::findByEmail($email);
        if (!$u) return null;
        if ($u['status'] === 'Suspended') return null;
        if (isset($u['profile_status']) && $u['profile_status'] === 'Suspended') return null;
        if (!verify_password($pw, $u['password_hash'])) return null;
        return $u;
    }

    /** US #3 — Insert a new account (password already hashed by the controller). */
    public static function saveAccount(array $f): array
    {
        return q1(
            'INSERT INTO user_account (profile_id, username, email, password_hash)
             VALUES ($1,$2,$3,$4) RETURNING account_id, username, email, status, profile_id',
            [$f['profile_id'], $f['username'], $f['email'], $f['password_hash']]
        );
    }

    /**
     * US #4 — Partial update via COALESCE. password_hash is only written when
     * the caller provided one (UpdateUserAccController unsets the key when the
     * password field is blank).
     */
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

    /** US #6 — Idempotency check for suspend. Returns null if not found. */
    public static function checkActiveStatus($id): ?string
    {
        $row = q1('SELECT status FROM user_account WHERE account_id = $1', [$id]);
        return $row['status'] ?? null;
    }

    /** US #6 — Toggle account status. */
    public static function updateStatus($id, string $s): ?array
    {
        return q1(
            'UPDATE user_account SET status=$2, updated_at=now()
             WHERE account_id=$1
             RETURNING account_id, username, email, status',
            [$id, $s]
        );
    }

    /** US #7 — Search by username OR email (case-insensitive ILIKE, contains). */
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

    /** US #5 (list view) — Full account list, capped at 500 in entity. */
    public static function all(): array
    {
        return q(
            'SELECT a.account_id, a.username, a.email, a.status, p.profile_name
               FROM user_account a JOIN user_profile p ON p.profile_id = a.profile_id
              ORDER BY a.account_id LIMIT 500'
        );
    }
}

/* =============================================================================
 * [ENTITY: UserProfile]  Backs US #8–#12 — role / permission storage
 * ============================================================================= */

/** Entity: UserProfile  |  Original teammate: UserProfile  (no rename; matches report p.50) */
class UserProfile
{
    /**
     * US #8 — Insert a new profile with its permission set.
     *
     * '{' . implode(',', [...]) . '}' builds a Postgres array literal, e.g.
     * ['user:manage','profile:manage'] → '{user:manage,profile:manage}'.
     */
    public static function createProfile(array $f): array
    {
        $perms = '{' . implode(',', $f['permissions'] ?? []) . '}';
        return q1(
            'INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING *',
            [$f['profile_name'], $perms]
        );
    }

    /** US #12 — All profiles for the admin directory. */
    public static function all(): array
    {
        return q('SELECT * FROM user_profile ORDER BY profile_id');
    }

    /**
     * US #9 — Partial update. `$perms` is null when 'permissions' isn't in
     * the request, so the admin can rename a profile without clearing its
     * permission set.
     */
    public static function saveProfileSettings($id, array $f): ?array
    {
        $perms = isset($f['permissions']) ? '{' . implode(',', $f['permissions']) . '}' : null;
        return q1(
            'UPDATE user_profile
                SET profile_name = COALESCE($2, profile_name),
                    permissions  = COALESCE($3, permissions)
              WHERE profile_id = $1 RETURNING *',
            [$id, $f['profile_name'] ?? null, $perms]
        );
    }

    /** US #10 — Single profile detail. */
    public static function getProfileDetails($id): ?array
    {
        return q1('SELECT * FROM user_profile WHERE profile_id = $1', [$id]);
    }

    /** US #11 — Toggle profile status (suspended profile blocks all its accounts). */
    public static function setStatus($id, string $s): ?array
    {
        return q1('UPDATE user_profile SET status=$2 WHERE profile_id=$1 RETURNING *', [$id, $s]);
    }

    /** US #12 — Case-insensitive name search. */
    public static function searchProfiles(string $t): array
    {
        return q('SELECT * FROM user_profile WHERE profile_name ILIKE $1 ORDER BY profile_id', ['%' . $t . '%']);
    }
}

/* =============================================================================
 * [ENTITY: CompanyInfo]  Backs US #15–#19 — company records
 * =============================================================================
 * NOTE ON REPORT DISCREPANCY:
 *   The report's BCE diagrams are internally inconsistent about this entity's
 *   name:
 *     - Report p.68 draws <<Entity>> Company
 *     - Report p.72 draws <<Entity>> CompanyInfo
 *   The codebase consolidated on CompanyInfo (already used on p.72 and in
 *   the sequence-diagram labels for US #16–#19). If you need to reconcile
 *   the report, update p.68 to match p.72 (or vice versa).
 * ============================================================================= */

/** Entity: CompanyInfo  |  Original teammate: CompanyInfo  (see report-discrepancy note above) */
class CompanyInfo
{
    /** US #15 — Insert a company owned by the given account. */
    public static function saveCompany(array $f): array
    {
        return q1(
            'INSERT INTO company (owner_id, company_name, address, contact_no)
             VALUES ($1,$2,$3,$4) RETURNING *',
            [
                $f['owner_id'],
                $f['company_name'],
                $f['address'] ?? null,
                $f['contact_no'] ?? null,
            ]
        );
    }

    /** US #16 (list) — All companies owned by this account. */
    public static function byOwner($id): array
    {
        return q('SELECT * FROM company WHERE owner_id=$1 ORDER BY company_id DESC', [$id]);
    }

    /** US #16 — Read one company by id (owner check in controller). */
    public static function findById($id): ?array
    {
        return q1('SELECT * FROM company WHERE company_id=$1', [$id]);
    }

    /** US #17 — Full update (empty address is meaningful; no COALESCE here). */
    public static function updateCompanyInfo($id, array $f): ?array
    {
        return q1(
            'UPDATE company
                SET company_name = $2,
                    address      = $3,
                    contact_no   = $4,
                    updated_at   = now()
              WHERE company_id = $1 RETURNING *',
            [
                $id,
                $f['company_name'],
                $f['address'] ?? null,
                $f['contact_no'] ?? null,
            ]
        );
    }

    /**
     * US #18 — Delete.
     * If the company still has projects, Postgres raises FK 23503 (because
     * project.company_id is ON DELETE RESTRICT). handle_error() translates
     * that to a friendly 400. This is intentional data protection.
     */
    public static function removeCompany($id): void
    {
        ex('DELETE FROM company WHERE company_id=$1', [$id]);
    }

    /** US #19 — Search the current owner's companies by name or address. */
    public static function findCompanies($oid, string $t): array
    {
        return q(
            'SELECT * FROM company
              WHERE owner_id=$1
                AND (company_name ILIKE $2 OR address ILIKE $2)
              ORDER BY company_id LIMIT 100',
            [$oid, '%' . $t . '%']
        );
    }
}

/* =============================================================================
 * [ENTITY: Project]  Backs US #20–#31, #37, #38
 * ============================================================================= */

/** Entity: Project  |  Original teammate: Project  (no rename; matches report p.84, p.126) */
class Project
{
    /** US #20 / #25 — Insert a project; `status` chosen by caller. */
    public static function saveProject(array $f): array
    {
        return q1(
            'INSERT INTO project (company_id, title, description, status)
             VALUES ($1,$2,$3,$4) RETURNING *',
            [
                $f['company_id'],
                $f['title'],
                $f['description'],
                $f['status'],
            ]
        );
    }

    /** US #21 / #26 / #31 — Read one project by id. */
    public static function findById($id): ?array
    {
        return q1('SELECT * FROM project WHERE project_id=$1', [$id]);
    }

    /**
     * US #21 — All projects for a company, optional status filter.
     * The `$2::text IS NULL OR status=$2` idiom serves both "all projects"
     * (pass null) and "only one status" (pass 'Ongoing'/'Completed') with a
     * single query. The ::text cast prevents Postgres type-inference errors.
     */
    public static function byCompany($cid, $s = null): array
    {
        return q(
            'SELECT * FROM project
              WHERE company_id=$1 AND ($2::text IS NULL OR status=$2)
              ORDER BY project_id DESC',
            [$cid, $s]
        );
    }

    /** US #22 / #27 — Partial update via COALESCE. */
    public static function updateProject($id, array $f): ?array
    {
        return q1(
            'UPDATE project
                SET company_id  = COALESCE($2, company_id),
                    title       = COALESCE($3, title),
                    description = COALESCE($4, description),
                    status      = COALESCE($5, status),
                    updated_at  = now()
              WHERE project_id = $1 RETURNING *',
            [
                $id,
                $f['company_id'] ?? null,
                $f['title'] ?? null,
                $f['description'] ?? null,
                $f['status'] ?? null,
            ]
        );
    }

    /** US #23 / #28 — Delete (cascades project_view + favourite). */
    public static function deleteProject($id): void
    {
        ex('DELETE FROM project WHERE project_id=$1', [$id]);
    }

    /**
     * US #24 / #29 — Search within a company (title OR description ILIKE,
     * optional status filter).
     */
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

    /** US #30 — Public search across ALL projects; includes company_name. */
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

    /**
     * US #31 — Record a view event (audit row in project_view).
     * The row's customer_id is used for analytics counting only; it is NEVER
     * returned to any API consumer (Ethical Considerations §Privacy).
     */
    public static function recordView($pid, $cid): void
    {
        ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1,$2)', [$pid, $cid]);
    }

    /**
     * US #37 / #38 (per-company) — Aggregated views + shortlists.
     *
     * Correlated COUNT subqueries (rather than JOIN + GROUP BY) produce exactly
     * one row per project, avoiding duplicate rows when a project has many
     * views AND many favourites. The ::int cast normalises the bigint returned
     * by COUNT() so JSON serialisation is consistent.
     */
    public static function interest($cid): array
    {
        return q(
            'SELECT p.project_id, p.title,
                    (SELECT COUNT(*)::int FROM project_view v WHERE v.project_id=p.project_id) AS views,
                    (SELECT COUNT(*)::int FROM favourite f WHERE f.project_id=p.project_id) AS shortlists
               FROM project p
              WHERE p.company_id=$1 ORDER BY p.project_id DESC',
            [$cid]
        );
    }

    /**
     * Cross-company search scoped to the current designer. Dynamic WHERE
     * clause is built with $n placeholders; user input is always bound — safe
     * from SQL injection.
     */
    public static function searchAllByOwner($ownerId, string $q, $status = null): array
    {
        $sql = 'SELECT p.*, c.company_name
                  FROM project p JOIN company c ON c.company_id = p.company_id
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

    /**
     * US #37 / #38 (cross-company) — Aggregated metrics across every project
     * the designer owns. This is the endpoint the report's TDD example (p.189)
     * references via GET /api/projects/search/interest.
     */
    public static function interestAllByOwner($ownerId): array
    {
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

/* =============================================================================
 * [ENTITY: Favourite]  Backs US #32–#34
 * ============================================================================= */

/** Entity: Favourite  |  Original teammate: Favourite  (no rename; matches report p.153) */
class Favourite
{
    /** Existence check for idempotent-save confirmation. */
    public static function exists($cid, $pid): bool
    {
        return q1('SELECT 1 FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]) !== null;
    }

    /**
     * US #32 — Save a favourite. ON CONFLICT DO NOTHING makes this idempotent
     * so double-clicking "Save" is a no-op rather than a 409 error. The
     * UNIQUE(customer_id, project_id) constraint on the table is what makes
     * the ON CONFLICT clause valid.
     */
    public static function saveFavourite($cid, $pid): void
    {
        ex('INSERT INTO favourite (customer_id, project_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [$cid, $pid]);
    }

    /** US #34 — List a customer's saved projects, newest first. */
    public static function viewFavourites($cid): array
    {
        return q(
            'SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at
               FROM favourite f JOIN project p ON p.project_id=f.project_id
              WHERE f.customer_id=$1 ORDER BY f.saved_at DESC',
            [$cid]
        );
    }

    /** US #33 — Search within the customer's own favourites. */
    public static function searchFavourites($cid, string $t): array
    {
        return q(
            'SELECT f.favourite_id, p.project_id, p.title, p.description, p.status, f.saved_at
               FROM favourite f JOIN project p ON p.project_id=f.project_id
              WHERE f.customer_id=$1 AND (p.title ILIKE $2 OR p.description ILIKE $2)',
            [$cid, '%' . $t . '%']
        );
    }

    /** US #34 — Remove a favourite (idempotent — no-op if already absent). */
    public static function removeFavourite($cid, $pid): void
    {
        ex('DELETE FROM favourite WHERE customer_id=$1 AND project_id=$2', [$cid, $pid]);
    }
}

/* =============================================================================
 * [ENTITY: Category]  Backs US #41–#45
 * ============================================================================= */

/** Entity: Category  |  Original teammate: Category  (no rename; matches report p.104) */
class Category
{
    /** Existence check for delete controller. */
    public static function exists($id): bool
    {
        return q1('SELECT 1 FROM category WHERE category_id=$1', [$id]) !== null;
    }

    /** US #41 — Insert a new category. */
    public static function saveCategory(array $f): array
    {
        return q1(
            'INSERT INTO category (category_name, description) VALUES ($1,$2) RETURNING *',
            [$f['category_name'], $f['description'] ?? null]
        );
    }

    /** US #42 (list) — All categories, alphabetised. */
    public static function retrieveCategories(): array
    {
        return q('SELECT * FROM category ORDER BY category_name');
    }

    /** US #42 (single) — Read one by id. */
    public static function viewCategory($id): ?array
    {
        return q1('SELECT * FROM category WHERE category_id=$1', [$id]);
    }

    /** US #43 — Partial update via COALESCE. */
    public static function updateCategory($id, array $f): ?array
    {
        return q1(
            'UPDATE category
                SET category_name = COALESCE($2, category_name),
                    description   = COALESCE($3, description)
              WHERE category_id=$1 RETURNING *',
            [$id, $f['category_name'] ?? null, $f['description'] ?? null]
        );
    }

    /** US #44 — Delete (cascade removes project_category links). */
    public static function deleteCategory($id): void
    {
        ex('DELETE FROM category WHERE category_id=$1', [$id]);
    }

    /** US #45 — Case-insensitive name search. */
    public static function findCategory(string $t): array
    {
        return q('SELECT * FROM category WHERE category_name ILIKE $1 ORDER BY category_name', ['%' . $t . '%']);
    }
}

/* =============================================================================
 * [ENTITY: Report]  Backs US #46–#48
 * ============================================================================= */

/** Entity: Report  |  Original teammate: Report  (no rename; matches report p.173) */
class Report
{
    /**
     * US #46 / #47 / #48 — Generate summary + top 10 projects.
     *
     * The $interval string is injected into raw SQL, but this is safe because:
     *   - It's chosen from a match() over hardcoded constants.
     *   - No user input flows into $interval.
     *   - The match() has a default case, so an unexpected period still
     *     defaults to '1 day'.
     *
     * COUNT(DISTINCT ...) on topProjects is essential: without DISTINCT a
     * project with 3 views and 2 saves produces 3*2=6 rows via the LEFT JOIN
     * cross-product, inflating both counts.
     */
    public static function generate(string $period): array
    {
        // --- 1. Translate period to a Postgres INTERVAL literal ---
        $interval = match($period) {
            'daily'   => '1 day',
            'weekly'  => '7 days',
            'monthly' => '30 days',
            default   => '1 day',
        };

        // --- 2. Summary counts ---
        $newUsers    = q1("SELECT COUNT(*) as count FROM user_account WHERE created_at >= NOW() - INTERVAL '$interval'");
        $newProjects = q1("SELECT COUNT(*) as count FROM project      WHERE created_at >= NOW() - INTERVAL '$interval'");
        $views       = q1("SELECT COUNT(*) as count FROM project_view WHERE viewed_at  >= NOW() - INTERVAL '$interval'");
        $saves       = q1("SELECT COUNT(*) as count FROM favourite    WHERE saved_at   >= NOW() - INTERVAL '$interval'");

        // --- 3. Top 10 projects by views then saves ---
        $topProjects = q("
            SELECT p.title, c.company_name,
                   COUNT(DISTINCT v.view_id)      as views,
                   COUNT(DISTINCT f.favourite_id) as shortlists
            FROM project p
            JOIN company c ON p.company_id = c.company_id
            LEFT JOIN project_view v ON p.project_id = v.project_id
            LEFT JOIN favourite  f ON p.project_id = f.project_id
            GROUP BY p.project_id, p.title, c.company_name
            ORDER BY views DESC, shortlists DESC
            LIMIT 10
        ");

        // --- 4. Cast to int so JSON output is numeric, not string ---
        $topProjects = array_map(function($row) {
            $row['views']      = (int)$row['views'];
            $row['shortlists'] = (int)$row['shortlists'];
            return $row;
        }, $topProjects);

        // --- 5. Assemble the response shape the frontend expects ---
        return [
            'summary' => [
                'new_users'     => (int)($newUsers['count']    ?? 0),
                'project_views' => (int)($views['count']       ?? 0),
                'shortlists'    => (int)($saves['count']       ?? 0),
                'new_projects'  => (int)($newProjects['count'] ?? 0),
            ],
            'topProjects' => $topProjects,
        ];
    }
}