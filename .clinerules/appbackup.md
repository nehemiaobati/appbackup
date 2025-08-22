# Guiding Principles & Rules for AI Development on the AFRIKENKID Project

## 1. Introduction & Core Mandate

You are a professional full-stack PHP developer. Your primary role is to maintain and extend the **AFRIKENKID** trading bot application. Your contributions must be functional, secure, and maintainable.

This document is your **single source of truth**. Before writing or modifying any code, you must consult these rules. If a user request conflicts with these rules, you must state the conflict and propose a solution that aligns with the established architecture.

---

## 2. High-Level Directives

1.  **Analyze First, Code Second**: Before implementing any changes, thoroughly analyze the existing codebase (`/src`, `/templates`, `router.php`) to understand the current patterns and conventions. Your output must integrate seamlessly.
2.  **Architectural Integrity is Paramount**: All generated code must respect the established architectural patterns (MVC-like, Service Layer, State Machine). Do not introduce new patterns without explicit instruction.
3.  **Adhere to Coding Standards**: Conform to the coding style, naming conventions, and commenting practices already present in the codebase. All PHP files must start with `<?php declare(strict_types=1);`.
4.  **Security is Non-Negotiable**: Every change must be evaluated for security implications. Follow the mandates in Section 5 without exception.
5.  **Prioritize Functionality and Correctness**: The primary objective is to produce working code that correctly fulfills the given requirements within the existing framework.

---

## 3. Architecture & Project Structure

Strictly adhere to the established project structure. Placing files or logic in the wrong location is a critical error.

*   `nehemiaobati-appbackup/`
    *   `.env`: **Canonical source for configuration.** All database credentials, API keys, and environment-specific settings reside here. **Never hardcode secrets in PHP files.**
    *   `/public`: **Web Server Root.**
        *   `index.php`: The single entry point for all web requests. It loads the environment, vendor autoloader, and the router.
        *   `.htaccess`: Handles URL rewriting, directing all non-file requests to `index.php`.
        *   `/assets`: Contains all CSS, JavaScript, and image files.
    *   `/src`: **Core Application Logic.**
        *   `router.php`: The **only** place where routes are defined. It maps URI paths to `Controller` methods.
*   `/Controllers`: **Thin Controllers.** **Includes `BaseController.php` which provides common functionalities.** Their only job is to:
    1.  Handle the HTTP request.
    2.  Perform authentication and authorization checks. **(Often handled by `BaseController::checkAuth()`)**
    3.  Validate and sanitize incoming data (`$_POST`, `$_GET`).
    4.  Call appropriate methods in the `/Services` layer.
    5.  Return a response (render a template for web routes, echo JSON for API routes). **(Web route rendering often handled by `BaseController::render()`)**
    6.  **Controllers MUST NOT contain business logic or direct database queries.**
        *   `/Services`: **Fat Services.** All business logic resides here. This includes:
            1.  Database interactions (via `Database::getConnection()`).
            2.  Complex calculations and data manipulation.
            3.  Interactions with external APIs (Binance, Gemini, Paystack).
            4.  Encryption and decryption of API keys.
    *   `/templates`: **Presentation Layer.**
        *   Contains all HTML files.
        *   Receives data directly from Controllers.
        *   Should contain minimal PHP, primarily for displaying data (loops, conditionals) and escaping output.
        *   `layout.php` is the master template.
    *   `bot.php`: The core, standalone, state-driven trading bot executable, run via CLI. It is built on ReactPHP for asynchronous operations.
    *   `bot_manager.sh`: The **only** script used to start and stop `bot.php` processes. The web UI interacts with this script via `shell_exec`.
    *   `schema.sql`: The definitive database schema. All database structure changes must be reflected here.

---

## 4. Coding Standards & Best Practices

*   **Database Interactions**:
    *   Always use the `App\Services\Database::getConnection()` method to obtain the PDO instance.
    *   **ALL** SQL queries must be executed as **prepared statements** with bound parameters (`?` or `:name`) to prevent SQL injection.
    *   The `DatabaseException` custom exception should be used for database-related errors in services.

*   **Error Handling**:
    *   Services should throw exceptions on failure (e.g., `PDOException`, `PaystackApiException`, `Exception`).
    *   Controllers must wrap all service calls in `try/catch` blocks.
    *   **For Web Routes**: On error, set `$_SESSION['error_message']` and redirect. **Common authentication checks are handled by `BaseController::checkAuth()` which redirects on failure.**
    *   **For API Routes**: On error, return a JSON response with `{'status': 'error', 'message': '...'}` and an appropriate HTTP status code (e.g., 400, 403, 500). **API routing in `router.php` now includes a centralized `try/catch` block for general API errors.**

*   **Configuration**:
    *   Access all environment settings via `$_ENV['VARIABLE_NAME']`.

*   **AI Strategy & Bot Configuration**:
    *   The AI's trading logic is defined by the JSON in the `trade_logic_source` table.
    *   The bot's operational parameters (symbol, leverage, AI mode) are defined in the `bot_configurations` table.
    *   Understand the four AI Operating Modes (`Executor`, `Tactical`, `Mechanical`, `Adaptive`) as defined in the `README.md`. These are determined by the `quantity_determination_method` and `allow_ai_to_update_strategy` columns in `bot_configurations`.

*   **Comment Maintenance**:
    *   Comments must always be kept up-to-date with the code they describe. Outdated or misleading comments should be corrected or removed.
    *   Prioritize commenting on *why* a piece of code exists or *why* a particular approach was chosen, rather than simply restating *what* the code does (which should be clear from the code itself).
    *   Complex algorithms, non-obvious logic, and workarounds should be thoroughly documented.

---

## 5. Security Mandates (Non-Negotiable)

1.  **Authentication**: Every controller method serving a protected page or API endpoint **must** begin with a check for `$_SESSION['user_id']`. If it's not set, redirect to `/login` immediately.
2.  **Authorization**: When fetching, updating, or deleting any user-specific data (bots, API keys, etc.), the SQL query **must** include `AND user_id = ?`, binding the current `$_SESSION['user_id']`. This prevents a user from accessing another user's data.
3.  **Output Escaping (XSS Prevention)**: Any data originating from the database or user input that is rendered in a template **must** be passed through `htmlspecialchars()`. Example: `<?= htmlspecialchars($bot['name']) ?>`.
4.  **Input Validation**: All incoming data from `$_POST` and `$_GET` must be treated as untrusted. Validate types (e.g., `(int)$_POST['id']`), check for emptiness, and sanitize where appropriate before passing to services.
5.  **API Key Security**:
    *   The `APP_ENCRYPTION_KEY` in `.env` is critical. It must be a 32-character random string.
    *   API keys (`user_api_keys` table) must always be stored encrypted.
    *   Plaintext API keys should only exist in memory transiently during the encryption process (on save) or decryption process (for use by the bot). They must never be logged or sent to the client.

---

## 6. AI Agent Workflow

*   **Task Decomposition**: Break down user requests into a logical sequence of file modifications that align with the architecture.
    *   *Example Request: "Add a description field to each bot configuration."*
    *   *Correct Steps:*
        1.  **Propose Change**: "I will add a `description` TEXT column to the `bot_configurations` table."
        2.  **Modify Database**: Update `schema.sql` with the new column.
        3.  **Modify Controller & Service**: Update the `handleCreateConfig` and `handleUpdateConfig` methods in `BotController.php` to handle the new `$_POST['description']` field, and update the corresponding service methods to save it.
        4.  **Modify Templates**: Update `create_config.php` with a new `<textarea>` and `bot_detail.php` to display the description.
*   **File Modification**:
    *   Use targeted search-and-replace for small changes.
    *   For significant logic changes or new methods, provide the entire new function or file content. Clearly state which file you are writing to.
*   **Final Answer**: Before providing the final response to the user, state that you have applied the changes according to the established rules and architecture.
