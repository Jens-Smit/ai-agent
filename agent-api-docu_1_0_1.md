# API-Dokumentation für React-Flash 19 Frontend

Diese Dokumentation beschreibt alle verfügbaren API-Endpunkte für das React-Flash 19 Frontend-Projekt. Sie dient als umfassende Referenz für die Entwicklung und Integration des Frontends, das auf React 19, JavaScript, Material UI und Framer Motion basiert.

## Inhaltsverzeichnis

1.  [Einleitung](#1-einleitung)
2.  [Authentifizierung & Autorisierung](#2-authentifizierung--autorisierung)
    *   [Standard-Login (E-Mail/Passwort)](#standard-login-e-mailpasswort)
    *   [Google OAuth Login](#google-oauth-login)
    *   [Token-Verwaltung](#token-verwaltung)
    *   [Rate Limiting](#rate-limiting)
    *   [Allgemeine Sicherheitshinweise](#allgemeine-sicherheitshinweise)
3.  [API Endpunkte](#3-api-endpunkte)
    *   [Agent Status](#agent-status)
    *   [Authentication](#authentication)
    *   [AI Agent - Personal Assistant](#ai-agent---personal-assistant)
    *   [Frontend Generator](#frontend-generator)
    *   [System Health](#system-health)
    *   [Knowledge Base (Admin)](#knowledge-base-admin)
    *   [Token Usage & Limits](#token-usage--limits)
    *   [User](#user)
    *   [User Documents](#user-documents)
    *   [User Knowledge Base](#user-knowledge-base)
    *   [User Settings](#user-settings)
    *   [Workflow Management](#workflow-management)
4.  [Datenmodelle (DTOs)](#4-datenmodelle-dtos)

---

## 1. Einleitung

Diese API ist das Backend für eine AI-gesteuerte Anwendung, die verschiedene Agenten (Personal Assistant, Frontend Generator) bereitstellt, Benutzerverwaltung, Dokumentenmanagement und Workflow-Orchestrierung ermöglicht. Die API ist RESTful und verwendet JSON für den Datenaustausch.

## 2. Authentifizierung & Autorisierung

Die API verwendet JWT (JSON Web Tokens) für die Authentifizierung der Benutzer und bietet zusätzlich einen Google OAuth-Flow an. Tokens werden hauptsächlich über HttpOnly Cookies verwaltet.

### Standard-Login (E-Mail/Passwort)

Benutzer können sich mit E-Mail und Passwort authentifizieren. Nach erfolgreicher Authentifizierung werden ein `BEARER` (Access Token) und ein `refresh_token` (Refresh Token) als `HttpOnly` Cookies im Browser gesetzt. Diese Cookies sind essentiell für nachfolgende authentifizierte Anfragen.

*   **Access Token (BEARER):** Kurzlebig (1 Stunde Gültigkeit), direkt für API-Zugriff verwendet.
*   **Refresh Token (refresh_token):** Langlebiger, wird verwendet, um einen neuen Access Token zu erhalten, wenn der aktuelle abgelaufen ist.

### Google OAuth Login

Für die Integration mit Google-Diensten (z.B. Google Calendar) wird ein OAuth2-Flow angeboten. Nach erfolgreicher Google-Authentifizierung wird der Benutzer zu einer definierten Frontend-URL weitergeleitet, und die notwendigen Google OAuth Tokens (Access- und Refresh Token für Google APIs) werden serverseitig gespeichert und mit dem Benutzer verknüpft. Die JWT-Tokens für die API-Authentifizierung werden ebenfalls als HttpOnly Cookies gesetzt.

### Token-Verwaltung

*   **Cookies:** `BEARER` (JWT Access Token) und `refresh_token` (JWT Refresh Token) werden als `HttpOnly`, `SameSite=Lax` Cookies gesetzt. Im Produktionsmodus sollten diese auch `Secure=true` sein.
*   **Keine Speicherung im Frontend:** Sensible Token (Access/Refresh Tokens) sollten niemals im JavaScript des Frontends direkt gespeichert oder manipuliert werden, da sie `HttpOnly` sind. Der Browser sendet sie automatisch mit jeder Anfrage.
*   **Refresh-Mechanismus:** Der Refresh-Token-Bundle im Backend kümmert sich um die Erneuerung des Access-Tokens, wenn dieser abläuft, sofern ein gültiger Refresh-Token vorhanden ist.

### Rate Limiting

Einzelne Endpunkte (z.B. Login, Passwort-Reset) sind durch Rate Limiter geschützt, um Brute-Force-Angriffe und Missbrauch zu verhindern. Bei Überschreitung des Limits wird ein `HTTP 429 Too Many Requests` Statuscode zurückgegeben, oft mit einem `Retry-After`-Header.

### Allgemeine Sicherheitshinweise

*   **HTTPS:** Alle Kommunikationswege zur API sollten über HTTPS verschlüsselt sein. Der Server erzwingt dies in der Produktion (`Strict-Transport-Security`).
*   **CSP (Content Security Policy):** Es werden strenge Content Security Policies angewendet, um XSS-Angriffe zu mitigieren.
*   **X-Frame-Options, X-Content-Type-Options, Referrer-Policy:** Weitere Sicherheitsheader werden gesetzt, um gängige Web-Schwachstellen zu adressieren.
*   **Informations-Disclosure:** Fehlermeldungen in Produktionsumgebungen sind generisch gehalten, um keine internen Details preiszugeben.
*   **Sensible Daten:** Passwörter und Google Refresh Tokens werden serverseitig sicher (gehasht/verschlüsselt) gespeichert und niemals unverschlüsselt übertragen oder im Frontend zugänglich gemacht.

---

## 3. API Endpunkte

### Agent Status

Base Path: `/api/agent`
Tag: `Agent Status`

#### `GET /api/agent/status/{sessionId}`

*   **Summary:** Agent Status abrufen
*   **Description:** Holt alle Status-Updates für eine Session.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `sessionId` (Path, `string`, **Required**): Die eindeutige ID der Agenten-Session.
    *   `since` (Query, `string`, Optional, `format: date-time`): ISO 8601 Timestamp für inkrementelle Updates.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "sessionId": "string",
            "statuses": [
                {
                    "timestamp": "string",
                    "message": "string"
                }
            ],
            "completed": "boolean",
            "result": "string | null",
            "error": "string | null",
            "timestamp": "string"
        }
        ```
    *   `400 Bad Request`: `{"error": "Invalid since parameter"}`

### Authentication

Base Path: `/api`
Tag: `Authentication`

#### `POST /api/login`

*   **Summary:** Benutzer-Login
*   **Description:** Authentifiziert einen Benutzer und gibt Access- und Refresh-Token zurück. Diese werden als HttpOnly Cookies gesetzt.
*   **Method:** POST
*   **Security:** Rate Limited (Login Limiter)
*   **Request Body:** `application/json`
    ```json
    {
        "email": "string (format: email)",
        "password": "string (format: password)"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "message": "Login erfolgreich",
            "user": {
                "email": "string"
            }
        }
        ```
        Cookies: `BEARER` (AccessToken), `refresh_token` (RefreshToken)
    *   `400 Bad Request`: `{"error": "E-Mail und Passwort sind erforderlich."}`
    *   `401 Unauthorized`: `{"error": "Ungültige Anmeldedaten."}`
    *   `429 Too Many Requests`: `{"error": "Zu viele Versuche", "retry_after": "timestamp"}`

#### `POST /api/logout`

*   **Summary:** Benutzer-Logout
*   **Description:** Löscht Access- und Refresh-Token-Cookies.
*   **Method:** POST
*   **Security:** Authenticated User (implied by cookie deletion)
*   **Responses:**
    *   `200 OK`: `{"message": "Logout erfolgreich."}`
        Cookies werden gelöscht.

#### `POST /api/register`

*   **Summary:** Benutzer registrieren
*   **Description:** Registriert einen neuen Benutzer mit E-Mail und Passwort.
*   **Method:** POST
*   **Security:** None
*   **Request Body:** `application/json`
    ```json
    {
        "email": "string (format: email)",
        "password": "string (min. 8 characters)"
    }
    ```
*   **Responses:**
    *   `201 Created`: `{"message": "Benutzer erfolgreich registriert."}`
    *   `400 Bad Request`: `{"error": "E-Mail ist bereits registriert."}` oder `{"error": "Passwort muss mindestens 8 Zeichen lang sein."}`
    *   `500 Internal Server Error`: `{"error": "Registrierung fehlgeschlagen"}`

#### `POST /api/password/request-reset`

*   **Summary:** Passwort-Reset anfordern
*   **Description:** Sendet eine E-Mail für das Zurücksetzen des Passworts.
*   **Method:** POST
*   **Security:** Rate Limited (Password Reset Limiter)
*   **Request Body:** `application/json`
    ```json
    {
        "email": "string (format: email)"
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"message": "Falls die E-Mail existiert, wurde eine Reset-E-Mail versendet."}` (Gibt immer 200 zurück, um E-Mail-Enumeration zu verhindern).
    *   `400 Bad Request`: `{"error": "E-Mail ist erforderlich."}`
    *   `429 Too Many Requests`: `{"error": "Zu viele Passwort-Reset-Anfragen. Bitte später erneut."}`

#### `POST /api/password/reset`

*   **Summary:** Passwort zurücksetzen
*   **Description:** Setzt das Passwort mithilfe des Tokens zurück.
*   **Method:** POST
*   **Security:** None
*   **Request Body:** `application/json`
    ```json
    {
        "token": "string",
        "newPassword": "string"
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"message": "Passwort erfolgreich zurückgesetzt."}`
    *   `400 Bad Request`: `{"error": "Ungültiger Reset-Token."}` oder `{"error": "Reset-Token ist abgelaufen."}` oder `{"error": "Passwort muss mindestens 8 Zeichen lang sein."}`
    *   `500 Internal Server Error`: `{"error": "Fehler beim Zurücksetzen des Passworts"}`

#### `POST /api/password/change`

*   **Summary:** Passwort ändern
*   **Description:** Ändert das Passwort des aktuell angemeldeten Benutzers.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "currentPassword": "string",
        "newPassword": "string"
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"message": "Passwort erfolgreich geändert."}`
    *   `400 Bad Request`: `{"error": "Das aktuelle Passwort ist ungültig."}` oder `{"error": "Neues Passwort muss sich vom alten unterscheiden."}` oder `{"error": "Neues Passwort muss mindestens 8 Zeichen lang sein."}`
    *   `401 Unauthorized`: `{"error": "Nicht authentifiziert."}`
    *   `500 Internal Server Error`: `{"error": "Fehler beim Ändern des Passworts"}`

#### `GET /api/connect/google`

*   **Summary:** Startet den Google OAuth Flow
*   **Description:** Leitet den Benutzer zur Google-Anmeldeseite weiter, um die Anwendung mit Google-Diensten zu verbinden (u.a. Calendar, Gmail).
*   **Method:** GET
*   **Security:** None (initial redirect)
*   **Responses:**
    *   `302 Redirect`: Weiterleitung zur Google-Anmeldeseite.

#### `GET /api/connect/google/check`

*   **Summary:** Google OAuth Callback (intern)
*   **Description:** Dieser Endpunkt wird von Google nach erfolgreicher Authentifizierung aufgerufen. Er ist nicht für den direkten Aufruf durch das Frontend gedacht.
*   **Method:** GET
*   **Security:** Internal
*   **Responses:**
    *   `302 Redirect`: Leitet den Benutzer zurück zur Frontend-Dashboard-URL (`https://127.0.0.1:3000/dashboard`), wobei die JWT-Tokens als HttpOnly Cookies gesetzt werden.
    *   `302 Redirect` (bei Fehler): Leitet zur Frontend-URL mit Fehlermeldung (`?error=oauth_failed...`).

### AI Agent - Personal Assistant

Base Path: `/api`
Tag: `AI Agent - Personal Assistant`

#### `POST /api/agent/personal`

*   **Summary:** Personal Assistant AI Agent (Async)
*   **Description:** Startet den Personal Assistant asynchron. Nutze `/api/agent/status/{sessionId}` für Updates.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json` (referenziert `AgentPromptRequest` Schema)
    ```json
    {
        "prompt": "string (min: 10, max: 20000)"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "queued",
            "sessionId": "string (UUID)",
            "message": "Job wurde erfolgreich zur Warteschlange hinzugefügt. Nutze /api/agent/status/{sessionId} für Updates.",
            "statusUrl": "/api/agent/status/{sessionId}"
        }
        ```
    *   `400 Bad Request`: `{"status": "error", "message": "Kein Prompt angegeben"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### Frontend Generator

Base Path: `/api`
Tag: `AI Agent - Personal Assistant` (Shared tag, could be separated)

#### `POST /api/agent/frontend`

*   **Summary:** Frontend Generator AI Agent (Async)
*   **Description:** Startet den Frontend Generator asynchron.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json` (referenziert `AgentPromptRequest` Schema)
    ```json
    {
        "prompt": "string (min: 10, max: 20000)"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "queued",
            "sessionId": "string (UUID)",
            "message": "Frontend Generator Job zur Warteschlange hinzugefügt.",
            "statusUrl": "/api/agent/status/{sessionId}"
        }
        ```
    *   `400 Bad Request`: `{"status": "error", "message": "Kein Prompt angegeben"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### System Health

Base Path: `/`
Tag: `System`

#### `GET /health`

*   **Summary:** Health Check Endpoint
*   **Description:** Prüft die Verfügbarkeit der API und Datenbankverbindung. Wird von Load Balancers und Monitoring-Tools verwendet.
*   **Method:** GET
*   **Security:** None
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "healthy",
            "timestamp": "string (format: date-time)",
            "database": "connected"
        }
        ```
    *   `503 Service Unavailable`:
        ```json
        {
            "status": "unhealthy",
            "timestamp": "string (format: date-time)",
            "database": "disconnected",
            "error": "string"
        }
        ```

### Knowledge Base (Admin)

Base Path: `/api`
Tag: `Knowledge Base`

#### `POST /api/index-knowledge`

*   **Summary:** Index Knowledge Base
*   **Description:** Triggert die Indizierung der Knowledge Base Dokumente in den Vektor-Speicher.
*   **Method:** POST
*   **Security:** Authenticated User (likely with admin role, though not explicitly checked in controller)
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "Knowledge base indexed successfully.", "details": "string"}`
    *   `200 OK` (with warnings): `{"status": "warning", "message": "Knowledge base indexing completed with warnings.", "details": "string"}`
    *   `500 Internal Server Error`: `{"error": "Knowledge base indexing failed.", "details": "string"}`

### Token Usage & Limits

Base Path: `/api/tokens`
Tag: `Token Usage & Limits`

#### `GET /api/tokens/limits`

*   **Summary:** Holt aktuelle Token-Limits
*   **Description:** Ruft die konfigurierten Token-Limits und die aktuelle Nutzung für den authentifizierten Benutzer ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "settings": {
                "minute_limit": "integer",
                "minute_limit_enabled": "boolean",
                "hour_limit": "integer",
                "hour_limit_enabled": "boolean",
                "day_limit": "integer",
                "day_limit_enabled": "boolean",
                "week_limit": "integer",
                "week_limit_enabled": "boolean",
                "month_limit": "integer",
                "month_limit_enabled": "boolean",
                "cost_per_million_tokens": "integer",
                "notify_on_limit_reached": "boolean",
                "warning_threshold_percent": "integer"
            },
            "current_usage": {
                "minute": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                "hour": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                "day": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                "week": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                "month": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" }
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `PUT /api/tokens/limits`

*   **Summary:** Aktualisiert Token-Limits
*   **Description:** Setzt oder aktualisiert die Token-Limits für den authentifizierten Benutzer.
*   **Method:** PUT
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "minute_limit": "integer | null",
        "minute_limit_enabled": "boolean",
        "hour_limit": "integer | null",
        "hour_limit_enabled": "boolean",
        "day_limit": "integer | null",
        "day_limit_enabled": "boolean",
        "week_limit": "integer | null",
        "week_limit_enabled": "boolean",
        "month_limit": "integer | null",
        "month_limit_enabled": "boolean",
        "cost_per_million_tokens": "integer",
        "notify_on_limit_reached": "boolean",
        "warning_threshold_percent": "integer"
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "Limits updated successfully", "settings": {"minute_limit": "integer", ...}}`
    *   `400 Bad Request`: `{"error": "Ungültige Eingabe"}` (inferred)
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/tokens/usage`

*   **Summary:** Holt Token-Nutzungsstatistiken
*   **Description:** Ruft detaillierte Token-Nutzungsstatistiken für einen bestimmten Zeitraum ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `period` (Query, `string`, Optional, Enum: `minute`, `hour`, `day`, `week`, `month`, `year`): Der gewünschte Zeitraum für die Statistiken.
    *   `start_date` (Query, `string`, Optional, `format: date-time`): Startdatum für einen benutzerdefinierten Zeitraum.
    *   `end_date` (Query, `string`, Optional, `format: date-time`): Enddatum für einen benutzerdefinierten Zeitraum.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "data": {
                "period": "string | null",
                "start_date": "string | null",
                "end_date": "string | null",
                "total_tokens": "integer",
                "total_input_tokens": "integer",
                "total_output_tokens": "integer",
                "total_cost_cents": "integer",
                "total_cost_dollars": "float",
                "by_model": [
                    {
                        "model": "string",
                        "agentType": "string",
                        "input_tokens": "integer",
                        "output_tokens": "integer",
                        "total_tokens": "integer",
                        "cost_cents": "integer",
                        "request_count": "integer",
                        "avg_response_time": "float"
                    }
                ],
                "limits": {
                    "minute": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                    // ... other periods
                },
                "usage_percent": {
                    "minute": "float",
                    // ... other periods
                }
            }
        }
        ```
    *   `400 Bad Request`: `{"error": "Failed to fetch statistics", "message": "string"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/tokens/usage/history`

*   **Summary:** Holt detaillierte Token-Usage-History
*   **Description:** Ruft die Token-Nutzungshistorie für eine bestimmte Anzahl von Tagen ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `days` (Query, `integer`, Optional, Default: 30): Anzahl der Tage, für die die Historie abgerufen werden soll.
    *   `model` (Query, `string`, Optional): Filtert nach LLM-Modell.
    *   `agent_type` (Query, `string`, Optional): Filtert nach Agententyp.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "period": "string",
            "start_date": "string (format: date-time)",
            "end_date": "string (format: date-time)",
            "data": {
                // Same structure as "data" in GET /api/tokens/usage
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/tokens/limits/check`

*   **Summary:** Prüft ob User noch Token-Kapazität hat
*   **Description:** Überprüft, ob der Benutzer noch genügend Token-Kapazität für eine geschätzte Anzahl von Tokens hat, basierend auf den konfigurierten Limits.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `estimated_tokens` (Query, `integer`, Optional, Default: 0): Die geschätzte Anzahl von Tokens für die nächste Operation.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "allowed": true,
            "estimated_tokens": "integer",
            "current_limits": {
                "minute": { "limit": "integer", "used": "integer", "remaining": "integer", "percent": "float" },
                // ... other periods
            }
        }
        ```
    *   `429 Too Many Requests`: `{"allowed": false, "message": "string", "estimated_tokens": "integer"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/tokens/limits/reset`

*   **Summary:** Setzt Token-Limits auf Standardwerte zurück
*   **Description:** Setzt die LLM-Token-Limits des Benutzers auf die vordefinierten Standardwerte zurück. (Wahrscheinlich nur für Admins oder bestimmte Rollen gedacht, im Code aber nur Authentifizierung geprüft).
*   **Method:** POST
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "Limits reset to defaults", "settings": {"minute_limit": "integer", ...}}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### User

Base Path: `/api`
Tag: `User`

#### `GET /api/user`

*   **Summary:** Get current authenticated user
*   **Description:** Returns the currently authenticated user's data.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "user": {
                "id": "integer",
                "email": "string",
                "name": "string | null",
                "roles": ["string"]
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `PUT /api/user`

*   **Summary:** Update current user profile
*   **Description:** Updates the current user's profile information. (Currently only `name` is shown as updateable, but logic is generic).
*   **Method:** PUT
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "name": "string"
        // Other fields like avatarUrl could be added here if updateable
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "message": "Profile updated successfully",
            "user": {
                "id": "integer",
                "email": "string"
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### User Documents

Base Path: `/api/documents`
Tag: `User Documents`

#### `GET /api/documents`

*   **Summary:** Liste aller hochgeladenen Dokumente
*   **Description:** Listet alle Dokumente des aktuell authentifizierten Benutzers auf.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `category` (Query, `string`, Optional, Enum: `template`, `attachment`, `reference`, `media`, `other`): Filtert Dokumente nach Kategorie.
    *   `type` (Query, `string`, Optional, Enum: `pdf`, `document`, `spreadsheet`, `image`, `text`, `other`): Filtert Dokumente nach Dateityp.
    *   `limit` (Query, `integer`, Optional, Default: 50, Max: 100): Maximale Anzahl der zurückgegebenen Dokumente.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "count": "integer",
            "documents": [
                {
                    "id": "integer",
                    "name": "string",
                    "original_filename": "string",
                    "type": "string",
                    "mime_type": "string",
                    "category": "string",
                    "size": "string (human-readable size)",
                    "size_bytes": "integer",
                    "tags": ["string"] | null,
                    "is_indexed": "boolean",
                    "created_at": "string (format: date-time)"
                }
            ]
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/documents`

*   **Summary:** Dokument hochladen
*   **Description:** Lädt eine Datei als Benutzerdokument hoch. Unterstützt `multipart/form-data`.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `multipart/form-data`
    *   `file` (File, **Required**): Die hochzuladende Datei.
    *   `category` (Form Data, `string`, Optional, Enum: `template`, `attachment`, `reference`, `media`, `other`, Default: `other`): Kategorie des Dokuments.
    *   `display_name` (Form Data, `string`, Optional): Ein benutzerfreundlicher Name für das Dokument.
    *   `description` (Form Data, `string`, Optional): Eine Beschreibung des Dokuments.
    *   `tags` (Form Data, `string`, Optional): Komma-getrennte Tags für das Dokument.
    *   `index_to_knowledge` (Form Data, `boolean`, Optional): Ob der extrahierte Text in die Knowledge Base indiziert werden soll.
*   **Responses:**
    *   `201 Created`:
        ```json
        {
            "status": "success",
            "message": "Document uploaded successfully",
            "document": {
                "id": "integer",
                "name": "string",
                "original_filename": "string",
                "type": "string",
                "mime_type": "string",
                "category": "string",
                "size": "string (human-readable size)",
                "size_bytes": "integer",
                "tags": ["string"] | null,
                "is_indexed": "boolean",
                "created_at": "string (format: date-time)",
                "description": "string | null",
                "metadata": "object",
                "has_extracted_text": "boolean",
                "access_count": "integer",
                "last_accessed": "string | null",
                "updated_at": "string (format: date-time)"
            }
        }
        ```
    *   `400 Bad Request`: `{"error": "string"}` (z.B. "No file uploaded", "Datei zu groß", "Dateityp nicht erlaubt", "Dieses Dokument existiert bereits").
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/documents/search`

*   **Summary:** Dokumente durchsuchen
*   **Description:** Sucht Dokumente des Benutzers anhand eines Suchbegriffs in Name, Beschreibung und extrahiertem Text.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `q` (Query, `string`, **Required**): Der Suchbegriff.
    *   `limit` (Query, `integer`, Optional, Default: 20, Max: 50): Maximale Anzahl der Suchergebnisse.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "query": "string",
            "count": "integer",
            "documents": [
                {
                    "id": "integer",
                    "name": "string",
                    "original_filename": "string",
                    "type": "string",
                    "mime_type": "string",
                    "category": "string",
                    "size": "string (human-readable size)",
                    "size_bytes": "integer",
                    "tags": ["string"] | null,
                    "is_indexed": "boolean",
                    "created_at": "string (format: date-time)"
                }
            ]
        }
        ```
    *   `400 Bad Request`: `{"error": "Search query required"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/documents/{id}`

*   **Summary:** Dokument-Details abrufen
*   **Description:** Ruft detaillierte Informationen zu einem spezifischen Benutzerdokument ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `integer`, **Required**): Die ID des Dokuments.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "document": {
                "id": "integer",
                "name": "string",
                "original_filename": "string",
                "type": "string",
                "mime_type": "string",
                "category": "string",
                "size": "string (human-readable size)",
                "size_bytes": "integer",
                "tags": ["string"] | null,
                "is_indexed": "boolean",
                "created_at": "string (format: date-time)",
                "description": "string | null",
                "metadata": "object",
                "has_extracted_text": "boolean",
                "access_count": "integer",
                "last_accessed": "string | null",
                "updated_at": "string (format: date-time)"
            }
        }
        ```
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/documents/{id}/download`

*   **Summary:** Dokument herunterladen
*   **Description:** Ermöglicht den Download eines spezifischen Benutzerdokuments.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `integer`, **Required**): Die ID des Dokuments.
*   **Responses:**
    *   `200 OK`: Dateibinary (HTTP Response mit `Content-Disposition: attachment`).
    *   `404 Not Found`: `{"error": "Document not found"}` oder `{"error": "File not found on disk"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `PUT /api/documents/{id}`

*   **Summary:** Dokument-Metadaten aktualisieren
*   **Description:** Aktualisiert die Metadaten (Name, Beschreibung, Kategorie, Tags) eines Benutzerdokuments.
*   **Method:** PUT
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `integer`, **Required**): Die ID des Dokuments.
*   **Request Body:** `application/json`
    ```json
    {
        "display_name": "string | null",
        "description": "string | null",
        "category": "string | null",
        "tags": ["string"] | null
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "message": "Document updated",
            "document": {
                "id": "integer",
                "name": "string",
                "original_filename": "string",
                "type": "string",
                "mime_type": "string",
                "category": "string",
                "size": "string (human-readable size)",
                "size_bytes": "integer",
                "tags": ["string"] | null,
                "is_indexed": "boolean",
                "created_at": "string (format: date-time)",
                "description": "string | null",
                "metadata": "object",
                "has_extracted_text": "boolean",
                "access_count": "integer",
                "last_accessed": "string | null",
                "updated_at": "string (format: date-time)"
            }
        }
        ```
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `DELETE /api/documents/{id}`

*   **Summary:** Dokument löschen
*   **Description:** Löscht ein spezifisches Benutzerdokument und die zugehörige Datei vom Speicher.
*   **Method:** DELETE
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `integer`, **Required**): Die ID des Dokuments.
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "Document deleted"}`
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/documents/{id}/index`

*   **Summary:** Dokument in Knowledge Base indizieren
*   **Description:** Indiziert den extrahierten Text eines Dokuments in die persönliche Knowledge Base des Benutzers.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `integer`, **Required**): Die ID des Dokuments.
*   **Request Body:** `application/json`
    ```json
    {
        "tags": ["string"] | null
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "string", "chunks_created": "integer"}`
    *   `400 Bad Request`: `{"error": "Document has no extractable text for indexing"}`
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Indexing failed: string"}`

#### `GET /api/documents/storage/stats`

*   **Summary:** Speicherstatistiken abrufen
*   **Description:** Ruft Statistiken über den von einem Benutzer verwendeten Speicherplatz für Dokumente ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "storage": {
                "used_bytes": "integer",
                "used_human": "string",
                "limit_bytes": "integer",
                "limit_human": "string",
                "available_bytes": "integer",
                "usage_percent": "float",
                "document_count": "integer"
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### User Knowledge Base

Base Path: `/api/knowledge`
Tag: `User Knowledge Base`

#### `GET /api/knowledge`

*   **Summary:** Liste aller persönlichen Wissensdokumente
*   **Description:** Listet alle Wissensdokumente des authentifizierten Benutzers auf.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "count": "integer",
            "documents": [
                {
                    "id": "string (UUID)",
                    "title": "string",
                    "source_type": "string",
                    "source_reference": "string | null",
                    "tags": ["string"] | null,
                    "created_at": "string (format: date-time)",
                    "updated_at": "string (format: date-time)"
                }
            ]
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/knowledge`

*   **Summary:** Neues Wissensdokument erstellen
*   **Description:** Erstellt ein neues manuelles Wissensdokument für den authentifizierten Benutzer.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "title": "string",
        "content": "string",
        "tags": ["string"] | null
    }
    ```
*   **Responses:**
    *   `201 Created`:
        ```json
        {
            "status": "success",
            "message": "Knowledge document created",
            "document": {
                "id": "string (UUID)",
                "title": "string",
                "source_type": "string",
                "source_reference": "string | null",
                "tags": ["string"] | null,
                "created_at": "string (format: date-time)",
                "updated_at": "string (format: date-time)",
                "content": "string",
                "metadata": "object"
            }
        }
        ```
    *   `400 Bad Request`: `{"error": "Title and content are required"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Failed to create document: string"}`

#### `POST /api/knowledge/search`

*   **Summary:** Semantische Suche in der Knowledge Base
*   **Description:** Führt eine semantische Ähnlichkeitssuche in der persönlichen Knowledge Base des Benutzers durch.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "query": "string",
        "limit": "integer (default: 5, max: 20)",
        "min_score": "float (default: 0.3, min: 0.0, max: 1.0)"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "query": "string",
            "count": "integer",
            "results": [
                {
                    "document": {
                        "id": "string (UUID)",
                        "title": "string",
                        "source_type": "string",
                        "source_reference": "string | null",
                        "tags": ["string"] | null,
                        "created_at": "string (format: date-time)",
                        "updated_at": "string (format: date-time)"
                    },
                    "score": "float"
                }
            ]
        }
        ```
    *   `400 Bad Request`: `{"error": "Query is required"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Search failed: string"}`

#### `GET /api/knowledge/{id}`

*   **Summary:** Einzelnes Wissensdokument abrufen
*   **Description:** Ruft ein spezifisches Wissensdokument des Benutzers ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `string` (UUID), **Required**): Die ID des Wissensdokuments.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "document": {
                "id": "string (UUID)",
                "title": "string",
                "source_type": "string",
                "source_reference": "string | null",
                "tags": ["string"] | null,
                "created_at": "string (format: date-time)",
                "updated_at": "string (format: date-time)",
                "content": "string",
                "metadata": "object"
            }
        }
        ```
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `PUT /api/knowledge/{id}`

*   **Summary:** Wissensdokument aktualisieren
*   **Description:** Aktualisiert Titel, Inhalt und Tags eines Wissensdokuments.
*   **Method:** PUT
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `string` (UUID), **Required**): Die ID des Wissensdokuments.
*   **Request Body:** `application/json`
    ```json
    {
        "title": "string | null",
        "content": "string | null",
        "tags": ["string"] | null
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "message": "Document updated",
            "document": {
                "id": "string (UUID)",
                "title": "string",
                "source_type": "string",
                "source_reference": "string | null",
                "tags": ["string"] | null,
                "created_at": "string (format: date-time)",
                "updated_at": "string (format: date-time)",
                "content": "string",
                "metadata": "object"
            }
        }
        ```
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Update failed: string"}`

#### `DELETE /api/knowledge/{id}`

*   **Summary:** Wissensdokument löschen
*   **Description:** Löscht ein spezifisches Wissensdokument (Soft-Delete: `isActive` wird auf `false` gesetzt).
*   **Method:** DELETE
*   **Security:** Authenticated User
*   **Parameters:**
    *   `id` (Path, `string` (UUID), **Required**): Die ID des Wissensdokuments.
*   **Responses:**
    *   `200 OK`: `{"status": "success", "message": "Document deleted"}`
    *   `404 Not Found`: `{"error": "Document not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/knowledge/stats`

*   **Summary:** Statistiken zur persönlichen Knowledge Base
*   **Description:** Ruft Statistiken über die persönliche Knowledge Base des Benutzers ab.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "stats": {
                "total_documents": "integer",
                "by_source_type": {
                    "manual": "integer",
                    "uploaded_file": "integer",
                    "url": "integer",
                    "api": "integer"
                }
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### User Settings

Base Path: `/api/user/settings`
Tag: `User Settings`

#### `GET /api/user/settings`

*   **Summary:** Get current user's mail settings
*   **Description:** Ruft die E-Mail-Einstellungen des aktuell authentifizierten Benutzers ab. Wenn keine Einstellungen vorhanden sind, werden Standardwerte zurückgegeben.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "id": "integer",
            "emailAddress": "string | null",
            "pop3": {
                "host": "string | null",
                "port": "integer | null",
                "encryption": "string | null"
            },
            "imap": {
                "host": "string | null",
                "port": "integer | null",
                "encryption": "string | null"
            },
            "smtp": {
                "host": "string | null",
                "port": "integer | null",
                "encryption": "string | null",
                "username": "string | null",
                "password": "string | null"
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `PUT /api/user/settings`

*   **Summary:** Update user mail settings
*   **Description:** Aktualisiert die E-Mail-Einstellungen des aktuell authentifizierten Benutzers.
*   **Method:** PUT
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "pop3Host": "string | null",
        "pop3Port": "integer | null",
        "pop3Encryption": "string | null",
        "imapHost": "string | null",
        "imapPort": "integer | null",
        "imapEncryption": "string | null",
        "smtpHost": "string | null",
        "smtpPort": "integer | null",
        "smtpEncryption": "string | null",
        "smtpUsername": "string | null",
        "smtpPassword": "string | null",
        "emailAddress": "string | null"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "message": "Settings updated",
            "settings": {
                "id": "integer",
                "emailAddress": "string | null",
                "pop3": { /* ... */ },
                "imap": { /* ... */ },
                "smtp": { /* ... */ }
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

### Workflow Management

Base Path: `/api/workflow`
Tag: `Workflow Management`

#### `POST /api/workflow/create`

*   **Summary:** Erstellt Workflow aus User-Intent
*   **Description:** Der Personal Assistant analysiert den Intent, prüft Tool-Verfügbarkeit, plant den Workflow und startet die Ausführung.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Request Body:** `application/json`
    ```json
    {
        "intent": "string",
        "sessionId": "string"
    }
    ```
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "created",
            "workflow_id": "integer",
            "session_id": "string",
            "steps_count": "integer",
            "missing_tools": ["string"],
            "message": "Workflow erstellt und wird ausgeführt."
        }
        ```
    *   `400 Bad Request`: `{"error": "Intent and sessionId are required"}`
    *   `401 Unauthorized`: `{"error": "Authentication required", "message": "User must be logged in to create a workflow."}`
    *   `500 Internal Server Error`: `{"error": "Workflow creation failed", "message": "string"}`

#### `DELETE /api/workflow/{workflowId}`

*   **Summary:** Löscht einen Workflow
*   **Description:** Löscht einen Workflow anhand seiner ID.
*   **Method:** DELETE
*   **Security:** Authenticated User (implied, though not explicit role check)
*   **Parameters:**
    *   `workflowId` (Path, `integer`, **Required**): ID des zu löschenden Workflows.
*   **Responses:**
    *   `200 OK`: `{"status": "deleted", "workflow_id": "integer", "message": "string"}`
    *   `404 Not Found`: `{"error": "Workflow not found", "workflow_id": "integer"}`
    *   `500 Internal Server Error`: `{"error": "Failed to delete workflow", "message": "string"}`

#### `GET /api/workflow/status/{sessionId}`

*   **Summary:** Holt Workflow-Status mit E-Mail-Details
*   **Description:** Ruft den aktuellen Status und alle Schritte eines Workflows ab, inklusive Details zu ausstehenden E-Mails.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `sessionId` (Path, `string`, **Required**): Die Session-ID des Workflows.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "workflow_id": "integer",
            "session_id": "string",
            "status": "string", // created, running, waiting_confirmation, completed, failed, cancelled
            "current_step": "integer | null",
            "total_steps": "integer",
            "steps": [
                {
                    "step_number": "integer",
                    "type": "string", // tool_call, analysis, decision, notification
                    "description": "string",
                    "status": "string", // pending, completed, failed, cancelled, pending_confirmation, rejected
                    "requires_confirmation": "boolean",
                    "result": "object | null",
                    "error": "string | null",
                    "email_details": { // Only if step is an email with pending confirmation
                        "recipient": "string",
                        "subject": "string",
                        "body": "string",
                        "body_preview": "string",
                        "body_length": "integer",
                        "attachments": [
                            {
                                "id": "integer",
                                "filename": "string",
                                "size": "integer",
                                "size_human": "string",
                                "mime_type": "string",
                                "download_url": "string"
                            }
                        ],
                        "attachment_count": "integer",
                        "ready_to_send": "boolean",
                        "created_at": "string (format: date-time)"
                    }
                }
            ],
            "created_at": "string (format: date-time)",
            "completed_at": "string (format: date-time) | null"
        }
        ```
    *   `404 Not Found`: `{"error": "Workflow not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/workflow/step/{stepId}/email-body`

*   **Summary:** Holt den vollständigen E-Mail-Body eines Steps
*   **Description:** Ruft die vollständigen E-Mail-Details (inkl. Body) eines Workflow-Schritts ab, falls es sich um einen E-Mail-Schritt handelt.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "step_id": "integer",
            "recipient": "string",
            "subject": "string",
            "body": "string",
            "body_html": "string",
            "attachments": [
                 {
                    "id": "integer",
                    "filename": "string",
                    "size": "integer",
                    "size_human": "string",
                    "mime_type": "string",
                    "download_url": "string"
                }
            ]
        }
        ```
    *   `404 Not Found`: `{"error": "Step not found"}` oder `{"error": "No email details available"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/workflow/step/{stepId}/attachment/{attachmentId}/preview`

*   **Summary:** Zeigt einen E-Mail-Anhang zur Vorschau
*   **Description:** Stellt den Inhalt eines E-Mail-Anhangs aus einem Workflow-Schritt zur Vorschau bereit (z.B. Bilder, PDFs).
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
    *   `attachmentId` (Path, `integer`, **Required**): Die ID des Anhangs (UserDocument ID).
*   **Responses:**
    *   `200 OK`: Dateibinary des Anhangs.
    *   `404 Not Found`: Fehler, wenn Step oder Anhang nicht gefunden.
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/workflow/confirm/{workflowId}`

*   **Summary:** Bestätigt oder lehnt einen wartenden Workflow-Step ab
*   **Description:** Bestätigt die Ausführung des aktuellen Steps eines Workflows, der auf Bestätigung wartet (z.B. E-Mail-Versand). Bei Ablehnung wird der Workflow abgebrochen.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Parameters:**
    *   `workflowId` (Path, `integer`, **Required**): Die ID des Workflows.
*   **Request Body:** `application/json`
    ```json
    {
        "confirmed": "boolean"
    }
    ```
*   **Responses:**
    *   `200 OK`: `{"status": "success", "confirmed": "boolean", "workflow_status": "string"}`
    *   `400 Bad Request`: `{"error": "Workflow is not waiting for confirmation"}` oder `{"error": "confirmed parameter is required"}`
    *   `404 Not Found`: `{"error": "Workflow not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Confirmation failed", "message": "string"}`

#### `GET /api/workflow/list`

*   **Summary:** Listet Workflows auf
*   **Description:** Listet alle Workflows des authentifizierten Benutzers auf.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `status` (Query, `string`, Optional): Filtert Workflows nach Status (z.B. `running`, `completed`, `failed`, `waiting_confirmation`).
    *   `limit` (Query, `integer`, Optional, Default: 20): Maximale Anzahl der zurückgegebenen Workflows.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "workflows": [
                {
                    "id": "integer",
                    "session_id": "string",
                    "user_intent": "string (truncated)",
                    "status": "string",
                    "steps_count": "integer",
                    "current_step": "integer | null",
                    "created_at": "string (format: date-time)",
                    "completed_at": "string (format: date-time) | null"
                }
            ],
            "count": "integer"
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/workflow/capabilities`

*   **Summary:** Listet verfügbare Tools und Capabilities
*   **Description:** Gibt eine Liste der vom System unterstützten AI-Tools und deren Kategorien zurück.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "tools": ["string"], // Array von Tool-Namen
            "tools_count": "integer",
            "capabilities": {
                "apartment_search": "boolean",
                "calendar_management": "boolean",
                "email_sending": "boolean",
                "web_scraping": "boolean",
                "pdf_generation": "boolean",
                "api_calling": "boolean"
            }
        }
        ```
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/workflow/{workflowId}/pending-emails`

*   **Summary:** Holt alle ausstehenden E-Mails eines Workflows
*   **Description:** Ruft eine Liste aller E-Mails ab, die im Rahmen eines Workflows vorbereitet wurden und auf Bestätigung warten.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `workflowId` (Path, `integer`, **Required**): Die ID des Workflows.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "workflow_id": "integer",
            "workflow_status": "string",
            "pending_count": "integer",
            "pending_emails": [
                {
                    "step_id": "integer",
                    "step_number": "integer",
                    "recipient": "string",
                    "subject": "string",
                    "body_preview": "string",
                    "attachment_count": "integer",
                    "created_at": "string (format: date-time)",
                    "status": "string" // e.g., "pending"
                }
            ]
        }
        ```
    *   `404 Not Found`: `{"error": "Workflow not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `GET /api/workflow/step/{stepId}/email`

*   **Summary:** Holt vollständige E-Mail-Details eines Steps
*   **Description:** Ruft die vollständigen Details einer spezifischen E-Mail ab, die zu einem Workflow-Schritt gehört, inklusive Attachment-Download-URLs.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "step_id": "integer",
            "step_number": "integer",
            "status": "string", // e.g., "pending_confirmation"
            "email_details": {
                "recipient": "string",
                "subject": "string",
                "body": "string",
                "body_preview": "string",
                "body_length": "integer",
                "attachments": [
                    {
                        "id": "integer",
                        "filename": "string",
                        "size": "integer",
                        "size_human": "string",
                        "mime_type": "string",
                        "preview_url": "string",
                        "download_url": "string"
                    }
                ],
                "attachment_count": "integer",
                "ready_to_send": "boolean",
                "created_at": "string (format: date-time)",
                "_original_params": "object" // Original parameters passed to the tool
            },
            "can_send": "boolean",
            "can_reject": "boolean"
        }
        ```
    *   `404 Not Found`: `{"error": "Step not found"}` oder `{"error": "No email details available"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

#### `POST /api/workflow/step/{stepId}/send-email`

*   **Summary:** Sendet eine ausstehende E-Mail
*   **Description:** Bestätigt und sendet eine E-Mail, die in einem Workflow-Schritt auf Bestätigung wartet.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "message": "Email sent successfully",
            "step_id": "integer",
            "step_status": "string", // e.g., "completed"
            "workflow_status": "string", // e.g., "running"
            "sent_at": "string (format: date-time)"
        }
        ```
    *   `400 Bad Request`: `{"error": "Email is not pending confirmation"}`
    *   `404 Not Found`: `{"error": "Step not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Failed to send email", "message": "string"}`

#### `POST /api/workflow/step/{stepId}/reject-email`

*   **Summary:** Lehnt eine ausstehende E-Mail ab
*   **Description:** Lehnt eine E-Mail ab, die in einem Workflow-Schritt auf Bestätigung wartet. Dies führt zum Abbruch des Workflows.
*   **Method:** POST
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
*   **Responses:**
    *   `200 OK`:
        ```json
        {
            "status": "success",
            "message": "Email rejected",
            "step_id": "integer",
            "step_status": "string", // e.g., "rejected"
            "workflow_status": "string" // e.g., "cancelled"
        }
        ```
    *   `400 Bad Request`: `{"error": "Email is not pending confirmation"}`
    *   `404 Not Found`: `{"error": "Step not found"}`
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`
    *   `500 Internal Server Error`: `{"error": "Failed to reject email", "message": "string"}`

#### `GET /api/workflow/step/{stepId}/attachment/{attachmentId}/download`

*   **Summary:** Download eines Anhangs
*   **Description:** Ermöglicht den Download eines spezifischen E-Mail-Anhangs aus einem Workflow-Schritt.
*   **Method:** GET
*   **Security:** Authenticated User
*   **Parameters:**
    *   `stepId` (Path, `integer`, **Required**): Die ID des Workflow-Schritts.
    *   `attachmentId` (Path, `integer`, **Required**): Die ID des Anhangs (UserDocument ID).
*   **Responses:**
    *   `200 OK`: Dateibinary des Anhangs.
    *   `404 Not Found`: Fehler, wenn Step, Dokument oder Datei nicht gefunden.
    *   `401 Unauthorized`: `{"error": "Not authenticated"}`

---

## 4. Datenmodelle (DTOs)

### `AgentPromptRequest`

Wird für Anfragen an die AI-Agenten verwendet.

```json
{
    "prompt": "string (required, minLength: 10, maxLength: 20000)"
}
```

### `RegisterRequestDTO`

Wird für die Benutzerregistrierung verwendet.

```json
{
    "email": "string (required)",
    "password": "string (required)"
}
```
