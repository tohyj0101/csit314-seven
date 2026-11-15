<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * =============================================================================
 * SYNTHETIC DATA GENERATOR  -  exactly 100 rows of every entity
 * =============================================================================
 * Synthetic rows (all counted separately from seed.php data):
 *   100 user profiles    (+ the 4 real role profiles, kept separate)
 *   100 user accounts    (_gen usernames; 10% Suspended)
 *   100 categories
 *   100 companies        (ALL owned by one designer, so one login sees all 100)
 *   100 projects         (+ 100 project_category links; ALL in one company,
 *                         as stated in the report's Test Data section, p.260)
 *   100 project views
 *   100 favourites       (ALL saved by one customer, one per project, so one login sees all 100)
 *
 * Safe to re-run: every section "tops up" to 100 instead of adding 100 more.
 * mt_srand() makes the random choices repeatable between runs.
 * =============================================================================
 */

const TARGET = 100;
mt_srand(314);

echo "--- STARTING SYNTHETIC DATA GENERATION (target: " . TARGET . " of each) ---\n";
$hash = hash_password('Password123!');

/**
 * Spread rows across the report time-buckets (Daily / Weekly / Monthly / older).
 * Reports filter on created_at / viewed_at / saved_at, so those timestamps - not
 * just project.date - must be back-dated for the three reports to differ.
 */
function spread_days(int $i): int {
    if ($i <= 20) return 0;            // today          -> Daily, Weekly, Monthly
    if ($i <= 45) return 3;            // this week      -> Weekly, Monthly
    if ($i <= 70) return 15;           // this month     -> Monthly
    return mt_rand(40, 365);           // older          -> none
}

function count_of(string $sql, array $params = []): int {
    return (int)q1($sql, $params)['c'];
}

// =============================================================================
// 1. USER PROFILES: 4 real roles + 100 synthetic profiles
// =============================================================================
$roles = ['Customer', 'Interior Designer', 'Platform Manager', 'User Admin'];
foreach ($roles as $role) {
    if (!q1('SELECT profile_id FROM user_profile WHERE profile_name = $1', [$role])) {
        // permissions must be an array: createProfile() implodes it into '{a,b}'
        UserProfile::createProfile(['profile_name' => $role, 'permissions' => []]);
    }
}
$pids = [];
foreach (q('SELECT profile_id, profile_name FROM user_profile') as $p) {
    $pids[$p['profile_name']] = $p['profile_id'];
}

for ($i = 1; $i <= TARGET; $i++) {
    $name = "Synthetic Profile $i";
    if (!q1('SELECT profile_id FROM user_profile WHERE profile_name = $1', [$name])) {
        $prof = UserProfile::createProfile(['profile_name' => $name, 'permissions' => []]);
        // COVERAGE: 10% suspended profiles (no users are attached to these)
        if ($i % 10 === 0) {
            UserProfile::updateStatus($prof['profile_id'], 'Suspended');
        }
    }
}
echo "✅ 100 synthetic User Profiles ensured (+ 4 base role profiles).\n";

// =============================================================================
// 2. 100 USER ACCOUNTS (Active & Suspended), spread over the 4 real roles
// =============================================================================
for ($i = 1; $i <= TARGET; $i++) {
    $role     = $roles[$i % 4];
    $username = strtolower(str_replace(' ', '', $role)) . '_gen' . $i;
    $email    = $username . '@example.test';

    if (!UserAcc::checkDuplicate($email)) {
        $uid = UserAcc::createUserAccount([
            'profile_id' => $pids[$role], 'username' => $username,
            'email' => $email, 'password_hash' => $hash,
        ])['account_id'];

        // COVERAGE: 10% of users are suspended to test LoginController blocking
        if ($i % 10 === 0) {
            UserAcc::suspendUserAccount($uid, 'Suspended');
        }
        // Back-date so the Reports time-buckets differ
        ex("UPDATE user_account SET created_at = now() - ($2::int * INTERVAL '1 day') WHERE account_id = $1",
           [$uid, spread_days($i)]);
    }
}
echo "✅ 100 Users generated (includes Suspended).\n";

// Only synthetic, Active users are used as owners / viewers / savers below.
$designers = q('SELECT account_id FROM user_account WHERE profile_id = $1 AND status = \'Active\' AND username LIKE \'%\\_gen%\' ORDER BY account_id', [$pids['Interior Designer']]);

// Designers only see THEIR OWN companies/projects (ownership checks), so every
// synthetic company is owned by ONE designer: the seed demo designer if it
// exists, otherwise the first synthetic designer.
$main    = q1('SELECT account_id FROM user_account WHERE email = $1', ['designer@example.test']);
$ownerId = $main ? (int)$main['account_id'] : (int)$designers[0]['account_id'];
$customers = q('SELECT account_id FROM user_account WHERE profile_id = $1 AND status = \'Active\' AND username LIKE \'%\\_gen%\' ORDER BY account_id', [$pids['Customer']]);

// Customers only see THEIR OWN favourites, so all 100 synthetic favourites are
// saved by ONE customer: the seed demo customer if it exists, else the first
// synthetic customer. (Views stay spread across customers: only designers see
// them, as aggregated counts.)
$mainCust  = q1('SELECT account_id FROM user_account WHERE email = $1', ['customer@example.test']);
$favCustId = $mainCust ? (int)$mainCust['account_id'] : (int)$customers[0]['account_id'];

// =============================================================================
// 3. 100 CATEGORIES
// =============================================================================
for ($i = 1; $i <= TARGET; $i++) {
    $name = "Synthetic Category $i";
    if (!q1('SELECT category_id FROM category WHERE category_name = $1', [$name])) {
        Category::saveCategory(['category_name' => $name, 'description' => "Auto-generated category $i"]);
    }
}
echo "✅ 100 Categories generated.\n";
$categories = q('SELECT category_id FROM category WHERE category_name LIKE \'Synthetic Category %\' ORDER BY category_id');

// =============================================================================
// 4. 100 COMPANIES
// =============================================================================
for ($i = 1; $i <= TARGET; $i++) {
    $name = "Synthetic Company $i";
    if (!q1('SELECT company_id FROM company WHERE company_name = $1', [$name])) {
        // 8-digit Singapore-style numbers starting with 8 or 9
        $phone   = (($i % 2 === 0) ? '9' : '8') . mt_rand(1000000, 9999999);
        // COVERAGE: every 5th company has no address (address column is nullable)
        $address = ($i % 5 === 0) ? null : "Address $i";

        CompanyInfo::createCompany([
            'owner_id' => $ownerId, 'company_name' => $name,
            'address' => $address, 'contact_no' => $phone,
        ]);
    }
}
// Repair step: also fixes rows left by an older run that used random owners.
ex('UPDATE company SET owner_id = $1 WHERE company_name LIKE \'Synthetic Company %\'', [$ownerId]);
echo "✅ 100 Companies generated (all owned by account #$ownerId).\n";
$companies     = q('SELECT company_id FROM company WHERE company_name LIKE \'Synthetic Company %\' ORDER BY company_id');
$mainCompanyId = (int)$companies[0]['company_id'];   // "Synthetic Company 1" holds all 100 projects

// =============================================================================
// 5. 100 PROJECTS (+ 100 project_category links)
// =============================================================================
for ($i = 1; $i <= TARGET; $i++) {
    $title = "Synthetic Project $i";
    if (!q1('SELECT project_id FROM project WHERE title = $1', [$title])) {
        $comp   = $mainCompanyId;
        $cat    = $categories[array_rand($categories)]['category_id'];
        $status = ($i % 2 === 0) ? 'Completed' : 'Ongoing';

        if ($i <= 10)      $date = date('Y-m-d');                                   // today
        elseif ($i <= 20)  $date = date('Y-m-d', strtotime('-3 days'));             // this week
        elseif ($i <= 30)  $date = date('Y-m-d', strtotime('-15 days'));            // this month
        else               $date = date('Y-m-d', strtotime('-' . mt_rand(40, 365) . ' days')); // old

        $row = q1(
            'INSERT INTO project (company_id, title, description, status, date, created_at)
             VALUES ($1, $2, $3, $4, $5, $5::timestamptz) RETURNING project_id',
            [$comp, $title, "Auto-generated project $i", $status, $date]
        );
        ex('INSERT INTO project_category (project_id, category_id) VALUES ($1, $2)', [(int)$row['project_id'], $cat]);
    }
}
// Repair step: move any projects from an older run into the single company.
ex('UPDATE project SET company_id = $1 WHERE title LIKE \'Synthetic Project %\'', [$mainCompanyId]);
echo "✅ 100 Projects generated (all in company #$mainCompanyId, dates spread for Reports).\n";

// "Popular" = synthetic projects 1-50 by id get the views. Projects 51-100 stay at 0 views.
$syntheticProjects = q('SELECT project_id FROM project WHERE title LIKE \'Synthetic Project %\' ORDER BY project_id');
$popularProjects   = array_slice($syntheticProjects, 0, 50);

// =============================================================================
// 6a. 100 VIEWS (top up to exactly 100 synthetic views)
// =============================================================================
$haveViews = count_of(
    'SELECT COUNT(*) c FROM project_view v
       JOIN project p ON p.project_id = v.project_id
       JOIN user_account u ON u.account_id = v.customer_id
      WHERE p.title LIKE \'Synthetic Project %\' AND u.username LIKE \'%\\_gen%\''
);
for ($n = $haveViews + 1; $n <= TARGET; $n++) {
    $proj = $popularProjects[array_rand($popularProjects)]['project_id'];
    $cust = $customers[array_rand($customers)]['account_id'];
    ex("INSERT INTO project_view (project_id, customer_id, viewed_at)
        VALUES ($1, $2, now() - ($3::int * INTERVAL '1 day'))", [$proj, $cust, spread_days($n)]);
}
echo "✅ 100 Views generated.\n";

// =============================================================================
// 6b. 100 FAVOURITES: one customer saves each of the 100 synthetic projects
// =============================================================================
// Repair step: drop favourites left by an older run that spread them randomly
// over many synthetic customers.
ex('DELETE FROM favourite
     WHERE project_id IN (SELECT project_id FROM project WHERE title LIKE \'Synthetic Project %\')
       AND customer_id IN (SELECT account_id FROM user_account WHERE username LIKE \'%\\_gen%\')
       AND customer_id <> $1', [$favCustId]);

foreach ($syntheticProjects as $idx => $sp) {
    // UNIQUE(customer_id, project_id) makes this idempotent on re-runs
    ex("INSERT INTO favourite (customer_id, project_id, saved_at)
        VALUES ($1, $2, now() - ($3::int * INTERVAL '1 day'))
        ON CONFLICT DO NOTHING", [$favCustId, $sp['project_id'], spread_days($idx + 1)]);
}
echo "✅ 100 Favourites generated (all saved by account #$favCustId).\n";

// =============================================================================
// 7. VERIFICATION: exact counts + state coverage + integrity
// =============================================================================
echo "--- RUNNING COUNT, COVERAGE & INTEGRITY CHECKS ---\n";
$testsPassed = true;

function assertTest(string $description, bool $condition): void {
    global $testsPassed;
    echo ($condition ? '✅ [PASS] ' : '❌ [FAIL] ') . $description . "\n";
    if (!$condition) $testsPassed = false;
}

// Exactly 100 of every synthetic entity
assertTest('100 synthetic User Profiles',  count_of("SELECT COUNT(*) c FROM user_profile WHERE profile_name LIKE 'Synthetic Profile %'") === TARGET);
assertTest('100 synthetic Users',          count_of("SELECT COUNT(*) c FROM user_account WHERE username LIKE '%\\_gen%'") === TARGET);
assertTest('100 synthetic Categories',     count_of("SELECT COUNT(*) c FROM category WHERE category_name LIKE 'Synthetic Category %'") === TARGET);
assertTest('100 synthetic Companies',      count_of("SELECT COUNT(*) c FROM company WHERE company_name LIKE 'Synthetic Company %'") === TARGET);
assertTest('100 synthetic Projects',       count_of("SELECT COUNT(*) c FROM project WHERE title LIKE 'Synthetic Project %'") === TARGET);
assertTest('100 project-category links',   count_of("SELECT COUNT(*) c FROM project_category pc JOIN project p USING (project_id) WHERE p.title LIKE 'Synthetic Project %'") === TARGET);
assertTest('100 synthetic Views',          count_of("SELECT COUNT(*) c FROM project_view v JOIN project p USING (project_id) JOIN user_account u ON u.account_id = v.customer_id WHERE p.title LIKE 'Synthetic Project %' AND u.username LIKE '%\\_gen%'") === TARGET);
assertTest('100 synthetic Favourites',     count_of("SELECT COUNT(*) c FROM favourite f JOIN project p USING (project_id) WHERE p.title LIKE 'Synthetic Project %'") === TARGET);

// One owner sees all 100 companies; one company holds all 100 projects
assertTest('All 100 synthetic Companies share one owner', count_of("SELECT COUNT(DISTINCT owner_id) c FROM company WHERE company_name LIKE 'Synthetic Company %'") === 1);
assertTest('All 100 synthetic Projects belong to one company', count_of("SELECT COUNT(DISTINCT company_id) c FROM project WHERE title LIKE 'Synthetic Project %'") === 1);

assertTest('All 100 Favourites belong to one customer', count_of("SELECT COUNT(DISTINCT f.customer_id) c FROM favourite f JOIN project p USING (project_id) WHERE p.title LIKE 'Synthetic Project %'") === 1);

// State coverage
assertTest('Suspended users exist (login blocking)',    count_of("SELECT COUNT(*) c FROM user_account WHERE username LIKE '%\\_gen%' AND status = 'Suspended'") > 0);
assertTest('Suspended profiles exist',                  count_of("SELECT COUNT(*) c FROM user_profile WHERE profile_name LIKE 'Synthetic Profile %' AND status = 'Suspended'") > 0);
assertTest('Both Ongoing and Completed projects exist', count_of("SELECT COUNT(*) c FROM project WHERE title LIKE 'Synthetic Project %' AND status = 'Ongoing'") > 0
                                                     && count_of("SELECT COUNT(*) c FROM project WHERE title LIKE 'Synthetic Project %' AND status = 'Completed'") > 0);
assertTest('Projects dated TODAY exist (Daily Report)', count_of("SELECT COUNT(*) c FROM project WHERE title LIKE 'Synthetic Project %' AND date = CURRENT_DATE") > 0);
assertTest('At least 50 synthetic projects have 0 views (empty states)',
    count_of("SELECT COUNT(*) c FROM project p LEFT JOIN project_view v ON v.project_id = p.project_id WHERE p.title LIKE 'Synthetic Project %' AND v.view_id IS NULL") >= 50);

// Integrity
assertTest('No orphaned projects', count_of('SELECT COUNT(*) c FROM project p LEFT JOIN company c ON c.company_id = p.company_id WHERE c.company_id IS NULL') === 0);

echo "-----------------------------------\n";
if ($testsPassed) {
    echo "🎉 ALL CHECKS PASSED: 100 of every entity generated.\n";
} else {
    echo "⚠️ CHECKS FAILED. SEE OUTPUT ABOVE.\n";
    exit(1);
}