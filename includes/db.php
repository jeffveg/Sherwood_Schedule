<?php
/**
 * Database connection helper.
 *
 * Provides a singleton PDO instance configured for the application.
 * Uses ERRMODE_EXCEPTION so all query errors throw PDOException rather
 * than returning false silently — callers don't need to check return values.
 *
 * Connection parameters come from DB_* constants in config/config.php.
 */

/**
 * Return the shared PDO connection, creating it on first call.
 * Exits with a generic 503 if the DB is unreachable (keeps credentials
 * out of browser output while still logging the real error server-side).
 */
function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                // Throw PDOException on every error (no silent failures)
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Return rows as associative arrays by default
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Use real prepared statements (prevents some edge-case injection risks)
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Log the real error server-side but never expose it to the browser
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(503);
            exit('Service temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}
