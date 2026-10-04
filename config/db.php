<?php
// ============================================================
// config/db.php
// PostgreSQL (Supabase). Set DB_HOST, DB_PORT, DB_NAME, DB_USER and
// DB_PASS in .env locally or in the Vercel environment variables.
//
// Supabase transaction pooler (used on Vercel):
//   DB_PORT=6543, DB_NAME=postgres, DB_USER=postgres.<project ref>
//
// Timezone: the database session must run in Asia/Manila so NOW()
// agrees with PHP. Set it once on the database role:
//   ALTER ROLE postgres SET timezone TO 'Asia/Manila';
// A SET sent from here would not stick on the transaction pooler.
// ============================================================

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '5432');
define('DB_NAME',    getenv('DB_NAME')    ?: 'postgres');
define('DB_USER',    getenv('DB_USER')    ?: 'postgres');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSLMODE
    );

    // Emulated prepares: the Supabase transaction pooler does not support
    // named server-side prepared statements.
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection error. Please contact the system administrator.');
    }

    return $pdo;
}
