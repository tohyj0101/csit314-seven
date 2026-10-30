<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

try {
    echo "--- STARTING CLEAN SEED ---\n";
    
    // 1. Wipe clean
    ex('TRUNCATE TABLE project_view, favourite, project_category, category, project, company, user_account, user_profile RESTART IDENTITY CASCADE');
    echo "✅ Database wiped clean\n";

    // 2. Profiles
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
    ];
    $uids = [];
    foreach ($users as $u) {
        $row = q1(
            'INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1, $2, $3, $4) RETURNING account_id',
            [$pids[$u[0]], preg_replace('/\s+/', '', strtolower($u[0])), $u[1], $hash]
        );
        $uids[$u[0]] = (int)$row['account_id'];
    }
    echo "✅ 4 Accounts created\n";

    // 4. Company
    $row = q1(
        'INSERT INTO company (owner_id, company_name, address, contact_no) VALUES ($1, $2, $3, $4) RETURNING company_id',
        [$uids['Interior Designer'], 'My Design Studio', '123 Orchard Road, Singapore', '+65 9123 4567']
    );
    $companyId = (int)$row['company_id'];

    // 5. Categories
    $catIds = [];
    foreach (['Residential', 'Commercial', 'Kitchen'] as $cat) {
        $row = q1('INSERT INTO category (category_name) VALUES ($1) RETURNING category_id', [$cat]);
        $catIds[] = (int)$row['category_id'];
    }

    // 6. Projects
    $projects = [
        ['Modern BTO Renovation',   'A sleek 4-room BTO flat.',            'Ongoing',   '/img/sample.jpg'],
        ['Luxury Condo Makeover',   'High-end interior design.',            'Completed', '/img/sample.jpg'],
        ['Minimalist Office Space', 'Clean lines and ergonomic design.',    'Ongoing',   '/img/sample.jpg'],
    ];
    foreach ($projects as $p) {
        $row = q1(
            'INSERT INTO project (company_id, title, description, status, image_url) VALUES ($1, $2, $3, $4, $5) RETURNING project_id',
            [$companyId, $p[0], $p[1], $p[2], $p[3]]
        );
        $pid = (int)$row['project_id'];
        ex('INSERT INTO project_category (project_id, category_id) VALUES ($1, $2)', [$pid, $catIds[array_rand($catIds)]]);
    }
    echo "✅ Starter company and projects created\n";

    echo "--- SEED COMPLETE & COMMITTED ---\n";
} catch (Throwable $e) {
    fwrite(STDERR, "❌ SEED FAILED: {$e->getMessage()}\n");
    exit(1);
}