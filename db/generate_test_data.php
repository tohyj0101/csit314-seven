<?php
require __DIR__ . '/../src/bootstrap.php';

$hash = hash_password('Password123!');
$profiles = q('SELECT profile_id, profile_name FROM user_profile');
$pids = [];
foreach ($profiles as $p) $pids[$p['profile_name']] = $p['profile_id'];

// Generate 30 Customers, 30 Designers, 30 Managers, 30 Admins (Total 120 Users)
$roles = ['Customer', 'Interior Designer', 'Platform Manager', 'User Admin'];

for ($i = 1; $i <= 120; $i++) {
    $role = $roles[$i % 4];
    $username = strtolower(str_replace(' ', '', $role)) . $i;
    $email = $username . '@example.test';
    
    // Check if user exists first to avoid duplicate errors
    if (!UserAcc::findByEmail($email)) {
        UserAcc::saveAccount([
            'profile_id' => $pids[$role],
            'username' => $username,
            'email' => $email,
            'password_hash' => $hash
        ]);
    }
}
echo "✅ 120 Test Users generated successfully!\n";