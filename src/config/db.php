<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '5432';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: 'postgres';
        $name = getenv('DB_NAME') ?: 'csit314_seven';
        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** 
 * Safely replaces $1, $2 placeholders with ? and reorders the parameters array accordingly. 
 */
function db_prepare(string $sql, array $params): array
{
    $newParams = [];
    $newSql = preg_replace_callback('/\$(\d+)/', function ($m) use (&$newParams, $params) {
        $index = (int)$m[1] - 1;
        $newParams[] = $params[$index] ?? null;
        return '?';
    }, $sql);
    return [$newSql, $newParams];
}

function q(string $sql, array $params = []): array
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function q1(string $sql, array $params = []): ?array
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ex(string $sql, array $params = []): void
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}