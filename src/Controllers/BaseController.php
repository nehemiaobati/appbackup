<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use PDO;

/**
 * BaseController.php
 *
 * This abstract class provides common functionalities for all controllers,
 * such as authentication checking and template rendering.
 * Controllers that extend this class can reuse these methods,
 * promoting code reusability and consistency.
 */
abstract class BaseController
{
    protected PDO $pdo;

    /**
     * BaseController constructor.
     * Initializes the PDO database connection.
     */
    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Ensures the user is authenticated.
     * If not logged in, redirects to the login page.
     *
     * @return void
     */
    protected function checkAuth(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Renders a specified template, injecting common layout variables.
     * This protected helper method ensures consistent page rendering.
     *
     * @param string $template The name of the template file (e.g., 'login', 'dashboard').
     * @param array $data An associative array of data to extract and make available to the template.
     * @return void
     */
    protected function render(string $template, array $data = []): void
    {
        extract($data); // Extract data array into individual variables for the template.
        $current_user_id = $_SESSION['user_id'] ?? null;
        $username_for_header = $_SESSION['username'] ?? null;
        $view = $template; // Used by layout.php to highlight the active navigation item.

        ob_start(); // Start output buffering to capture template content.
        require __DIR__ . "/../../templates/{$template}.php"; // Include the specific template.
        $content = ob_get_clean(); // Get the buffered content.
        require __DIR__ . "/../../templates/layout.php"; // Include the main layout.
    }
}
