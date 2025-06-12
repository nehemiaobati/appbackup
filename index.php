<?php
/**
 * P2Profit Bot Dashboard - Entry Point
 *
 * This file serves as the main entry point for the application.
 * Its sole purpose is to redirect all incoming web requests from the root
 * to the main dashboard controller file (dashboard.php).
 *
 * This approach provides a clean, user-friendly URL (e.g., yourdomain.com/)
 * while keeping the core application logic organized in a separate file.
 *
 * It uses a 301 "Moved Permanently" redirect, which is the standard
 * for permanent redirects and is good for SEO if the application were
 * to be publicly indexed.
 *
 * PHP Version 8.1+
 *
 * @category  Application
 * @package   P2ProfitBot
 * @author    Your Name <you@example.com>
 * @license   MIT
 * @link      https://example.com
 */

// Define the target file for redirection.
$redirect_target = 'dashboard.php';

// Send a permanent redirect header (HTTP 301).
// This informs browsers and search engines that the content has permanently moved.
header("Location: " . $redirect_target, true, 301);

// It's a best practice to call exit() after a redirect header
// to prevent any further script execution.
exit();
?>