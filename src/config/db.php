<?php
declare(strict_types=1);

/**
 * =============================================================================
 * DATABASE CONNECTION & QUERY HELPERS
 * =============================================================================
 * Provides a Singleton PDO instance and wrapper functions for running queries.
 * 
 * WHY THE CUSTOM PLACEHOLDER LOGIC?
 * PostgreSQL natively uses `$1, $2` for prepared statement placeholders. 
 * However, PHP's PDO library uses `?`. Because your teammates likely wrote 
 * raw SQL using `$1, $2` (which is standard for Postgres), this file includes 
 * a translator to convert `$1, $2` into `?` so PDO doesn't crash.
 * =============================================================================
 */

/**
 * Singleton Database Connection.
 * Ensures only ONE PDO connection is created per request lifecycle.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Pull credentials from environment variables (loaded by env.php)
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '5432';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: 'postgres';
        $name = getenv('DB_NAME') ?: 'csit314_seven';
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
        
        $pdo = new PDO($dsn, $user, $pass, [
            // Throws PDOException on database errors. 
            // (This is what handle_error() in controllers.php catches!)
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            
            // Returns associative arrays by default (e.g., ['id' => 1, 'name' => 'John'])
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            
            // Disables emulation so actual DB-level prepared statements are used 
            // (crucial for security and performance).
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** 
 * Translates Postgres `$1, $2` placeholders into PDO `?` placeholders.
 * 
 * @param string $sql    The SQL query with $1, $2 placeholders.
 * @param array  $params The values to bind.
 * @return array         [0] => Modified SQL string with `?`, [1] => Reordered params array
 */
function db_prepare(string $sql, array $params): array
{
    $newParams = [];
    
    // Regex finds every occurrence of $1, $2, $3, etc.
    $newSql = preg_replace_callback('/\$(\d+)/', function ($m) use (&$newParams, $params) {
        $index = (int)$m[1] - 1; // Convert 1-based index to 0-based array index
        $newParams[] = $params[$index] ?? null; // Populate parameters in the exact order they appear
        return '?'; // Replace with PDO placeholder
    }, $sql);
    
    return [$newSql, $newParams];
}

/**
 * Execute a query and fetch ALL results.
 */
function q(string $sql, array $params = []): array
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a query and fetch a SINGLE row (or null if not found).
 */
function q1(string $sql, array $params = []): ?array
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Execute a query that doesn't return records (INSERT, UPDATE, DELETE).
 */
function ex(string $sql, array $params = []): void
{
    [$sql, $params] = db_prepare($sql, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}