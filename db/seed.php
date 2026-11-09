<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * =============================================================================
 * CLEAN SEED SCRIPT (FULL UI/UX COVERAGE)
 * =============================================================================
 * This script wipes the DB and creates a perfect baseline dataset to test
 * every single feature, empty state, and error message in the application.
 * =============================================================================
 */

try {
    echo "--- STARTING CLEAN SEED ---\n";
    
    // 1. Wipe clean
    ex('TRUNCATE TABLE project_view, favourite, project_category, category, project, company, user_account, user_profile RESTART IDENTITY CASCADE');
    echo "✅ Database wiped clean\n";

    // 2. Profiles
    // Matches exactly what app.js PERMISSION_MAP expects
    $profiles = [
        ['User Admin',       ['user:manage','profile:manage']],
        ['Interior Designer',['company:manage','project:manage','interest:view']],
        ['Customer',         ['project:view','favourite:manage']],
        ['Platform Manager', ['category:manage','report:generate']],
    ];
    $pids = [];
    foreach ($profiles as $p) {
        $permStr = '{' . implode(',', $p[1]) . '}';
        $row = q1(
            'INSERT INTO user_profile (profile_name, permissions) VALUES ($1, $2) RETURNING profile_id',
            [$p[0], $permStr]
        );
        $pids[$p[0]] = (int)$row['profile_id'];
    }

    // 3. Users
    $hash = hash_password('Password123!');
    $users = [
        ['User Admin',        'admin@example.test'],
        ['Interior Designer', 'designer@example.test'],
        ['Customer',          'customer@example.test'],
        ['Platform Manager',  'manager@example.test'],
        ['Customer',          'suspended@example.test'], // [NEW] To test 403 Error
    ];
    $uids = [];
    foreach ($users as $u) {
        $username = explode('@', $u[1])[0]; // Use email prefix as username
        $row = q1(
            'INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1, $2, $3, $4) RETURNING account_id',
            [$pids[$u[0]], $username, $u[1], $hash]
        );
        $uids[$u[1]] = (int)$row['account_id'];
    }
    // Suspend the last user to test the display_error() blocking logic
    ex('UPDATE user_account SET status = $1 WHERE email = $2', ['Suspended', 'suspended@example.test']);
    echo "✅ 5 Accounts created (1 Suspended for error testing)\n";

    // 4. Companies (Adding a second company to test the dropdown)
    $row = q1(
        'INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1, $2, $3, $4) RETURNING company_id',
        [$uids['designer@example.test'], 'My Design Studio', '123 Orchard Road, Singapore', '91234567']
    );
    $companyId1 = (int)$row['company_id'];
    
    $row = q1(
        'INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1, $2, $3, $4) RETURNING company_id',
        [$uids['designer@example.test'], 'Luxury Interiors Pte Ltd', '456 Marina Bay Sands, Singapore', '88889999']
    );
    $companyId2 = (int)$row['company_id'];

    // 5. Categories
    $catIds = [];
    $cats = [
        ['Residential', 'Homes, BTOs, and Condos'],
        ['Commercial', 'Offices and Retail Spaces'],
        ['Kitchen', 'Culinary Spaces']
    ];
    foreach ($cats as $cat) {
        $row = q1('INSERT INTO category (category_name, description) VALUES ($1, $2) RETURNING category_id', [$cat[0], $cat[1]]);
        $catIds[] = (int)$row['category_id'];
    }

    // 6. Projects (Added date field, alternated statuses)
    $projects = [
        ['Modern BTO Renovation',   'A sleek 4-room BTO flat.',            'Ongoing',   $companyId1],
        ['Luxury Condo Makeover',   'High-end interior design.',            'Completed', $companyId2],
        ['Minimalist Office Space', 'Clean lines and ergonomic design.',    'Ongoing',   $companyId1],
        ['Scandinavian Kitchen',    'Bright and functional layout.',        'Completed', $companyId2],
    ];
    
    $pids_proj = [];
    foreach ($projects as $p) {
        $date = date('Y-m-d', strtotime('-' . rand(1, 30) . ' days')); // Random date last 30 days
        $row = q1(
            'INSERT INTO project (company_id, title, description, status, date) VALUES ($1, $2, $3, $4, $5) RETURNING project_id',
            [$p[3], $p[0], $p[1], $p[2], $date]
        );
        $pid = (int)$row['project_id'];
        $pids_proj[] = $pid;
        // Link category
        ex('INSERT INTO project_category (project_id, category_id) VALUES ($1, $2)', [$pid, $catIds[array_rand($catIds)]]);
    }
    echo "✅ 2 Companies, 3 Categories, and 4 Projects created\n";

    // 7. Inject Views & Favourites (To test analytics and empty states)
    $customerId = $uids['customer@example.test'];
    
    // Give Project 1 and 2 some views/favourites
    foreach ([$pids_proj[0], $pids_proj[1]] as $pid) {
        ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1, $2)', [$pid, $customerId]);
        Favourite::addFavourite($customerId, $pid);
    }
    // Leave Project 3 & 4 with 0 views/saves to test the empty metrics UI
    echo "✅ Views & Favourites injected (Projects 3 & 4 left empty for UI testing)\n";

    echo "--- SEED COMPLETE & COMMITTED ---\n";
    echo "You can now log in with:\n";
    echo "  Designer: designer@example.test (Password123!)\n";
    echo "  Customer: customer@example.test (Password123!)\n";
    echo "  Admin:    admin@example.test (Password123!)\n";
    echo "  Manager:  manager@example.test (Password123!)\n";
    echo "  Suspended: suspended@example.test (Password123!) -> Should trigger 403 Error\n";

} catch (Throwable $e) {
    fwrite(STDERR, "❌ SEED FAILED: {$e->getMessage()}\n");
    exit(1);
}