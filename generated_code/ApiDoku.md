# API Dokumentation für Frontend-Entwickler

## Einleitung

Dieses Dokument beschreibt die RESTful API des Projekts und richtet sich an Frontend-Entwickler, die mit den Backend-Diensten interagieren. Es deckt Authentifizierungsmechanismen, verfügbare Endpunkte, das Messaging-System und wichtige Sicherheitsaspekte ab.

Das Projekt ist ein **Symfony AI Agent**, der komplexe Aufgaben über eine Reihe von spezialisierten AI-Agenten und Tools ausführen kann. Es bietet Funktionalitäten wie automatisierte Code-Generierung, Workflow-Management, E-Mail-Integration, Kalenderverwaltung und Wissensdatenbanksuche.

## 1. Authentifizierung

Das System unterstützt zwei primäre Authentifizierungsmethoden: Standard-E-Mail/Passwort und Google OAuth2. Beide Methoden nutzen JWT (JSON Web Tokens) für den Zugriff und Refresh Tokens für die Sitzungsverwaltung.

### 1.1 Standard-Authentifizierung (E-Mail/Passwort)

Benutzer können sich mit ihrer E-Mail-Adresse und einem Passwort authentifizieren.

**Endpunkte:**

*   **`POST /api/register`**
    *   **Beschreibung:** Registriert einen neuen Benutzer.
    *   **Request Body (JSON):**
        ```json
        {
          "email": "user@example.com",
          "password": "yourStrongPassword123"
        }
        ```
        *   `email` (string, required): Eindeutige E-Mail-Adresse des Benutzers.
        *   `password` (string, required): Das Passwort. Muss mindestens 8 Zeichen lang sein.
    *   **Responses:**
        *   `201 Created`:
            ```json
            {
              "message": "Benutzer erfolgreich registriert."
            }
            ```
        *   `400 Bad Request`: Bei fehlenden Feldern, ungültigem Passwort oder bereits registrierter E-Mail.
        *   `500 Internal Server Error`: Bei unerwarteten Serverfehlern.

*   **`POST /api/login`**
    *   **Beschreibung:** Authentifiziert einen Benutzer und gibt JWT Access- und Refresh-Token als HTTP-Only Cookies zurück.
    *   **Request Body (JSON):**
        ```json
        {
          "email": "user@example.com",
          "password": "yourStrongPassword123"
        }
        ```
        *   `email` (string, required): E-Mail-Adresse des Benutzers.
        *   `password` (string, required): Passwort des Benutzers.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Login erfolgreich.",
              "user": {
                "email": "user@example.com"
              }
            }
            ```
            *   **Cookies:** `BEARER` (Access Token, HttpOnly, Secure, SameSite=Lax), `refresh_token` (Refresh Token, HttpOnly, Secure, SameSite=Lax).
        *   `401 Unauthorized`: Ungültige Anmeldedaten.
        *   `429 Too Many Requests`: Zu viele Login-Versuche (Rate Limiting aktiv).

*   **`POST /api/logout`**
    *   **Beschreibung:** Meldet den Benutzer ab, indem die `BEARER` und `refresh_token` Cookies gelöscht werden.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Logout erfolgreich."
            }
            ```

*   **`POST /api/password/request-reset`**
    *   **Beschreibung:** Fordert eine E-Mail zum Zurücksetzen des Passworts an. Es wird eine E-Mail an die angegebene Adresse gesendet, wenn diese in der Datenbank existiert.
    *   **Request Body (JSON):**
        ```json
        {
          "email": "user@example.com"
        }
        ```
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Falls die E-Mail existiert, wurde eine Reset-E-Mail versendet."
            }
            ```
            *   **Hinweis:** Aus Sicherheitsgründen wird immer die gleiche Nachricht zurückgegeben, unabhängig davon, ob die E-Mail in der Datenbank gefunden wurde oder nicht, um Enumerationsangriffe zu verhindern.
        *   `400 Bad Request`: E-Mail fehlt.
        *   `429 Too Many Requests`: Zu viele Anfragen zum Zurücksetzen des Passworts (Rate Limiting aktiv).

*   **`POST /api/password/reset`**
    *   **Beschreibung:** Setzt das Passwort eines Benutzers mit einem gültigen Reset-Token zurück, der per E-Mail gesendet wurde.
    *   **Request Body (JSON):**
        ```json
        {
          "token": "generated_reset_token",
          "newPassword": "yourNewStrongPassword123"
        }
        ```
        *   `token` (string, required): Der Reset-Token aus der E-Mail.
        *   `newPassword` (string, required): Das neue Passwort. Muss mindestens 8 Zeichen lang sein.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Passwort erfolgreich zurückgesetzt."
            }
            ```
        *   `400 Bad Request`: Ungültiger Token, abgelaufener Token oder zu kurzes Passwort.
        *   `500 Internal Server Error`: Bei unerwarteten Serverfehlern.

*   **`POST /api/password/change`**
    *   **Beschreibung:** Ändert das Passwort des aktuell authentifizierten Benutzers.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "currentPassword": "yourCurrentPassword",
          "newPassword": "yourNewStrongPassword123"
        }
        ```
        *   `currentPassword` (string, required): Das aktuelle Passwort des Benutzers.
        *   `newPassword` (string, required): Das neue Passwort. Muss mindestens 8 Zeichen lang sein und sich vom alten unterscheiden.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Passwort erfolgreich geändert."
            }
            ```
        *   `400 Bad Request`: Ungültiges aktuelles Passwort oder ungültiges neues Passwort.
        *   `401 Unauthorized`: Benutzer nicht authentifiziert.
        *   `500 Internal Server Error`: Bei unerwarteten Serverfehlern.

### 1.2 Google OAuth2 Authentifizierung

Die Google OAuth2 Integration ermöglicht es Benutzern, sich über ihr Google-Konto anzumelden und der Anwendung Zugriff auf Google-Dienste (wie Kalender und Gmail) zu gewähren.

**Endpunkte:**

*   **`GET /api/connect/google`**
    *   **Beschreibung:** Leitet den Benutzer zur Google-Anmeldeseite weiter, um die OAuth2-Zustimmung einzuholen.
    *   **Authentifizierung:** Nicht erforderlich.
    *   **Scopes:**
        *   `email`
        *   `profile`
        *   `https://www.googleapis.com/auth/calendar` (für Kalender-Events)
        *   `https://www.googleapis.com/auth/gmail.modify` (für Gmail senden/ändern/lesen)
    *   **Parameter:** `prompt=consent`, `access_type=offline` (wichtig für den Erhalt von Refresh Tokens).
    *   **Responses:**
        *   `302 Found`: Leitet zum Google OAuth-Server weiter.

*   **`GET /api/connect/google/check`**
    *   **Beschreibung:** Dies ist der Callback-Endpunkt, zu dem Google den Benutzer nach erfolgreicher Authentifizierung zurückleitet.
    *   **Authentifizierung:** Nicht erforderlich (wird intern vom OAuth2-Authenticator verarbeitet).
    *   **Responses:**
        *   `302 Found`: Bei Erfolg leitet es zum Frontend-Dashboard (`http://127.0.0.1:3000/dashboard`) weiter und setzt die `BEARER` und `refresh_token` Cookies.
        *   `302 Found`: Bei Fehlern (z.B. fehlendem Refresh Token) leitet es zur Login-Seite des Frontends mit Fehlermeldung (`http://127.0.0.1:3000/login?error=oauth_failed&hint=reconsent`) weiter.

### 1.3 JWT & Refresh Token Handling

*   **Access Token (`BEARER` Cookie):**
    *   Kurzzeitig gültig (z.B. 1 Stunde).
    *   Wird bei jeder Anfrage im `Authorization: Bearer <token>` Header (oder direkt über das Cookie, wie hier implementiert) gesendet.
    *   Speicherung im Browser als `HttpOnly` Cookie, um XSS-Angriffe zu verhindern.
*   **Refresh Token (`refresh_token` Cookie):**
    *   Länger gültig (z.B. 7 Tage).
    *   Wird verwendet, um ein neues Access Token anzufordern, wenn das aktuelle Access Token abgelaufen ist, ohne dass der Benutzer sich erneut anmelden muss.
    *   Speicherung im Browser als `HttpOnly` Cookie.
    *   **Hinweis:** Das Refresh Token wird automatisch im Hintergrund aktualisiert, wenn ein JWT-Access Token abläuft und der Refresh Token noch gültig ist. Der Frontend-Entwickler muss sich darum in der Regel nicht direkt kümmern, solange die Cookies korrekt mitgesendet werden.

### 1.4 Cookie-Sicherheit

Alle Authentifizierungs-Cookies (`BEARER`, `refresh_token`) werden mit folgenden Attributen gesetzt, um die Sicherheit zu maximieren:

*   **`HttpOnly`:** Verhindert den Zugriff über JavaScript, was XSS-Angriffe auf die Tokens erschwert.
*   **`Secure`:** Stellt sicher, dass das Cookie nur über HTTPS gesendet wird.
*   **`SameSite=Lax` oder `SameSite=None`:**
    *   `Lax` (für Standard-Login/Logout): Schützt vor CSRF-Angriffen, indem das Cookie nur bei Top-Level-Navigationen oder GET-Anfragen von Drittanbietern gesendet wird.
    *   `None` (für Google OAuth Callback): Erforderlich für Cross-Site-Anfragen, muss aber mit `Secure` kombiniert werden.
*   **`Expires`:** Legt die Gültigkeitsdauer des Cookies fest.

## 2. API Endpunkte

Alle Endpunkte befinden sich unter dem Präfix `/api`.

### 2.1 Auth & User Management

*   **`GET /api/user`**
    *   **Beschreibung:** Ruft die Profildaten des aktuell authentifizierten Benutzers ab.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "user": {
                "id": 1,
                "email": "user@example.com",
                "name": "John Doe",
                "roles": ["ROLE_USER"]
              }
            }
            ```
        *   `401 Unauthorized`: Benutzer nicht authentifiziert.

*   **`PUT /api/user`**
    *   **Beschreibung:** Aktualisiert die Profildaten des aktuell authentifizierten Benutzers.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "name": "Jane Doe"
        }
        ```
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Profile updated successfully",
              "user": {
                "id": 1,
                "email": "user@example.com"
              }
            }
            ```
        *   `401 Unauthorized`: Benutzer nicht authentifiziert.

*   **`GET /api/user/settings`**
    *   **Beschreibung:** Ruft die E-Mail-Einstellungen des aktuell authentifizierten Benutzers ab (IMAP, POP3, SMTP).
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "id": 1,
              "emailAddress": "my.email@example.com",
              "pop3": {
                "host": "pop3.example.com",
                "port": 995,
                "encryption": "ssl"
              },
              "imap": {
                "host": "imap.example.com",
                "port": 993,
                "encryption": "ssl"
              },
              "smtp": {
                "host": "smtp.example.com",
                "port": 587,
                "encryption": "tls",
                "username": "my.email@example.com",
                "password": "encrypted_password"
              }
            }
            ```
        *   `401 Unauthorized`: Benutzer nicht authentifiziert.

*   **`PUT /api/user/settings`**
    *   **Beschreibung:** Aktualisiert die E-Mail-Einstellungen des aktuell authentifizierten Benutzers.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "emailAddress": "new.email@example.com",
          "pop3Host": "new.pop3.host.com",
          "pop3Port": 995,
          "pop3Encryption": "ssl",
          "imapHost": "new.imap.host.com",
          "imapPort": 993,
          "imapEncryption": "ssl",
          "smtpHost": "new.smtp.host.com",
          "smtpPort": 587,
          "smtpEncryption": "tls",
          "smtpUsername": "new.email@example.com",
          "smtpPassword": "new_smtp_password"
        }
        ```
        *   Alle Felder sind optional und können einzeln aktualisiert werden.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "message": "Settings updated",
              "settings": { /* ... aktualisierte Einstellungen ... */ }
            }
            ```
        *   `401 Unauthorized`: Benutzer nicht authentifiziert.

### 2.2 AI Agent & Workflow Management

Diese Endpunkte ermöglichen die Interaktion mit den intelligenten AI-Agenten und die Verwaltung komplexer, mehrstufiger Workflows.

*   **`POST /api/agent`**
    *   **Beschreibung:** Startet einen asynchronen Personal Assistant AI Agent Job. Der Agent wird im Hintergrund ausgeführt.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "prompt": "Finde mir eine 3-Zimmer-Wohnung in Berlin Mitte unter 1500€ und erstelle eine Liste der Top-5 Angebote."
        }
        ```
        *   `prompt` (string, required): Die natürliche Sprachbeschreibung der Aufgabe für den AI Agent.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "queued",
              "sessionId": "01234567-89ab-cdef-0123-456789abcdef",
              "message": "Job wurde erfolgreich zur Warteschlange hinzugefügt. Nutze /api/agent/status/01234567-89ab-cdef-0123-456789abcdef für Updates.",
              "statusUrl": "/api/agent/status/01234567-89ab-cdef-0123-456789abcdef"
            }
            ```
        *   `400 Bad Request`: Kein Prompt angegeben.

*   **`POST /api/devAgent`**
    *   **Beschreibung:** Startet einen asynchronen DevAgent Job. Dieser Agent ist darauf spezialisiert, Code (z.B. neue Tools) basierend auf einem Prompt zu generieren.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "prompt": "Erstelle ein Symfony AI Tool namens 'MyNewTool' mit einer Methode 'doSomething(string $param)'."
        }
        ```
        *   `prompt` (string, required): Die detaillierte Anweisung für den DevAgent zur Code-Generierung.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "queued",
              "message": "Job wurde erfolgreich in die Warteschlange gelegt."
            }
            ```
        *   `400 Bad Request`: Kein Prompt angegeben.

*   **`POST /api/frondend_devAgent`**
    *   **Beschreibung:** Startet einen asynchronen Frontend Generator AI Agent Job. Dieser Agent ist darauf spezialisiert, Frontend-Code basierend auf einem Prompt zu generieren.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "prompt": "Erstelle eine React-Komponente für ein Login-Formular."
        }
        ```
        *   `prompt` (string, required): Die detaillierte Anweisung für den Frontend Generator Agent.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "queued",
              "sessionId": "01234567-89ab-cdef-0123-456789abcdef",
              "message": "Frontend Generator Job zur Warteschlange hinzugefügt.",
              "statusUrl": "/api/agent/status/01234567-89ab-cdef-0123-456789abcdef"
            }
            ```
        *   `400 Bad Request`: Kein Prompt angegeben.

*   **`GET /api/agent/status/{sessionId}`**
    *   **Beschreibung:** Ruft den aktuellen Status und alle Status-Updates für einen laufenden AI Agent Job ab. Dies ist für Polling und die Anzeige des Fortschritts im Frontend gedacht.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Path Parameter:**
        *   `sessionId` (string, required): Die UUID der Job-Session.
    *   **Query Parameter:**
        *   `since` (string, optional): ISO 8601 Timestamp (z.B. `2024-01-15T10:30:00+00:00`). Wenn angegeben, werden nur Updates nach diesem Zeitpunkt zurückgegeben.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "sessionId": "01234567-89ab-cdef-0123-456789abcdef",
              "statuses": [
                {
                  "timestamp": "2024-01-15T10:30:00+00:00",
                  "message": "🚀 DevAgent Job gestartet"
                },
                {
                  "timestamp": "2024-01-15T10:30:05+00:00",
                  "message": "📝 Prompt wird analysiert..."
                },
                {
                  "timestamp": "2024-01-15T10:31:20+00:00",
                  "message": "✅ Job erfolgreich abgeschlossen (Dauer: 70.0s)"
                },
                {
                  "timestamp": "2024-01-15T10:31:25+00:00",
                  "message": "RESULT:Der Agent hat die Aufgabe erfolgreich abgeschlossen und einen PHP-Code erzeugt."
                }
              ],
              "completed": true,
              "result": "Der Agent hat die Aufgabe erfolgreich abgeschlossen und einen PHP-Code erzeugt.",
              "error": null,
              "timestamp": "2024-01-15T10:31:30+00:00"
            }
            ```
            *   `completed` (boolean): `true`, wenn der Job abgeschlossen ist (entweder erfolgreich oder mit Fehler).
            *   `result` (string, nullable): Der finale Ergebnis-String des Agenten.
            *   `error` (string, nullable): Eine Fehlermeldung, falls der Job fehlgeschlagen ist.
        *   `400 Bad Request`: Ungültiger `since` Parameter.

*   **`POST /api/workflow/create`**
    *   **Beschreibung:** Erstellt und startet einen neuen Workflow basierend auf einem User-Intent. Der Personal Assistant zerlegt die Anfrage in Schritte, prüft Tool-Verfügbarkeit und plant die Ausführung.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Request Body (JSON):**
        ```json
        {
          "intent": "Such mir eine Wohnung in Berlin Mitte für 1500€ und vereinbare Besichtigungstermine für die Top-3.",
          "sessionId": "01234567-89ab-cdef-0123-456789abcdef"
        }
        ```
        *   `intent` (string, required): Die User-Anfrage in natürlicher Sprache.
        *   `sessionId` (string, required): Eine eindeutige Session-ID für das Tracking des Workflows (kann von Frontend generiert werden, z.B. UUID).
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "created",
              "workflow_id": 123,
              "session_id": "01234567-89ab-cdef-0123-456789abcdef",
              "steps_count": 4,
              "missing_tools": [],
              "message": "Workflow erstellt und wird ausgeführt. Nutze /api/workflow/status für Updates."
            }
            ```
        *   `400 Bad Request`: `intent` oder `sessionId` fehlt.
        *   `500 Internal Server Error`: Fehler bei der Workflow-Erstellung.

*   **`GET /api/workflow/status/{sessionId}`**
    *   **Beschreibung:** Ruft den detaillierten Status eines Workflows ab, einschließlich aller Schritte und deren Status.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Path Parameter:**
        *   `sessionId` (string, required): Die Session-ID des Workflows.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "workflow_id": 123,
              "session_id": "01234567-89ab-cdef-0123-456789abcdef",
              "status": "running", // created, running, waiting_confirmation, completed, failed, cancelled
              "current_step": 2,
              "total_steps": 4,
              "steps": [
                {
                  "step_number": 1,
                  "type": "tool_call",
                  "description": "Suche Wohnungen in Berlin Mitte bis 1500€",
                  "status": "completed",
                  "requires_confirmation": false,
                  "result": { /* ... Ergebnis des Tools ... */ },
                  "error": null
                },
                {
                  "step_number": 2,
                  "type": "analysis",
                  "description": "Analysiere Suchergebnisse und filtere beste Angebote",
                  "status": "running",
                  "requires_confirmation": false,
                  "result": null,
                  "error": null
                }
              ],
              "created_at": "2024-01-15T11:00:00+00:00",
              "completed_at": null
            }
            ```
        *   `404 Not Found`: Workflow mit der angegebenen Session-ID nicht gefunden.

*   **`POST /api/workflow/confirm/{workflowId}`**
    *   **Beschreibung:** Bestätigt oder lehnt einen Workflow-Schritt ab, der auf Benutzerbestätigung wartet.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Path Parameter:**
        *   `workflowId` (integer, required): Die ID des Workflows.
    *   **Request Body (JSON):**
        ```json
        {
          "confirmed": true
        }
        ```
        *   `confirmed` (boolean, required): `true` für Bestätigung, `false` für Ablehnung.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "success",
              "confirmed": true,
              "workflow_status": "running"
            }
            ```
        *   `400 Bad Request`: Workflow wartet nicht auf Bestätigung oder `confirmed` Parameter fehlt.
        *   `404 Not Found`: Workflow nicht gefunden.
        *   `500 Internal Server Error`: Fehler bei der Verarbeitung der Bestätigung.

*   **`GET /api/workflow/list`**
    *   **Beschreibung:** Listet alle Workflows auf.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Query Parameter:**
        *   `status` (string, optional): Filtert nach Workflow-Status (`created`, `running`, `waiting_confirmation`, `completed`, `failed`, `cancelled`).
        *   `limit` (integer, optional, default: 20): Maximale Anzahl der zurückzugebenden Workflows.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "workflows": [
                {
                  "id": 1,
                  "session_id": "...",
                  "user_intent": "Finde Wohnung...",
                  "status": "completed",
                  "steps_count": 3,
                  "current_step": 3,
                  "created_at": "2024-01-15T10:00:00+00:00",
                  "completed_at": "2024-01-15T10:15:00+00:00"
                }
              ],
              "count": 1
            }
            ```

*   **`GET /api/workflow/capabilities`**
    *   **Beschreibung:** Listet alle vom AI-Agenten unterstützten Tools und deren Capabilities auf.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "tools": [
                "immobilien_search_tool",
                "google_calendar_create_event",
                "gmail_send_tool",
                "web_scraper",
                "api_client",
                "PdfGenerator",
                "mysql_knowledge_search"
              ],
              "tools_count": 7,
              "capabilities": {
                "apartment_search": true,
                "calendar_management": true,
                "email_sending": true,
                "web_scraping": true,
                "pdf_generation": true,
                "api_calling": true
              }
            }
            ```

### 2.3 System & Health Checks

*   **`GET /health`**
    *   **Beschreibung:** Ein einfacher Health Check Endpunkt, um die Verfügbarkeit der API und der Datenbankverbindung zu prüfen.
    *   **Authentifizierung:** Nicht erforderlich.
    *   **Responses:**
        *   `200 OK`: Bei Erfolg.
            ```json
            {
              "status": "healthy",
              "timestamp": "2024-01-15T10:30:00+00:00",
              "database": "connected"
            }
            ```
        *   `503 Service Unavailable`: Wenn die Datenbankverbindung fehlschlägt.
            ```json
            {
              "status": "unhealthy",
              "timestamp": "2024-01-15T10:30:00+00:00",
              "database": "disconnected",
              "error": "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"
            }
            ```

### 2.4 Knowledge Base

*   **`POST /api/index-knowledge`**
    *   **Beschreibung:** Löst die Indizierung der Dokumente in der Wissensdatenbank aus. Dies ist ein interner Endpunkt, der normalerweise nicht direkt vom Frontend aufgerufen wird, sondern von Administratoren oder als Teil eines Deployment-Prozesses.
    *   **Authentifizierung:** Erfordert ein gültiges `BEARER` Cookie.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "success",
              "message": "Knowledge base indexed successfully.",
              "details": "SUCCESS: Indexed X chunks from Y document(s) into MySQL knowledge base."
            }
            ```
        *   `500 Internal Server Error`: Bei Fehlern während der Indizierung.

### 2.5 Mail (Senden & Empfangen)

Diese Endpunkte ermöglichen es dem AI-Agenten, E-Mails im Namen des Benutzers zu versenden und zu empfangen, basierend auf den in den Benutzereinstellungen hinterlegten SMTP/IMAP/POP3-Konfigurationen.

*   **`POST /api/mail/send` (interner Tool-Endpunkt, nicht direkt vom Frontend aufrufen)**
    *   **Beschreibung:** Sendet eine E-Mail über die konfigurierten SMTP-Einstellungen des Benutzers. Dies wird als AI Tool verwendet.
    *   **Authentifizierung:** Intern durch den Agenten über `userId` gesteuert.
    *   **Request Body (JSON):**
        ```json
        {
          "userId": 1,
          "to": "recipient@example.com, another@example.com",
          "subject": "Wichtige Nachricht",
          "body": "<p>Hallo,</p><p>Dies ist der E-Mail-Inhalt.</p>",
          "from": "my.email@example.com"
        }
        ```
        *   `userId` (integer, required): Die ID des sendenden Benutzers.
        *   `to` (string, required): Empfänger-E-Mail-Adressen (durch Kommas getrennt).
        *   `subject` (string, required): Betreff der E-Mail.
        *   `body` (string, required): HTML-Inhalt der E-Mail.
        *   `from` (string, optional): Absender-E-Mail-Adresse. Wenn nicht angegeben, wird `smtpUsername` aus den UserSettings verwendet.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "success",
              "message": "E-Mail erfolgreich gesendet."
            }
            ```
        *   `400 Bad Request`: Ungültige Eingabe oder fehlende SMTP-Einstellungen.
        *   `500 Internal Server Error`: Fehler beim Senden der E-Mail.

*   **`POST /api/mail/receive` (interner Tool-Endpunkt, nicht direkt vom Frontend aufrufen)**
    *   **Beschreibung:** Empfängt E-Mails über die konfigurierten IMAP- oder POP3-Einstellungen des Benutzers. Dies wird als AI Tool verwendet.
    *   **Authentifizierung:** Intern durch den Agenten über `userId` gesteuert.
    *   **Request Body (JSON):**
        ```json
        {
          "userId": 1,
          "protocol": "IMAP",
          "limit": 10
        }
        ```
        *   `userId` (integer, required): Die ID des Benutzers.
        *   `protocol` (string, required): `'IMAP'` oder `'POP3'`.
        *   `limit` (integer, optional, default: 10): Maximale Anzahl der abzurufenden E-Mails.
    *   **Responses:**
        *   `200 OK`:
            ```json
            {
              "status": "success",
              "message": "X E-Mails erfolgreich abgerufen.",
              "emails": [
                {
                  "from": "sender@example.com",
                  "subject": "Betreff der E-Mail",
                  "date": "2024-01-15 12:00:00",
                  "body_plain": "Dies ist der Klartext-Inhalt.",
                  "body_html": "<p>Dies ist der HTML-Inhalt.</p>",
                  "message_id": "<abcde12345@example.com>"
                }
              ]
            }
            ```
        *   `400 Bad Request`: Ungültige Eingabe oder fehlende E-Mail-Einstellungen.
        *   `500 Internal Server Error`: Fehler beim Empfangen der E-Mails.

## 3. Messenger System für asynchrone Jobs

Das Backend verwendet Symfony Messenger, um langlaufende AI-Agenten-Aufgaben asynchron zu verarbeiten. Dies bedeutet, dass API-Aufrufe, die Agenten-Jobs starten, sofort mit einer `sessionId` antworten und der eigentliche Job im Hintergrund von Worker-Prozessen ausgeführt wird.

### 3.1 Funktionsweise

1.  **Job-Einreichung:** Wenn Sie einen AI Agent Job starten (z.B. `POST /api/agent`), wird eine Nachricht (`Message`) in eine Warteschlange (`Queue`) gelegt.
2.  **Sofortige Antwort:** Die API antwortet sofort mit einer `sessionId` und dem Status `queued`.
3.  **Hintergrundverarbeitung:** Worker-Prozesse empfangen Nachrichten aus der Warteschlange und verarbeiten sie.
4.  **Status-Tracking:** Während der Verarbeitung wird der Status des Jobs in der Datenbank gespeichert und kann über den `GET /api/agent/status/{sessionId}` Endpunkt abgefragt werden.
5.  **Circuit Breaker:** Ein `CircuitBreakerMiddleware` schützt die externen AI-APIs (z.B. Gemini) vor Überlastung, indem es bei zu vielen Fehlern Anfragen für eine bestimmte Zeit blockiert und später automatisch wieder versucht.
6.  **Progressive Backoff:** Bei transienten Fehlern werden Jobs mit zunehmender Verzögerung wiederholt, um eine Überlastung der externen Dienste zu vermeiden.

### 3.2 Status-Updates für Frontend

Das Frontend sollte den Endpunkt `GET /api/agent/status/{sessionId}` regelmäßig (z.B. alle 5-10 Sekunden) pollen, um den Fortschritt eines Agenten-Jobs anzuzeigen.

*   Der `statuses` Array enthält alle chronologischen Statusmeldungen.
*   Das `completed` Flag zeigt an, ob der Job beendet ist.
*   `result` enthält das Endergebnis bei Erfolg.
*   `error` enthält die Fehlermeldung bei Misserfolg.

## 4. Sicherheitsmerkmale (Allgemein)

Das Projekt implementiert eine Reihe von Sicherheitsmaßnahmen auf verschiedenen Ebenen:

*   **HTTPS-Erzwingung:** In Produktionsumgebungen werden alle HTTP-Anfragen automatisch auf HTTPS umgeleitet.
*   **Sicherheits-Header:**
    *   `Strict-Transport-Security (HSTS)`: Erzwingt die Verwendung von HTTPS für zukünftige Anfragen.
    *   `Content-Security-Policy (CSP)`: Schützt vor Cross-Site Scripting (XSS) und Dateninjektionsangriffen, indem es die Quellen von Inhalten (Scripts, Styles, Bilder etc.) einschränkt. Für die API-Dokumentation (`/api/doc`) wird eine lockerere CSP verwendet, um die Funktionalität der Swagger UI zu gewährleisten. Für alle anderen Endpunkte ist sie sehr restriktiv (`default-src 'none'`).
    *   `X-Content-Type-Options: nosniff`: Verhindert MIME-Type Sniffing, das zu XSS führen kann.
    *   `X-Frame-Options: DENY`: Verhindert Clickjacking-Angriffe, indem das Einbetten der Seite in `<iframe>`s untersagt wird.
    *   `X-XSS-Protection: 1; mode=block`: Aktiviert den XSS-Filter moderner Browser.
    *   `Referrer-Policy: strict-origin-when-cross-origin`: Kontrolliert, wann und wie Referrer-Informationen gesendet werden.
    *   `Permissions-Policy`: Deaktiviert Browser-APIs (z.B. Geolocation, Kamera) die von der Anwendung nicht benötigt werden, um die Angriffsfläche zu reduzieren.
*   **Rate Limiting:** Schützt kritische Endpunkte (Login, Passwort-Reset) vor Brute-Force- und Denial-of-Service-Angriffen.
*   **JWT & Refresh Token:** Eine bewährte Methode für zustandslose Authentifizierung, mit der zusätzlichen Sicherheit von Refresh Tokens, um die Häufigkeit der Neuanmeldung zu reduzieren.
*   **HttpOnly Cookies:** Verhindern den Zugriff von JavaScript auf sensible Authentifizierungscookies.
*   **SameSite Cookies:** Schützen vor CSRF-Angriffen.
*   **Passwort-Hashing:** Alle Passwörter werden mit `UserPasswordHasherInterface` (basierend auf Argon2 oder ähnlichem) sicher gehasht und gesalzen gespeichert.
*   **Input-Validierung:** Strikte Validierung aller Benutzereingaben auf der Serverseite, um Injektionsangriffe und andere Schwachstellen zu verhindern. Dies geschieht sowohl im Code der Controller als auch durch Symfony Validator Constraints und `#[With]` Attribute bei AI-Tools.
*   **Fehlerbehandlung:** Der zentrale `ExceptionListener` stellt sicher, dass in der Produktion keine sensiblen Fehlerinformationen an den Client weitergegeben werden.
*   **Audit-Logging:** Sensible Sicherheitsereignisse (z.B. fehlgeschlagene Anmeldeversuche, unbefugte Zugriffe) werden geloggt.
*   **File Security (`FileSecurityService`):**
    *   **MIME-Type Whitelisting:** Nur erlaubte Dateitypen (z.B. Bilder) können hochgeladen werden.
    *   **Dateigrößenbegrenzung:** Beschränkt die Größe hochgeladener Dateien.
    *   **Server-seitige MIME-Erkennung:** Vergleicht den vom Client angegebenen MIME-Typ mit dem tatsächlich erkannten MIME-Typ, um Manipulationen zu erkennen.
    *   **Bildvalidierung:** Für Bilder werden Dimensionen geprüft, um Exploits zu verhindern.
    *   **Dateinamen-Validierung:** Verhindert Polyglot-Dateinamen und gefährliche Dateierweiterungen.
    *   **Sicheres Speichern:** Dateien werden mit eindeutigen, slugifizierten Namen gespeichert, um Path-Traversal-Angriffe zu verhindern.
    *   **Sicheres Löschen:** Dateien können sicher gelöscht werden, indem ihr Inhalt überschrieben wird, bevor sie entfernt werden.
*   **Circuit Breaker (`CircuitBreakerService`):** Schützt Downstream-Dienste (wie externe AI-APIs) vor Überlastung und Kaskadierung von Fehlern, indem Anfragen vorübergehend blockiert werden, wenn ein Dienst als fehlerhaft erkannt wird.
*   **Minimale OAuth2-Scopes:** Google OAuth-Berechtigungen werden nur für die tatsächlich benötigten Dienste (Kalender, Gmail) angefordert.
*   **Refresh Token Management:** Google Refresh Tokens werden sicher gespeichert und verwendet, um Access Tokens transparent zu erneuern, was die Notwendigkeit wiederholter Anmeldungen reduziert, aber Offline-Zugriff ermöglicht.

## 5. Externe Tool-Integrationen

Die AI-Agenten nutzen eine Reihe von Tools, um ihre Aufgaben auszuführen. Einige dieser Tools sind interne APIs (z.B. `send_email`), während andere externe APIs ansprechen. Die Frontend-Anwendung interagiert *nicht* direkt mit diesen Tools, sondern fordert den AI-Agenten auf, sie zu verwenden.

*   **`web_scraper`:** Greift auf URLs zu und extrahiert Daten mittels CSS-Selektoren.
*   **`api_client`:** Macht allgemeine HTTP-Anfragen an externe APIs.
*   **`mysql_knowledge_search`:** Sucht in der internen MySQL-Wissensdatenbank.
*   **`google_calendar_create_event`:** Erstellt Ereignisse im Google Kalender des Benutzers (erfordert Google OAuth-Authentifizierung).
*   **`PdfGenerator`:** Erzeugt PDF-Dateien aus Textinhalten.
*   **`job_search`:** Sucht nach Stellenangeboten über die API der Bundesagentur für Arbeit.

**Wichtiger Hinweis für Frontend-Entwickler bei Google-Integrationen (z.B. Kalender, Gmail):**

Wenn ein AI-Agent versucht, ein Google-Tool zu verwenden und der Benutzer nicht (mehr) mit Google authentifiziert ist oder die erforderlichen Berechtigungen fehlen, kann der Status des Agenten-Jobs eine Fehlermeldung wie `Google Calendar is not connected. Please connect your Google account first by visiting /connect/google` zurückgeben. In diesem Fall sollte das Frontend den Benutzer auffordern, die Google OAuth-Verbindung über den Endpunkt `/api/connect/google` erneut herzustellen.

---
Ende der Dokumentation.
