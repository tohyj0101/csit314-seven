<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

echo "--- STARTING DATA GENERATION (100 EACH) ---\n";

// 1. Generate 100 Users
$hash = hash_password('Password123!');
$profiles = q('SELECT profile_id, profile_name FROM user_profile');
$pids = [];
foreach ($profiles as $p) $pids[$p['profile_name']] = $p['profile_id'];

$roles = ['Customer', 'Interior Designer', 'Platform Manager', 'User Admin'];
for ($i = 1; $i <= 100; $i++) {
    $role = $roles[$i % 4];
    $username = strtolower(str_replace(' ', '', $role)) . "_gen" . $i;
    $email = $username . '@example.test';
    if (!UserAcc::findByEmail($email)) {
        UserAcc::saveAccount([
            'profile_id' => $pids[$role], 'username' => $username, 'email' => $email, 'password_hash' => $hash
        ]);
    }
}
echo "✅ 100 Users generated.\n";

// Fetch IDs to link data together
$designers = q("SELECT account_id FROM user_account WHERE profile_id = " . $pids['Interior Designer']);
$customers = q("SELECT account_id FROM user_account WHERE profile_id = " . $pids['Customer']);

// 2. Generate 100 Categories
for ($i = 1; $i <= 100; $i++) {
    $name = "Synthetic Category " . $i;
    if (!q1("SELECT category_id FROM category WHERE category_name = $1", [$name])) {
        Category::saveCategory(['category_name' => $name, 'description' => "Auto-generated category $i"]);
    }
}
echo "✅ 100 Categories generated.\n";

// 3. Generate 100 Companies (8-digit phone numbers)
for ($i = 1; $i <= 100; $i++) {
    $name = "Synthetic Company " . $i;
    if (!q1("SELECT company_id FROM company WHERE company_name = $1", [$name])) {
        $owner = $designers[array_rand($designers)]['account_id'];
        // Generate a random 8-digit phone number starting with 8 or 9
        $randomPhone = '8' . str_pad((string)rand(0, 9999999), 7, '0', STR_PAD_LEFT);
        
        CompanyInfo::saveCompany([
            'owner_id' => $owner, 
            'company_name' => $name, 
            'address' => "Address $i", 
            'contact_no' => $randomPhone
        ]);
    }
}
echo "✅ 100 Companies generated.\n";

$companies = q("SELECT company_id FROM company");
$categories = q("SELECT category_id FROM category");

// 4. Generate 100 Projects (Updated for Date, removed image_url)
for ($i = 1; $i <= 100; $i++) {
    $title = "Synthetic Project " . $i;
    if (!q1("SELECT project_id FROM project WHERE title = $1", [$title])) {
        $comp = $companies[array_rand($companies)]['company_id'];
        $cat = $categories[array_rand($categories)]['category_id'];
        $status = ($i % 2 == 0) ? 'Completed' : 'Ongoing';
        
        // Generate a random date within the last 365 days
        $randomDate = date('Y-m-d', strtotime('-' . rand(1, 365) . ' days'));
        
        $proj = Project::saveProject([
            'company_id' => $comp, 
            'title' => $title, 
            'date' => $randomDate,
            'description' => "Auto-generated project $i", 
            'status' => $status
        ]);
        
        // Link to category
        ex('INSERT INTO project_category (project_id, category_id) VALUES ($1, $2)', [$proj['project_id'], $cat]);
    }
}
echo "✅ 100 Projects generated.\n";

$projects = q("SELECT project_id FROM project");

// 5. Generate 100 Views
for ($i = 1; $i <= 100; $i++) {
    $proj = $projects[array_rand($projects)]['project_id'];
    $cust = $customers[array_rand($customers)]['account_id'];
    ex('INSERT INTO project_view (project_id, customer_id) VALUES ($1, $2)', [$proj, $cust]);
}
echo "✅ 100 Views generated.\n";

// 6. Generate 100 Favourites
for ($i = 1; $i <= 100; $i++) {
    $proj = $projects[array_rand($projects)]['project_id'];
    $cust = $customers[array_rand($customers)]['account_id'];
    Favourite::saveFavourite($cust, $proj);
}
echo "✅ 100 Favourites generated.\n";

echo "--- DATA GENERATION COMPLETE ---\n";