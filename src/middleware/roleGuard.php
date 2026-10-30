<?php
declare(strict_types=1);

function role_guard(string ...$roles): callable
{
    return function () use ($roles): bool {
        $u = session_user();
        if (!$u) { json_out(401, ['error' => 'Not authenticated']); return false; }
        if (!in_array($u['role'], $roles, true)) {
            json_out(403, ['error' => 'Forbidden']);
            return false;
        }
        return true;
    };
}