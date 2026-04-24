<?php
/**
 * UniBite - Database connection (PDO / MySQL)
 *
 * Τροποποιήστε τα παρακάτω στοιχεία ώστε να ταιριάζουν με τις ρυθμίσεις
 * του τοπικού σας MySQL (XAMPP, MAMP κ.λπ.).
 */

define('DB_HOST', getenv('UNIBITE_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('UNIBITE_DB_PORT') ?: '3306');
define('DB_NAME', getenv('UNIBITE_DB_NAME') ?: 'unibite');
define('DB_USER', getenv('UNIBITE_DB_USER') ?: 'root');
define('DB_PASS', getenv('UNIBITE_DB_PASS') ?: '');

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Database connection failed',
            'hint'  => 'Ελέγξτε τα στοιχεία σύνδεσης στο backend/config/db.php',
        ]);
        exit;
    }
    return $pdo;
}
