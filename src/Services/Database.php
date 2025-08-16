<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database.php
 *
 * This Service class provides a static method to establish and manage a
 * single PDO database connection for the entire application.
 * It ensures that only one database connection is created, promoting efficiency
 * and preventing resource exhaustion.
 */

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    /**
     * @var PDO|null The static PDO instance to ensure a single connection.
     */
    private static ?PDO $pdo = null;

    /**
     * Establishes and returns a PDO database connection.
     * Uses a static variable to implement the Singleton pattern, preventing multiple connections.
     * Connection parameters are loaded from environment variables (`.env`).
     *
     * @return PDO The PDO database connection instance.
     * @throws RuntimeException If the database connection fails due to configuration issues or network problems.
     */
    public static function getConnection(): PDO
    {
        // If a PDO instance does not already exist, create one.
        if (self::$pdo === null) {
            try {
                // Construct the DSN (Data Source Name) from environment variables.
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    $_ENV['DB_HOST'],
                    $_ENV['DB_PORT'],
                    $_ENV['DB_NAME']
                );

                // Define PDO options for error handling and fetching modes.
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,         // Throw exceptions on errors.
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,    // Fetch results as associative arrays by default.
                    PDO::ATTR_EMULATE_PREPARES => false,                 // Disable emulation for prepared statements for better security and performance.
                ];

                // Create a new PDO instance.
                self::$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
            } catch (PDOException $e) {
                // Log the database connection error for debugging.
                error_log("Database connection failed: " . $e->getMessage());
                // Re-throw as a RuntimeException to indicate a critical application failure.
                throw new RuntimeException("Database connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
        // Return the existing or newly created PDO instance.
        return self::$pdo;
    }
}
