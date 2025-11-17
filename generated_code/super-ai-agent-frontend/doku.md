# Symfony AI Agent Project Documentation

This document provides a comprehensive overview of the Symfony AI Agent backend application, covering its architecture, API endpoints, authentication mechanisms, and key functionalities. It is intended to serve as a guide for frontend developers to integrate with the backend services.

## 1. Introduction

The Symfony AI Agent is a backend application designed to expose AI agent capabilities via a RESTful API. It leverages Symfony components, Doctrine ORM, and integrates with AI platforms to perform various tasks, including code generation, knowledge base indexing, and personal assistance. The application supports both custom email/password authentication and Google OAuth2.

## 2. Authentication

The project implements two primary authentication methods: Custom Email/Password authentication and Google OAuth2. Both methods issue JSON Web Tokens (JWT) for API access and utilize refresh tokens for extended session management, stored in secure HTTP-only cookies.

### 2.1. Custom Email/Password Authentication

This method allows users to register and authenticate using their email address and a password.

#### Endpoints:

*   **`POST /api/register`**
    *   **Description:** Registers a new user with an email and password.
    *   **Request Body (`application/json`):**
        ```json
        {
          "email": "user@example.com",
          "password": "strongPassword123"
        }
        ```
    *   **Responses:**
        *   `201 Created`: `{"message": "Benutzer erfolgreich registriert."}`
        *   `400 Bad Request`: `{"error": "E-Mail ist bereits registriert."}` or `{"error": "Passwort muss mindestens 8 Zeichen lang sein."}`
        *   `500 Internal Server Error`: `{"error": "Registrierung fehlgeschlagen"}`
    *   **Notes:** Password must be at least 8 characters long.
*   **`POST /api/login`**
    *   **Description:** Authenticates a user with email and password, returning an access token and a refresh token in HTTP-only cookies.
    *   **Request Body (`application/json`):**
        ```json
        {
          "email": "user@example.com",
          "password": "strongPassword123"
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"message": "Login erfolgreich.", "user": {"email": "user@example.com"}}`
            *   Sets `BEARER` (access token) and `refresh_token` (refresh token) HTTP-only cookies.
        *   `401 Unauthorized`: `{"error": "Ungültige Anmeldedaten."}`
        *   `429 Too Many Requests`: `{"error": "Zu viele Versuche", "retry_after": <timestamp>}` (Rate-limited)
    *   **Notes:** The `AppCustomAuthenticator` handles the login process and JWT issuance. A `login_limiter` rate limiter is applied to prevent brute-force attacks.
*   **`POST /api/logout`**
    *   **Description:** Logs out the currently authenticated user by clearing the `BEARER` and `refresh_token` cookies.
    *   **Responses:**
        *   `200 OK`: `{"message": "Logout erfolgreich."}`
*   **`POST /api/password/request-reset`**
    *   **Description:** Initiates the password reset process. Sends a password reset email to the provided email address if a user exists.
    *   **Request Body (`application/json`):**
        ```json
        {
          "email": "user@example.com"
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"message": "Falls die E-Mail existiert, wurde eine Reset-E-Mail versendet."}` (Always returns 200 to prevent email enumeration).
        *   `429 Too Many Requests`: `{"error": "Zu viele Passwort-Reset-Anfragen. Bitte später erneut."}` (Rate-limited)
    *   **Notes:** The email contains a link with a one-time token that is valid for a limited time (default: 1 hour).
*   **`POST /api/password/reset`**
    *   **Description:** Resets the user's password using a valid reset token received via email.
    *   **Request Body (`application/json`):**
        ```json
        {
          "token": "reset_token_from_email",
          "newPassword": "newStrongPassword123"
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"message": "Passwort erfolgreich zurückgesetzt."}`
        *   `400 Bad Request`: `{"error": "Ungültiger Reset-Token."}` or `{"error": "Reset-Token ist abgelaufen."}` or `{"error": "Passwort muss mindestens 8 Zeichen lang sein."}`
        *   `500 Internal Server Error`: `{"error": "Fehler beim Zurücksetzen des Passworts"}`
*   **`POST /api/password/change`**
    *   **Description:** Allows an authenticated user to change their password.
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Request Body (`application/json`):**
        ```json
        {
          "currentPassword": "currentStrongPassword",
          "newPassword": "newSuperStrongPassword"
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"message": "Passwort erfolgreich geändert."}`
        *   `400 Bad Request`: `{"error": "Das aktuelle Passwort ist ungültig."}` or `{"error": "Neues Passwort muss sich vom alten unterscheiden."}` or `{"error": "Neues Passwort muss mindestens 8 Zeichen lang sein."}`
        *   `401 Unauthorized`: `{"error": "Nicht authentifiziert."}`
        *   `500 Internal Server Error`: `{"error": "Fehler beim Ändern des Passworts"}`

### 2.2. Google OAuth2 Authentication

This method allows users to authenticate using their Google account. The flow is initiated on the backend, redirects to Google for consent, and then Google redirects back to a backend callback URL which handles user provisioning and JWT issuance.

#### Flow Overview:

1.  **Initiate OAuth:** Frontend calls `GET /connect/google`.
2.  **Google Consent:** Backend redirects to Google's consent screen.
3.  **Google Callback:** After user consent, Google redirects back to `/connect/google/check`.
4.  **Backend Processing:** The `GoogleAuthenticator` intercepts the callback, fetches user data from Google, creates or updates a `User` entity, and issues JWT and refresh tokens.
5.  **Frontend Redirect:** Backend redirects to a frontend success URL (e.g., `FRONTEND_URL/auth/success`) with the JWT in an HTTP-only cookie.

#### Endpoints:

*   **`GET /connect/google`**
    *   **Description:** Initiates the Google OAuth2 authentication flow.
    *   **Responses:**
        *   `302 Redirect`: Redirects the user to Google's authentication page.
*   **`GET /connect/google/check`**
    *   **Description:** The callback URL Google redirects to after user consent. This endpoint is typically intercepted by `GoogleAuthenticator`. If this code is reached, it indicates an OAuth failure.
    *   **Responses:**
        *   `302 Redirect`: Redirects to the frontend login page with an error (`/login?error=oauth_failed`).
*   **`GET /api/user`**
    *   **Description:** Optional API endpoint to retrieve authenticated user data after a successful Google OAuth login.
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Responses:**
        *   `200 OK`: `{"status": "success", "user": {"id": "...", "email": "...", "name": "...", "googleId": "...", "avatarUrl": "...", "roles": [...]}}`
        *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### Key Components:

*   **`GoogleOAuthController`:** Provides the `/connect/google` endpoint to initiate the OAuth flow and `/api/user` to fetch user data.
*   **`GoogleAuthenticator`:** Intercepts the `/connect/google/check` callback. It uses `KnpU\OAuth2ClientBundle` to fetch user data from Google, then utilizes `UserRepository` to find or create a `User` entity based on Google ID or email. It sets default roles (`ROLE_USER`) and issues JWT and refresh tokens as HTTP-only cookies.
*   **`GoogleUserProvider`:** Extends `OAuthUserProvider` to load or create `User` entities from Google OAuth2 resource owner data. It handles finding users by `googleId` or `email`, creating new users, and refreshing user data from the database.
*   **`GoogleClientService`:** Manages the Google API client, setting up client ID, secret, redirect URI, and necessary scopes (e.g., `Gmail::GMAIL_SEND`, `Calendar::CALENDAR`). It also handles refreshing expired access tokens using the stored refresh token.

### 2.3. JWT Token Authenticator (`JwtTokenAuthenticator`)

This authenticator is responsible for verifying the `BEARER` token cookie on subsequent API requests.

*   **Functionality:** It checks for the presence and validity of the `BEARER` cookie. If found, it parses the JWT, extracts the username (email), and loads the corresponding user.
*   **Error Handling:** On authentication failure (missing, invalid, or expired token), it returns a `401 Unauthorized` JSON response. It also implements `AuthenticationEntryPointInterface::start()` to correctly handle unauthenticated access to protected endpoints.

## 3. API Endpoints

This section outlines the various API endpoints available in the application.

*   **`GET /health`**
    *   **Description:** Health check endpoint to verify API and database connectivity.
    *   **Responses:**
        *   `200 OK`: `{"status": "healthy", "timestamp": "...", "database": "connected"}`
        *   `503 Service Unavailable`: `{"status": "unhealthy", "timestamp": "...", "database": "disconnected", "error": "..."}`
    *   **Authentication:** None (public endpoint).
*   **`POST /api/devAgent`**
    *   **Description:** Enqueues a prompt for the Symfony Developing expert AI Agent to be processed asynchronously by workers.
    *   **Request Body (`application/json`):**
        ```json
        {
          "prompt": "Generate a deployment script for nginx"
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"status": "queued", "message": "Job wurde erfolgreich in die Warteschlange gelegt."}`
        *   `400 Bad Request`: `{"error": "Missing prompt"}`
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Notes:** This endpoint dispatches an `AiAgentJob` message to the message bus.
*   **`POST /api/agent`**
    *   **Description:** Starts the Personal Assistant AI Agent asynchronously.
    *   **Request Body (`application/json`, ref `#/components/schemas/AgentPromptRequest`):**
        ```json
        {
          "prompt": "Plan my day tomorrow including a meeting at 10 AM.",
          "context": "...",
          "options": {}
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"status": "queued", "sessionId": "...", "message": "Job wurde erfolgreich zur Warteschlange hinzugefügt. Nutze /api/agent/status/{sessionId} für Updates.", "statusUrl": "/api/agent/status/{sessionId}"}`
        *   `400 Bad Request`: `{"status": "error", "message": "Kein Prompt angegeben"}`
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Notes:** Generates a `sessionId` for tracking and dispatches a `PersonalAssistantJob` message.
*   **`POST /api/frondend_devAgent`**
    *   **Description:** Starts the Frontend Generator AI Agent asynchronously.
    *   **Request Body (`application/json`, ref `#/components/schemas/AgentPromptRequest`):**
        ```json
        {
          "prompt": "Create a React component for a user profile page.",
          "context": "...",
          "options": {}
        }
        ```
    *   **Responses:**
        *   `200 OK`: `{"status": "queued", "sessionId": "...", "message": "Frontend Generator Job zur Warteschlange hinzugefügt.", "statusUrl": "/api/agent/status/{sessionId}"}`
        *   `400 Bad Request`: `{"status": "error", "message": "Kein Prompt angegeben"}`
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Notes:** Generates a `sessionId` for tracking and dispatches a `FrontendGeneratorJob` message.
*   **`GET /api/agent/status/{sessionId}`**
    *   **Description:** Retrieves all status updates for a specific AI agent session.
    *   **Path Parameters:**
        *   `sessionId` (string, required): The unique session ID for which to retrieve status updates.
    *   **Query Parameters:**
        *   `since` (string, optional, `date-time` format): ISO 8601 Timestamp for incremental updates (e.g., `2024-01-15T10:30:00+00:00`).
    *   **Responses:**
        *   `200 OK`: `{"sessionId": "...", "statuses": [{"timestamp": "...", "message": "..."}, ...], "completed": false, "result": null, "error": null, "timestamp": "..."}`
        *   `400 Bad Request`: `{"error": "Invalid since parameter"}`
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Notes:** The `completed` flag, `result`, and `error` fields are derived from special `RESULT:`, `ERROR:`, or `DEPLOYMENT:` messages within the status updates.
*   **`POST /api/index-knowledge`**
    *   **Description:** Triggers the indexing of the knowledge base documents into the vector store.
    *   **Responses:**
        *   `200 OK`: `{"status": "success", "message": "Knowledge base indexed successfully.", "details": "..."}`
        *   `500 Internal Server Error`: `{"error": "Knowledge base indexing failed.", "details": "..."}`
    *   **Authentication:** Requires a valid `BEARER` cookie.
    *   **Notes:** This endpoint calls the `KnowledgeIndexerTool` directly.

## 4. AI Agent Tools

The following tools are available to the AI agents to perform specific tasks. Each tool is a PHP class annotated with `#[AsTool]` and registered as a service.

*   **`analyze_code` (`App\Tool\AnalyzeCodeTool`)**
    *   **Description:** Analyzes project files and returns metrics and per-file details (path, lines, size, textual content truncated).
*   **`api_client` (`App\Tool\ApiClientTool`)**
    *   **Description:** Makes HTTP requests to external APIs. Supports GET, POST, PUT, DELETE methods with JSON payloads and headers. Handles API responses and errors.
*   **`save_code_file` (`App\Tool\CodeSaverTool`)**
    *   **Description:** Saves the provided code content to a file with a given name in the `generated_code` directory, supporting subdirectories.
*   **`deploy_generated_code` (`App\Tool\DeployGeneratedCodeTool`)**
    *   **Description:** Creates a comprehensive, safe deployment package with rollback capability. Includes config changes, dependency updates, and database migrations. Requires explicit user approval.
*   **`google_calendar_create_event` (`App\Tool\GoogleCalendarCreateEventTool`)**
    *   **Description:** Creates a new event in the Google Calendar of the currently authenticated user.
*   **`index_knowledge_base` (`App\Tool\KnowledgeIndexerTool`)**
    *   **Description:** Indexes RST and Markdown documentation files from the `knowledge_base` directory into a MySQL vector store.
*   **`mysql_knowledge_search` (`App\Tool\MySQLKnowledgeSearchTool`)**
    *   **Description:** Searches the MySQL knowledge base using semantic similarity, returning relevant documentation chunks.
*   **`PdfGenerator` (`App\Tool\PdfGenerator`)**
    *   **Description:** Generates a PDF file from a given text and saves it with the specified filename.
*   **`run_code_in_sandbox` (`App\Tool\SandboxExecutorTool`)**
    *   **Description:** Executes code in a complete isolated project clone, runs tests in a Docker sandbox, and generates structured update packages.
*   **`request_tool_development` (`App\Tool\ToolRequestor`)**
    *   **Description:** Requests the DevAgent API to create a new Symfony AI Agent tool.
*   **`web_scraper` (`App\Tool\WebScraperTool`)**
    *   **Description:** Accesses a given URL, parses its HTML content, and extracts data using CSS selectors.

## 5. Core Services

*   **`AgentStatusService` (`App\Service\AgentStatusService`)**
    *   Manages the persistence and retrieval of status messages for AI agent sessions in the database. Provides methods to add, retrieve, clear, and get active session statuses.
*   **`AiAgentService` (`App\Service\AiAgentService`)**
    *   Orchestrates the execution of AI agent prompts. Implements a robust retry mechanism with exponential backoff and a circuit breaker pattern to handle transient errors and protect external AI APIs. Dispatches `AiAgentJob` messages.
*   **`AuthService` (`App\Service\AuthService`)**
    *   Handles user registration logic, including checking for existing emails and hashing passwords.
*   **`CircuitBreakerService` (`App\Service\CircuitBreakerService`)**
    *   Implements the Circuit Breaker pattern to prevent cascading failures in interactions with external services (e.g., AI APIs). It can open (block requests), half-open (test recovery), and close (resume normal operation).
*   **`FileSecurityService` (`App\Service\FileSecurityService`)**
    *   Provides file upload validation (MIME types, size, dimensions, filename) and secure file saving/deletion.
*   **`GoogleClientService` (`App\Service\GoogleClientService`)**
    *   Manages the Google API client configuration and token management for Google OAuth2. It handles refreshing expired access tokens to ensure continuous access to Google services on behalf of the user.
*   **`PasswordService` (`App\Service\PasswordService`)**
    *   Manages password-related operations: requesting password resets (generating tokens and sending emails), resetting passwords with a token, and allowing authenticated users to change their password.
*   **`AuditLoggerService` (`App\Service\AuditLoggerService`)**
    *   Provides centralized logging for security-relevant events, including authentication attempts, unauthorized access, and suspicious activities.

## 6. Entities

*   **`User` (`App\Entity\User`)**
    *   Represents a user in the system. Stores email, hashed password, roles, Google ID, Google OAuth tokens (access and refresh), Google token expiry, and password reset tokens/expiry. Implements `UserInterface` and `PasswordAuthenticatedUserInterface`.
*   **`AgentStatus` (`App\Entity\AgentStatus`)**
    *   Stores status updates for AI agent sessions, including `sessionId`, `message`, and `createdAt` timestamp.
*   **`KnowledgeDocument` (`App\Entity\KnowledgeDocument`)**
    *   Represents a document chunk stored in the MySQL vector store. Contains `id`, `sourceFile`, `content`, `metadata`, `embedding` (vector data), `embeddingDimension`, `createdAt`, and `updatedAt`.
*   **`RefreshToken` (`App\Entity\RefreshToken`)**
    *   Extends `Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken` to store refresh tokens for JWT authentication.

## 7. Asynchronous Processing (Symfony Messenger)

The application uses Symfony Messenger for asynchronous job processing, improving responsiveness and fault tolerance.

*   **`AiAgentJob` / `AiAgentJobHandler`:** Handles general AI agent prompts.
*   **`FrontendGeneratorJob` / `FrontendGeneratorJobHandler`:** Specifically designed for frontend code generation tasks, including deployment package creation.
*   **`PersonalAssistantJob` / `PersonalAssistantJobHandler`:** Processes prompts for the personal assistant agent.

These jobs are dispatched to a message queue (configured in `config/packages/messenger.yaml`) and processed by dedicated workers. Status updates for these jobs are managed by the `AgentStatusService`.

## 8. Security Measures

*   **`ExceptionListener`:** Catches all exceptions, logs them with full details in development, and provides a generic error message in production to prevent information disclosure.
*   **`SecurityHeadersListener`:** Sets various HTTP security headers (`Strict-Transport-Security`, `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`) to enhance security against common web vulnerabilities. It also handles HTTPS redirection in production.
*   **Rate Limiting:** Implemented for login and password reset endpoints to mitigate brute-force and denial-of-service attacks.

## 9. Key Configuration Files

*   **`config/bundles.php`:** Registers all Symfony bundles used in the project, including `LexikJWTAuthenticationBundle`, `GesdinetJWTRefreshTokenBundle`, `NelmioApiDocBundle`, `Symfony\AI\AiBundle`, and `KnpU\OAuth2ClientBundle`.
*   **`config/packages/security.yaml`:**
    *   Defines security firewalls (`main`, `api`, `dev`).
    *   Configures user providers (`app_user_provider`, `google_user_provider`).
    *   Registers `JwtTokenAuthenticator`, `GoogleAuthenticator`, and `AppCustomAuthenticator`.
    *   Sets up password hashing algorithms.
    *   Defines access control rules (e.g., `/api/admin` requires `ROLE_ADMIN`).
*   **`config/packages/lexik_jwt_authentication.yaml`:**
    *   Configures JWT token encryption keys, token TTL, and public/private key paths.
*   **`config/packages/gesdinet_jwt_refresh_token.yaml`:**
    *   Configures refresh token TTL, entity manager, and route for refreshing tokens.
*   **`config/packages/knpu_oauth2_client.yaml`:**
    *   Configures Google OAuth2 client details, including `clientId`, `clientSecret`, and `redirectRoute`.
*   **`config/packages/rate_limiter.yaml`:**
    *   Defines rate limiters for login attempts (`login_limiter`) and password reset requests (`password_reset_limiter`).
*   **`config/packages/ai.yaml`:**
    *   Configures the Symfony AI Bundle, defining AI agents (`ai.agent.personal_assistent`, `ai.agent.file_generator`, `ai.agent.frontend_generator`) and platform (e.g., `gemini`), and registering available tools.
*   **`config/services.yaml`:**
    *   Autoconfigures and autowires services. Important custom services (`AgentStatusService`, `AiAgentService`, `AuthService`, etc.) are defined here, and dependencies are injected.
    *   AI Agent Tools are tagged with `ai.tool`.
*   **`.env` and `.env.local`:**
    *   Contain environment-specific variables like `DATABASE_URL`, `FRONTEND_URL`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and API keys for AI platforms (`GEMINI_API_KEY`, `OPENAI_API_KEY`, etc.).

This documentation provides a comprehensive overview for frontend developers to understand and integrate with the Symfony AI Agent backend.
