<?php
declare(strict_types=1);

function hash_password(string $plain): string
{
    // cost 10 = same as Node's bcryptjs.hash(p, 10)
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password(string $plain, string $hash): bool
{
    // Accepts $2a$, $2b$, $2y$ (both bcryptjs and PHP use the same format)
    return password_verify($plain, $hash);
}