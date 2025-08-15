# AFRIKENKID AI Agent Rules

**1. Project Structure:**
*   **Maintain Directories**: Strictly follow the established project structure: `/public`, `/src`, `/templates`, `/logs`, `/pids`.
*   **File Responsibilities**:
    *   `/public`: Web server root (entry point, assets, `.htaccess`).
    *   `/src`: Core application logic (`Controllers`, `Services`, `Router`).
    *   `/templates`: View files.
    *   `/logs`: Bot logs.
    *   `/pids`: Bot process IDs.
*   **Logic Location**: Business logic MUST be in `/Services`, NOT in `/Controllers`.

**2. Development Practices:**
*   **No Git Operations**: AI agents do not perform Git actions.
*   **Dependencies**: Use Composer for PHP dependencies (`composer install`/`update`).
*   **Environment Variables**: Use `.env` for all sensitive and environment-specific settings. Never commit secrets directly.

**3. Routing:**
*   Define all routes (web and API) in `src/router.php`. Routes should be RESTful and map to Controller actions.

**4. Controllers:**
*   **Handle Requests**: Parse, validate, and delegate requests to Services.
*   **No Business Logic**: Controllers must NOT contain business logic.
*   **Validate Data**: Rigorously validate all incoming data before passing to Services.
*   **Consistent Responses**: Generate JSON for APIs and render templates for the dashboard.
*   **Error Handling**: Catch Service exceptions and return standardized error responses.

**5. Templates:**
*   **Consistency**: Maintain visual and structural consistency using `templates/layout.php`.
*   **Secure Data**: Ensure data passed to templates is secure and easy to render.
*   **Sanitize Output**: Sanitize all user-generated content in templates to prevent XSS.
*   **Template Rendering and Output Buffering**:
    *   When rendering views, output buffering should be managed centrally by the controller responsible for the view.
    *   Template files (e.g., in the `/templates` directory) should focus solely on outputting their HTML content directly, without initiating their own output buffering (`ob_start()`, `ob_get_clean()`).
    *   This separation of concerns ensures controllers handle the request lifecycle and view composition, while templates remain presentation-focused.

**6. AI Strategy:**
*   **Storage**: AI strategies are in the `trade_logic_source` database table.
*   **Validation**: Validate "Live Strategy Editor" changes for JSON correctness.
*   **AI Modes**: Understand and adhere to the four AI operating modes (Executor, Tactical, Mechanical, Adaptive).
*   **Learning Data**: Use the `learning_directive` format for `ai_learnings_notes`.

**7. Security:**
*   **API Keys**: Use a 32-character `APP_ENCRYPTION_KEY` for API key encryption.
*   **Authentication**: Ensure secure password hashing and session management.
*   **Input Sanitization**: Sanitize all user inputs to prevent SQL injection and XSS.
*   **Rate Limiting**: Implement rate limiting for APIs and bot actions.

**8. Error Handling & Logging:**
*   **Logging**: Use Monolog for structured logging (console, database). Bot logs go in `/logs/`.
*   **State Machine**: Log all state transitions; ensure state machine logic is robust.
*   **Resilience**: Implement retries for API errors and ensure graceful shutdowns.

**9. Configuration:**
*   **`.env`**: Use `.env` for all sensitive and environment-specific configurations.
*   **Database**: Ensure `.env` database details are correct.
*   **Bot Parameters**: Manage bot parameters (symbols, intervals, AI modes) via the dashboard and store in the database.

**10. Workflow Priorities:**
*   **Rule Adherence**: Always consult these rules before starting a task.
*   **Task Priority Hierarchy**:
    1.  Security (Section 7)
    2.  Project Structure (Section 1)
    3.  Error Handling & Logging (Section 8)
    4.  Development Operations (Section 2)
    5.  Controller Operations (Section 4)
    6.  Configuration Management (Section 9)
    7.  Routing Management (Section 3)
    8.  AI Strategy (Section 6)
    9.  Template Management (Section 5)

**11. File Structure Changes:**
*   **Strict Adherence**: Maintain the project structure.
*   **Propose Changes**: If a structural change is needed, propose it explicitly with rule references and await approval.

**12. Edits and Amendments:**
*   **Rule Compliance**: Make edits according to all rules, focusing on validation, Service logic, sanitization, `.env` usage, security, and logging.
*   **Tool Preference**: Prefer `replace_in_file` for targeted edits; use `write_to_file` for new files or complete overwrites.

**13. Security Updates:**
*   **Passwords**: Use strong, unique passwords for sensitive credentials or prompt the user.
*   **Configuration**: Manage sensitive values via `.env`, avoid hardcoding.
*   **Production Keys**: Use live API keys for production.

**14. Logging Details:**
*   **Internal Logging**: Log detailed errors internally (Monolog).
*   **User Messages**: Present generic, user-friendly error messages.
*   **Bot Scripts**: Parse output from scripts like `bot_manager.sh` robustly (e.g., structured data, exit codes).
