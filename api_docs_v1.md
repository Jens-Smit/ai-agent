# API-Dokumentation für Frontend-Entwickler (v1.0)

Dies ist eine umfassende Dokumentation der Backend-API, die als Grundlage für die Entwicklung eines robusten und sicheren Frontends dient. Sie beschreibt die verfügbaren Endpunkte, deren Funktionalität, die erwarteten Request- und Response-Formate sowie wichtige Sicherheitsaspekte.

---

## 1. Authentifizierung & Autorisierung

Die API verwendet JWT (JSON Web Tokens) für die Authentifizierung. Es gibt zwei Hauptwege zur Authentifizierung: E-Mail/Passwort-Login und Google OAuth. Refresh-Tokens werden ebenfalls verwendet, um die Benutzerfreundlichkeit und Sicherheit zu verbessern.

### 1.1. E-Mail & Passwort Authentifizierung

**Login-Flow:**
1. Der Frontend sendet eine `POST`-Anfrage an `/api/login` mit E-Mail und Passwort.
2. Bei erfolgreicher Authentifizierung werden zwei `HttpOnly`-Cookies im Browser des Benutzers gesetzt: `BEARER` (Access Token) und `refresh_token` (Refresh Token).
3. Diese Cookies werden automatisch mit nachfolgenden Anfragen gesendet und vom Backend zur Authentifizierung genutzt. Das Frontend muss die Cookies nicht manuell lesen oder verwalten.

**Logout-Flow:**
1. Der Frontend sendet eine `POST`-Anfrage an `/api/logout`.
2. Das Backend löscht die `BEARER`- und `refresh_token`-Cookies, wodurch der Benutzer abgemeldet wird.

**Token-Erneuerung (implizit):**
Die API ist so konfiguriert, dass der `BEARER`-Token eine kürzere Lebensdauer hat (z.B. 1 Stunde), während der `refresh_token` eine längere Lebensdauer hat (z.B. 7 Tage). Wenn der `BEARER`-Token abgelaufen ist, aber der `refresh_token` noch gültig ist, kann der Server automatisch einen neuen `BEARER`-Token generieren, ohne dass sich der Benutzer erneut anmelden muss. Dies geschieht transparent im Backend.

### 1.2. Google OAuth Authentifizierung

**Google Connect-Flow:**
1. Der Frontend leitet den Benutzer zu `/api/connect/google` weiter.
2. Der Backend-Dienst leitet den Benutzer zur Google-Anmeldeseite weiter, wo er die Berechtigungen für die Anwendung erteilt.
3. Nach erfolgreicher Autorisierung durch Google wird der Benutzer zum Callback-Endpunkt `/api/connect/google/check` umgeleitet.
4. Der Backend-Dienst verarbeitet den Callback, speichert die Google OAuth-Tokens (Access- und Refresh-Token) serverseitig und setzt die `BEARER`- und `refresh_token`-Cookies im Browser.
5. Abschließend wird der Benutzer zurück zum Frontend umgeleitet (standardmäßig `https://127.0.0.1:3000/dashboard`), wo die Anwendung nun authentifiziert ist.

**Wichtige Google Scopes:**
Die Anwendung fordert folgende Google OAuth Scopes an:
- `email`: Zugriff auf die E-Mail-Adresse des Benutzers.
- `profile`: Zugriff auf grundlegende Profilinformationen.
- `https://www.googleapis.com/auth/calendar`: Vollzugriff auf den Google Kalender des Benutzers.
- `https://www.googleapis.com/auth/gmail.modify`: Modifizierungszugriff auf Gmail (lesen, senden, löschen etc.).

### 1.3. Cookie-Sicherheit (`httponly`, `secure`, `samesite`)

Die Cookies, die für die Authentifizierung verwendet werden, sind wie folgt konfiguriert:

-   **`HttpOnly: true`**: Dies ist eine kritische Sicherheitseinstellung. Sie stellt sicher, dass die Cookies nicht über clientseitiges JavaScript zugänglich sind. Dies ist ein wirksamer Schutz gegen Cross-Site Scripting (XSS)-Angriffe, da ein Angreifer, selbst wenn er bösartigen JavaScript-Code in die Webseite einschleusen könnte, keinen Zugriff auf die Authentifizierungscookies hätte. Das Frontend muss daher **nicht versuchen**, die Tokens aus Cookies auszulesen. Die Browser senden diese Cookies automatisch mit jeder Anfrage an die API.
-   **`Secure: false` (Entwicklungsumgebung)** / **`Secure: true` (Produktion)**:
    -   In der aktuellen Entwicklungsumgebung ist `Secure` auf `false` gesetzt. Dies ist notwendig, damit die Cookies auch über unverschlüsselte HTTP-Verbindungen gesendet werden können, was in lokalen Entwicklungsumgebungen üblich ist (z.B. `http://localhost`).
    -   **Für die Produktion MUSS `Secure: true` gesetzt werden.** Dies stellt sicher, dass die Cookies nur über verschlüsselte HTTPS-Verbindungen gesendet werden, was Man-in-the-Middle (MITM)-Angriffe verhindert. Das Backend enthält bereits einen Listener (`SecurityHeadersListener`), der in `prod`-Umgebungen einen HTTPS-Redirect erzwingt und HSTS-Header setzt, was die Nutzung von `Secure: true` in der Produktion obligatorisch macht.
-   **`SameSite: Lax`**: Diese Einstellung bietet einen guten Kompromiss zwischen Sicherheit und Funktionalität.
    -   Cookies werden mit Top-Level-Navigationen (z.B. Klick auf einen Link von einer externen Seite zur eigenen App) gesendet.
    -   Cookies werden **nicht** mit Cross-Site-Anfragen von Drittanbietern gesendet (z.B. `<img>` oder `<iframe>` Tags), wodurch ein gewisser Schutz gegen Cross-Site Request Forgery (CSRF)-Angriffe geboten wird, aber kein vollständiger Schutz.
    -   In Kombination mit `HttpOnly` ist dies eine starke Absicherung.
    -   Wenn eine noch strengere `SameSite`-Politik (`Strict`) gewünscht ist, müsste dies explizit im Backend geändert werden, was jedoch die Benutzererfahrung bei manchen Cross-Site-Navigationen einschränken könnte.

**Zusammenfassung für Frontend:**
Das Frontend muss sich **nicht** um die Speicherung, das Auslesen oder das Anhängen von Tokens an Anfragen kümmern. Die Authentifizierung erfolgt transparent über `HttpOnly`-Cookies. Entwickler sollten sicherstellen, dass alle API-Aufrufe über die gleiche Domain erfolgen, um `SameSite`-Probleme zu vermeiden und die Sicherheit zu gewährleisten. In der Produktion muss die Frontend-Applikation **immer** über HTTPS ausgeliefert werden.

---

## 2. API Endpunkte

Die API-Endpunkte sind in logische Gruppen unterteilt.

### 2.1. Agent Status (Tag: `Agent Status`)

**Basis-Route:** `/api/agent`

#### 2.1.1. Status eines Agenten abrufen

-   **Route:** `/api/agent/status/{sessionId}`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft alle Status-Updates für eine Session ab.
-   **Beschreibung:** Holt alle Status-Meldungen für eine bestimmte Session-ID. Kann optional mit einem `since`-Parameter für inkrementelle Updates verwendet werden.

-   **Path Parameters:**
    -   `sessionId` (string, required): Die eindeutige ID der Agenten-Session.

-   **Query Parameters:**
    -   `since` (string, optional, format: `date-time`): ISO 8601 Timestamp (z.B. `2024-01-15T10:30:00Z`) für inkrementelle Updates. Es werden nur Status-Meldungen zurückgegeben, die nach diesem Zeitpunkt erstellt wurden.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "sessionId": "string",
          "statuses": [
            {
              "timestamp": "string", // ISO 8601 Datum/Zeit
              "message": "string"
            }
          ],
          "completed": "boolean", // True, wenn der Job abgeschlossen ist (RESULT:/ERROR:/DEPLOYMENT:)
          "result": "string | null", // Ergebnisnachricht, wenn der Job erfolgreich abgeschlossen wurde
          "error": "string | null", // Fehlermeldung, wenn der Job fehlgeschlagen ist
          "timestamp": "string" // Aktueller ISO 8601 Timestamp der Antwort
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Invalid since parameter"
        }
        ```

### 2.2. AI Agent (Tag: `AI Agent`)

**Basis-Route:** Keine spezifische, da `/api/devAgent` eine separate Route ist.

#### 2.2.1. Symfony Developing Expert AI Agent (queued)

-   **Route:** `/api/devAgent`
-   **Methode:** `POST`
-   **Zusammenfassung:** Symfony Developing expert AI Agent (queued)
-   **Beschreibung:** Stellt einen Prompt asynchron zur Verarbeitung durch Worker in die Warteschlange.

-   **Request Body (JSON):**
    ```json
    {
      "prompt": "string", // Beispiel: "Generate a deployment script for nginx",
      "context": "string | null",
      "options": "object | null"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "string", // "queued"
          "message": "string" // "Job wurde erfolgreich in die Warteschlange gelegt."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Missing prompt"
        }
        ```

### 2.3. AI Agent - Personal Assistant (Tag: `AI Agent - Personal Assistant`)

**Basis-Route:** `/api`

#### 2.3.1. Personal Assistant AI Agent (Async)

-   **Route:** `/api/agent`
-   **Methode:** `POST`
-   **Zusammenfassung:** Startet den Personal Assistant asynchron.
-   **Beschreibung:** Enqueue einen Prompt für den Personal Assistant. Der Status kann über `/api/agent/status/{sessionId}` verfolgt werden.

-   **Request Body (JSON - `AgentPromptRequest` Schema):**
    ```json
    {
      "prompt": "string", // Der eigentliche Befehl oder die Frage an den Agenten
      "context": "string | null",
      "options": "object | null"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "string", // "queued"
          "sessionId": "string", // Eindeutige ID der Session
          "message": "string", // Bestätigungsnachricht
          "statusUrl": "string" // URL zum Abrufen des Status der Session
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "status": "string", // "error"
          "message": "string" // "Kein Prompt angegeben"
        }
        ```

#### 2.3.2. Frontend Generator AI Agent (Async)

-   **Route:** `/api/frondend_devAgent`
-   **Methode:** `POST`
-   **Zusammenfassung:** Startet den Frontend Generator asynchron.
-   **Beschreibung:** Enqueue einen Prompt für den Frontend Generator Agent.

-   **Request Body (JSON - `AgentPromptRequest` Schema):**
    ```json
    {
      "prompt": "string", // Der eigentliche Befehl oder die Anforderung an den Generator
      "context": "string | null",
      "options": "object | null"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "string", // "queued"
          "sessionId": "string", // Eindeutige ID der Session
          "message": "string", // Bestätigungsnachricht
          "statusUrl": "string" // URL zum Abrufen des Status der Session
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "status": "string", // "error"
          "message": "string" // "Kein Prompt angegeben"
        }
        ```

### 2.4. Authentication (Tag: `Authentication`)

**Basis-Route:** Keine spezifische, da die Routen direkt auf `/api` beginnen.

#### 2.4.1. Benutzer-Login

-   **Route:** `/api/login`
-   **Methode:** `POST`
-   **Zusammenfassung:** Authentifiziert einen Benutzer und gibt Access- und Refresh-Token zurück.
-   **Beschreibung:** Der Benutzer meldet sich mit E-Mail und Passwort an. Bei Erfolg werden `HttpOnly`-Cookies mit den Tokens gesetzt.

-   **Request Body (JSON):**
    ```json
    {
      "email": "string", // Format: email
      "password": "string" // Format: password
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "string", // "Login erfolgreich"
          "user": {
            "email": "string"
          }
        }
        ```
        (Zusätzlich werden `BEARER` und `refresh_token` Cookies gesetzt)
    -   `401 Unauthorized`:
        ```json
        {
          "error": "string" // "Ungültige Anmeldedaten."
        }
        ```
    -   `429 Too Many Requests`:
        ```json
        {
          "error": "string", // "Zu viele Versuche"
          "retry_after": "integer" // Unix Timestamp wann ein neuer Versuch erlaubt ist
        }
        ```

#### 2.4.2. Benutzer-Logout

-   **Route:** `/api/logout`
-   **Methode:** `POST`
-   **Zusammenfassung:** Löscht Access- und Refresh-Token-Cookies.
-   **Beschreibung:** Meldet den Benutzer ab, indem die Authentifizierungs-Cookies im Browser gelöscht werden.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "string" // "Logout erfolgreich."
        }
        ```
        (Zusätzlich werden `BEARER` und `refresh_token` Cookies mit abgelaufener Gültigkeit gesendet, um sie zu löschen)

#### 2.4.3. Benutzer registrieren

-   **Route:** `/api/register`
-   **Methode:** `POST`
-   **Zusammenfassung:** Registriert einen neuen Benutzer mit E-Mail und Passwort.

-   **Request Body (JSON):**
    ```json
    {
      "email": "string", // Format: email
      "password": "string" // Format: password
    }
    ```

-   **Responses:**
    -   `201 Created`:
        ```json
        {
          "message": "string" // "Benutzer erfolgreich registriert."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string" // "E-Mail und Passwort sind erforderlich." oder "Passwort muss mindestens 8 Zeichen lang sein." oder "E-Mail ist bereits registriert."
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "string" // "Registrierung fehlgeschlagen"
        }
        ```

#### 2.4.4. Passwort-Reset anfordern

-   **Route:** `/api/password/request-reset`
-   **Methode:** `POST`
-   **Zusammenfassung:** Sendet eine E-Mail für das Zurücksetzen des Passworts.

-   **Request Body (JSON):**
    ```json
    {
      "email": "string" // Format: email
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "string" // "Falls die E-Mail existiert, wurde eine Reset-E-Mail versendet."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string" // "E-Mail ist erforderlich."
        }
        ```
    -   `429 Too Many Requests`:
        ```json
        {
          "error": "string" // "Zu viele Passwort-Reset-Anfragen. Bitte später erneut."
        }
        ```

#### 2.4.5. Passwort zurücksetzen

-   **Route:** `/api/password/reset`
-   **Methode:** `POST`
-   **Zusammenfassung:** Setzt das Passwort mithilfe des Tokens zurück.

-   **Request Body (JSON):**
    ```json
    {
      "token": "string", // Der Reset-Token aus der E-Mail
      "newPassword": "string"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "string" // "Passwort erfolgreich zurückgesetzt."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string" // "Ungültiger Reset-Token." oder "Reset-Token ist abgelaufen." oder "Passwort muss mindestens 8 Zeichen lang sein."
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "string" // "Fehler beim Zurücksetzen des Passworts"
        }
        ```

#### 2.4.6. Passwort ändern

-   **Route:** `/api/password/change`
-   **Methode:** `POST`
-   **Zusammenfassung:** Ändert das Passwort des aktuell angemeldeten Benutzers.

-   **Request Body (JSON):**
    ```json
    {
      "currentPassword": "string",
      "newPassword": "string"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "string" // "Passwort erfolgreich geändert."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string" // "Aktuelles und neues Passwort sind erforderlich." oder "Das aktuelle Passwort ist ungültig." oder "Neues Passwort muss sich vom alten unterscheiden." oder "Neues Passwort muss mindestens 8 Zeichen lang sein."
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "string" // "Nicht authentifiziert."
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "string" // "Fehler beim Ändern des Passworts"
        }
        ```

### 2.5. Google Authentication

**Basis-Route:** Keine spezifische, Routen sind direkt.

#### 2.5.1. Google OAuth Connect Start

-   **Route:** `/api/connect/google`
-   **Methode:** `GET`
-   **Zusammenfassung:** Startet den Google OAuth-Prozess.
-   **Beschreibung:** Leitet den Benutzer zur Google-Anmeldeseite weiter.

-   **Responses:**
    -   `302 Found`: Redirect zur Google-Anmeldeseite.

#### 2.5.2. Google OAuth Connect Check (Callback)

-   **Route:** `/api/connect/google/check`
-   **Methode:** `GET`
-   **Zusammenfassung:** OAuth2-Callback-Route für Google.
-   **Beschreibung:** Diese Route wird von Google nach erfolgreicher Authentifizierung aufgerufen. Sie verarbeitet die Antwort, speichert die Tokens und leitet den Benutzer zum Frontend weiter. Normalerweise interagiert das Frontend nicht direkt mit diesem Endpunkt.

-   **Responses:**
    -   `302 Found`: Redirect zum konfigurierten Frontend-Dashboard (z.B. `https://127.0.0.1:3000/dashboard`). Setzt dabei die `BEARER`- und `refresh_token`-Cookies.
    -   `302 Found` (Fehlerfall): Redirect zum Frontend-Dashboard mit Fehlermeldung als Query-Parameter (z.B. `?error=oauth_failed&hint=reconsent`).

### 2.6. Health Check (Tag: `System`)

**Basis-Route:** Keine spezifische.

#### 2.6.1. Health Check Endpoint

-   **Route:** `/health`
-   **Methode:** `GET`
-   **Zusammenfassung:** Prüft die Verfügbarkeit der API und Datenbankverbindung.
-   **Beschreibung:** Wird von Load Balancers und Monitoring-Tools verwendet, um den Systemzustand zu überprüfen.

-   **Responses:**
    -   `200 OK`: System ist gesund und funktionsfähig.
        ```json
        {
          "status": "healthy",
          "timestamp": "string", // ISO 8601 Datum/Zeit, Beispiel: "2024-01-15T10:30:00+00:00"
          "database": "connected"
        }
        ```
    -   `503 Service Unavailable`: Service nicht verfügbar (z.B. Datenbankverbindung ausgefallen).
        ```json
        {
          "status": "unhealthy",
          "timestamp": "string", // ISO 8601 Datum/Zeit
          "database": "disconnected",
          "error": "string" // Fehlermeldung der Datenbank
        }
        ```

### 2.7. Knowledge Base (Tag: `Knowledge Base`)

**Basis-Route:** `/api`

#### 2.7.1. Knowledge Base indizieren

-   **Route:** `/api/index-knowledge`
-   **Methode:** `POST`
-   **Zusammenfassung:** Triggert die Indizierung der Knowledge Base Dokumente in den Vector Store.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "Knowledge base indexing initiated successfully.",
          "details": "string" // Details vom Indexierungsprozess
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Knowledge base indexing failed.",
          "details": "string" // Fehlermeldung
        }
        ```

### 2.8. User (Tag: `User`)

**Basis-Route:** `/api/user`

#### 2.8.1. Aktuellen authentifizierten Benutzer abrufen

-   **Route:** `/api/user`
-   **Methode:** `GET`
-   **Zusammenfassung:** Gibt die Daten des aktuell authentifizierten Benutzers zurück.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "user": {
            "id": "integer",
            "email": "string",
            "name": "string | null",
            "roles": [
              "string" // z.B. "ROLE_USER"
            ]
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.8.2. Benutzerprofil aktualisieren

-   **Route:** `/api/user`
-   **Methode:** `PUT`
-   **Zusammenfassung:** Aktualisiert die Profilinformationen des aktuellen Benutzers.
-   **Beschreibung:** Aktuell nur Unterstützung für die Aktualisierung des Namens.

-   **Request Body (JSON):**
    ```json
    {
      "name": "string" // Der neue Name des Benutzers
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "Profile updated successfully",
          "user": {
            "id": "integer",
            "email": "string"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

### 2.9. User Documents (Tag: `User Documents`)

**Basis-Route:** `/api/documents`

#### 2.9.1. Liste aller hochgeladenen Dokumente

-   **Route:** `/api/documents`
-   **Methode:** `GET`
-   **Zusammenfassung:** Listet alle Dokumente des Benutzers auf.

-   **Query Parameters:**
    -   `category` (string, optional, enum: `template`, `attachment`, `reference`, `media`, `other`): Filtert Dokumente nach Kategorie.
    -   `type` (string, optional, enum: `pdf`, `document`, `spreadsheet`, `image`, `text`, `other`): Filtert Dokumente nach Typ.
    -   `limit` (integer, optional, default: `50`): Maximale Anzahl der zurückgegebenen Dokumente (Min 1, Max 100).

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "count": "integer",
          "documents": [
            {
              "id": "integer",
              "name": "string", // Anzeigename oder Original-Dateiname
              "original_filename": "string",
              "type": "string", // pdf, document, image, text, etc.
              "mime_type": "string",
              "category": "string",
              "size": "string", // Human-readable size (e.g., "1.2 MB")
              "size_bytes": "integer",
              "tags": [ "string" ], // Array von Tags
              "is_indexed": "boolean",
              "created_at": "string" // ISO 8601 Datum/Zeit
            }
          ]
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.9.2. Dokument hochladen

-   **Route:** `/api/documents`
-   **Methode:** `POST`
-   **Zusammenfassung:** Lädt ein Dokument hoch.

-   **Request Body (`multipart/form-data`):**
    -   `file` (file, required): Die hochzuladende Datei.
    -   `category` (string, optional, enum: `template`, `attachment`, `reference`, `media`, `other`): Kategorie des Dokuments.
    -   `display_name` (string, optional): Anzeigename des Dokuments.
    -   `description` (string, optional): Beschreibung des Dokuments.
    -   `tags` (string, optional): Komma-getrennte Tags für das Dokument.
    -   `index_to_knowledge` (boolean, optional): Ob das Dokument in die Knowledge Base indiziert werden soll.

-   **Responses:**
    -   `201 Created`:
        ```json
        {
          "status": "success",
          "message": "Document uploaded successfully",
          "document": {
            "id": "integer",
            "name": "string",
            "original_filename": "string",
            "type": "string",
            "category": "string",
            "size": "string",
            "mime_type": "string",
            "created_at": "string",
            "tags": [ "string" ],
            "description": "string | null",
            "metadata": "object | null", // Extrahierte Metadaten (z.B. Seitenanzahl für PDF)
            "has_extracted_text": "boolean",
            "access_count": "integer",
            "last_accessed": "string | null"
          }
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string" // Z.B. "No file uploaded", "Datei zu groß.", "Dateityp nicht erlaubt."
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.9.3. Dokumente durchsuchen

-   **Route:** `/api/documents/search`
-   **Methode:** `GET`
-   **Zusammenfassung:** Sucht in den Dokumenten des Benutzers.

-   **Query Parameters:**
    -   `q` (string, required): Der Suchbegriff.
    -   `limit` (integer, optional, default: `20`): Maximale Anzahl der Ergebnisse (Min 1, Max 50).

-   **Responses:**
    -   `200 OK`:
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
              "category": "string",
              "size": "string",
              "mime_type": "string",
              "created_at": "string",
              "tags": [ "string" ]
            }
          ]
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Search query required"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.9.4. Dokument-Details abrufen

-   **Route:** `/api/documents/{id}`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft die Details eines spezifischen Dokuments ab.

-   **Path Parameters:**
    -   `id` (integer, required): Die ID des Dokuments.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "document": {
            "id": "integer",
            "name": "string",
            "original_filename": "string",
            "type": "string",
            "category": "string",
            "size": "string",
            "mime_type": "string",
            "created_at": "string",
            "tags": [ "string" ],
            "description": "string | null",
            "metadata": "object | null",
            "is_indexed": "boolean",
            "has_extracted_text": "boolean",
            "access_count": "integer",
            "last_accessed": "string | null"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```

#### 2.9.5. Dokument herunterladen

-   **Route:** `/api/documents/{id}/download`
-   **Methode:** `GET`
-   **Zusammenfassung:** Lädt eine Datei herunter.

-   **Path Parameters:**
    -   `id` (integer, required): Die ID des Dokuments.

-   **Responses:**
    -   `200 OK`: Dateidownload (Binary-Response).
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```
        oder
        ```json
        {
          "error": "File not found on disk"
        }
        ```

#### 2.9.6. Dokument-Metadaten aktualisieren

-   **Route:** `/api/documents/{id}`
-   **Methode:** `PUT`
-   **Zusammenfassung:** Aktualisiert die Metadaten eines Dokuments.

-   **Path Parameters:**
    -   `id` (integer, required): Die ID des Dokuments.

-   **Request Body (JSON):**
    ```json
    {
      "display_name": "string | null",
      "description": "string | null",
      "category": "string | null",
      "tags": [ "string" ] | null
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "Document updated",
          "document": {
            "id": "integer",
            "name": "string",
            "original_filename": "string",
            "type": "string",
            "category": "string",
            "size": "string",
            "mime_type": "string",
            "created_at": "string",
            "tags": [ "string" ],
            "description": "string | null",
            "metadata": "object | null",
            "is_indexed": "boolean",
            "has_extracted_text": "boolean",
            "access_count": "integer",
            "last_accessed": "string | null"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```

#### 2.9.7. Dokument löschen

-   **Route:** `/api/documents/{id}`
-   **Methode:** `DELETE`
-   **Zusammenfassung:** Löscht ein Dokument.

-   **Path Parameters:**
    -   `id` (integer, required): Die ID des Dokuments.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "Document deleted"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```

#### 2.9.8. Dokument in Knowledge Base indizieren

-   **Route:** `/api/documents/{id}/index`
-   **Methode:** `POST`
-   **Zusammenfassung:** Indiziert ein Dokument in die Knowledge Base.

-   **Path Parameters:**
    -   `id` (integer, required): Die ID des Dokuments.

-   **Request Body (JSON):**
    ```json
    {
      "tags": [ "string" ] | null // Optional: Tags für die Indizierung
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "string", // z.B. "2 knowledge documents created"
          "chunks_created": "integer"
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Document has no extractable text for indexing"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Indexing failed: string"
        }
        ```

#### 2.9.9. Speicherstatistiken abrufen

-   **Route:** `/api/documents/storage/stats`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft Speicherstatistiken für den Benutzer ab.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "storage": {
            "used_bytes": "integer",
            "used_human": "string", // Z.B. "1.2 MB"
            "limit_bytes": "integer",
            "limit_human": "string", // Z.B. "500 MB"
            "available_bytes": "integer",
            "usage_percent": "number", // Prozentsatz der Nutzung (z.B. 0.25)
            "document_count": "integer"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

### 2.10. User Knowledge Base (Tag: `User Knowledge Base`)

**Basis-Route:** `/api/knowledge`

#### 2.10.1. Liste aller persönlichen Wissensdokumente

-   **Route:** `/api/knowledge`
-   **Methode:** `GET`
-   **Zusammenfassung:** Listet alle Wissensdokumente des Benutzers auf.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "count": "integer",
          "documents": [
            {
              "id": "string", // UUID
              "title": "string",
              "source_type": "string", // manual, uploaded_file, url, api
              "source_reference": "string | null",
              "tags": [ "string" ] | null,
              "created_at": "string", // ISO 8601 Datum/Zeit
              "updated_at": "string" // ISO 8601 Datum/Zeit
            }
          ]
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.10.2. Neues Wissensdokument erstellen

-   **Route:** `/api/knowledge`
-   **Methode:** `POST`
-   **Zusammenfassung:** Erstellt ein neues Wissensdokument.

-   **Request Body (JSON):**
    ```json
    {
      "title": "string", // Beispiel: "Wichtige Kontakte"
      "content": "string", // Beispiel: "Liste wichtiger Ansprechpartner..."
      "tags": [ "string" ] | null // Beispiel: ["kontakte", "arbeit"]
    }
    ```

-   **Responses:**
    -   `201 Created`:
        ```json
        {
          "status": "success",
          "message": "Knowledge document created",
          "document": {
            "id": "string",
            "title": "string",
            "source_type": "string",
            "source_reference": "string | null",
            "tags": [ "string" ] | null,
            "created_at": "string",
            "updated_at": "string",
            "content": "string",
            "metadata": "object | null"
          }
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Title and content are required"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Failed to create document: string"
        }
        ```

#### 2.10.3. Semantische Suche in der Knowledge Base

-   **Route:** `/api/knowledge/search`
-   **Methode:** `POST`
-   **Zusammenfassung:** Führt eine semantische Ähnlichkeitssuche in der persönlichen Knowledge Base durch.

-   **Request Body (JSON):**
    ```json
    {
      "query": "string", // Die Suchanfrage, Beispiel: "Kontaktdaten von Max"
      "limit": "integer", // Optional, Standard: 5, Max: 20
      "min_score": "number" // Optional, Standard: 0.3, Min: 0.0, Max: 1.0
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "query": "string",
          "count": "integer",
          "results": [
            {
              "document": {
                "id": "string",
                "title": "string",
                "source_type": "string",
                "source_reference": "string | null",
                "tags": [ "string" ] | null,
                "created_at": "string",
                "updated_at": "string"
              },
              "score": "number" // Relevanz-Score (0.0 - 1.0)
            }
          ]
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "Query is required"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Search failed: string"
        }
        ```

#### 2.10.4. Einzelnes Wissensdokument abrufen

-   **Route:** `/api/knowledge/{id}`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft ein einzelnes Wissensdokument ab.

-   **Path Parameters:**
    -   `id` (string, required): Die UUID des Wissensdokuments.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "document": {
            "id": "string",
            "title": "string",
            "source_type": "string",
            "source_reference": "string | null",
            "tags": [ "string" ] | null,
            "created_at": "string",
            "updated_at": "string",
            "content": "string", // Inhalt des Dokuments
            "metadata": "object | null"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```

#### 2.10.5. Wissensdokument aktualisieren

-   **Route:** `/api/knowledge/{id}`
-   **Methode:** `PUT`
-   **Zusammenfassung:** Aktualisiert ein Wissensdokument.

-   **Path Parameters:**
    -   `id` (string, required): Die UUID des Wissensdokuments.

-   **Request Body (JSON):**
    ```json
    {
      "title": "string | null",
      "content": "string | null",
      "tags": [ "string" ] | null
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "Document updated",
          "document": {
            "id": "string",
            "title": "string",
            "source_type": "string",
            "source_reference": "string | null",
            "tags": [ "string" ] | null,
            "created_at": "string",
            "updated_at": "string"
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Update failed: string"
        }
        ```

#### 2.10.6. Wissensdokument löschen (Soft-Delete)

-   **Route:** `/api/knowledge/{id}`
-   **Methode:** `DELETE`
-   **Zusammenfassung:** Löscht ein Wissensdokument (markiert es als inaktiv).

-   **Path Parameters:**
    -   `id` (string, required): Die UUID des Wissensdokuments.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "message": "Document deleted"
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Document not found"
        }
        ```

#### 2.10.7. Statistiken zur persönlichen Knowledge Base

-   **Route:** `/api/knowledge/stats`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft Statistiken zur persönlichen Knowledge Base ab.

-   **Responses:**
    -   `200 OK`:
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
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

### 2.11. User Settings (Tag: `User Settings`)

**Basis-Route:** `/api/user/settings`

#### 2.11.1. E-Mail-Einstellungen des aktuellen Benutzers abrufen

-   **Route:** `/api/user/settings`
-   **Methode:** `GET`
-   **Zusammenfassung:** Ruft die E-Mail-Einstellungen des aktuellen Benutzers ab.

-   **Responses:**
    -   `200 OK`:
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
            "password": "string | null" // Hinweis: Passwort wird hier als Klartext angezeigt, sollte im Frontend nicht direkt gespeichert oder wiederverwendet werden.
          }
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

#### 2.11.2. E-Mail-Einstellungen des Benutzers aktualisieren

-   **Route:** `/api/user/settings`
-   **Methode:** `PUT`
-   **Zusammenfassung:** Aktualisiert die E-Mail-Einstellungen des Benutzers.

-   **Request Body (JSON):**
    ```json
    {
      "pop3Host": "string | null",
      "pop3Port": "integer | null",
      "pop3Encryption": "string | null", // z.B. "ssl", "tls", "none"
      "imapHost": "string | null",
      "imapPort": "integer | null",
      "imapEncryption": "string | null", // z.B. "ssl", "tls", "none"
      "smtpHost": "string | null",
      "smtpPort": "integer | null",
      "smtpEncryption": "string | null", // z.B. "ssl", "tls", "none"
      "smtpUsername": "string | null",
      "smtpPassword": "string | null",
      "emailAddress": "string | null"
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "message": "Settings updated",
          "settings": {
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
        }
        ```
    -   `401 Unauthorized`:
        ```json
        {
          "error": "Not authenticated"
        }
        ```

### 2.12. Workflow Management (Tag: `Workflow Management`)

**Basis-Route:** `/api/workflow`

#### 2.12.1. Workflow aus User-Intent erstellen

-   **Route:** `/api/workflow/create`
-   **Methode:** `POST`
-   **Zusammenfassung:** Erstellt Workflow aus User-Intent.
-   **Beschreibung:** Der Personal Assistant analysiert den Intent, prüft Tool-Verfügbarkeit, plant den Workflow und startet die Ausführung.

-   **Request Body (JSON):**
    ```json
    {
      "intent": "string", // Beispiel: "Such mir eine Wohnung in Berlin Mitte für 1500€"
      "sessionId": "string" // Eindeutige ID der Session
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "string", // "created"
          "workflow_id": "integer",
          "session_id": "string",
          "steps_count": "integer",
          "missing_tools": [ "string" ], // Array von fehlenden Tools (kann leer sein, da DevAgent Tools dynamisch erstellt)
          "message": "string" // "Workflow erstellt und wird ausgeführt."
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string", // "Intent and sessionId are required"
          "message": "string | null"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Workflow creation failed",
          "message": "string" // Fehlermeldung
        }
        ```

#### 2.12.2. Workflow-Status abrufen

-   **Route:** `/api/workflow/status/{sessionId}`
-   **Methode:** `GET`
-   **Zusammenfassung:** Holt den Workflow-Status für eine Session.

-   **Path Parameters:**
    -   `sessionId` (string, required): Die eindeutige ID der Workflow-Session.

-   **Responses:**
    -   `200 OK`:
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
              "status": "string", // pending, running, completed, failed, cancelled
              "requires_confirmation": "boolean",
              "result": "object | null", // Ergebnis des Steps
              "error": "string | null" // Fehlermeldung des Steps
            }
          ],
          "created_at": "string", // ISO 8601 Datum/Zeit
          "completed_at": "string | null" // ISO 8601 Datum/Zeit
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Workflow not found"
        }
        ```

#### 2.12.3. Wartenden Step bestätigen oder ablehnen

-   **Route:** `/api/workflow/confirm/{workflowId}`
-   **Methode:** `POST`
-   **Zusammenfassung:** Bestätigt oder lehnt einen wartenden Workflow-Step ab.

-   **Path Parameters:**
    -   `workflowId` (integer, required): Die ID des Workflows.

-   **Request Body (JSON):**
    ```json
    {
      "confirmed": "boolean" // true für Bestätigung, false für Ablehnung
    }
    ```

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "status": "success",
          "confirmed": "boolean",
          "workflow_status": "string" // Der neue Status des Workflows
        }
        ```
    -   `400 Bad Request`:
        ```json
        {
          "error": "string", // Z.B. "Workflow is not waiting for confirmation" oder "confirmed parameter is required"
          "current_status": "string | null"
        }
        ```
    -   `404 Not Found`:
        ```json
        {
          "error": "Workflow not found"
        }
        ```
    -   `500 Internal Server Error`:
        ```json
        {
          "error": "Confirmation failed",
          "message": "string"
        }
        ```

#### 2.12.4. Alle Workflows auflisten

-   **Route:** `/api/workflow/list`
-   **Methode:** `GET`
-   **Zusammenfassung:** Listet alle Workflows auf.

-   **Query Parameters:**
    -   `status` (string, optional): Filtert Workflows nach Status (z.B. `running`, `completed`, `failed`).
    -   `limit` (integer, optional, default: `20`): Maximale Anzahl der zurückgegebenen Workflows.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "workflows": [
            {
              "id": "integer",
              "session_id": "string",
              "user_intent": "string", // Gekürzter User-Intent
              "status": "string",
              "steps_count": "integer",
              "current_step": "integer | null",
              "created_at": "string", // ISO 8601 Datum/Zeit
              "completed_at": "string | null" // ISO 8601 Datum/Zeit
            }
          ],
          "count": "integer"
        }
        ```

#### 2.12.5. Verfügbare Tools und Capabilities auflisten

-   **Route:** `/api/workflow/capabilities`
-   **Methode:** `GET`
-   **Zusammenfassung:** Listet die vom System unterstützten Tools und deren Capabilities auf.

-   **Responses:**
    -   `200 OK`:
        ```json
        {
          "tools": [ "string" ], // Array von Tool-Namen
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

---

## 3. Fehlerbehandlung (General)

Die API gibt bei Fehlern im Allgemeinen JSON-Responses mit einem `error`-Feld und einer beschreibenden Nachricht zurück. Häufige HTTP-Statuscodes sind:

-   `400 Bad Request`: Ungültige Eingabedaten.
-   `401 Unauthorized`: Authentifizierung fehlgeschlagen oder nicht vorhanden.
-   `403 Forbidden`: Authentifiziert, aber keine Berechtigung für die Aktion.
-   `404 Not Found`: Die angeforderte Ressource existiert nicht.
-   `429 Too Many Requests`: Rate Limit überschritten.
-   `500 Internal Server Error`: Ein unerwarteter Serverfehler ist aufgetreten.
-   `503 Service Unavailable`: Der Service ist temporär nicht verfügbar (z.B. Datenbankverbindung).

In der `prod`-Umgebung werden generische Fehlermeldungen zurückgegeben, um Information Disclosure zu vermeiden. In der `dev`-Umgebung können detailliertere Fehlermeldungen, einschließlich Stack Traces, erscheinen.

---

## 4. Frontend-Best Practices

-   **Keine manuellen Cookie-Operationen**: Aufgrund von `HttpOnly` können und sollten Frontend-Anwendungen nicht versuchen, die Authentifizierungs-Cookies zu lesen oder zu schreiben. Der Browser erledigt dies automatisch.
-   **Sichere Redirects nach OAuth**: Nach dem Google OAuth-Flow wird der Benutzer zu einer definierten Frontend-URL weitergeleitet. Das Frontend sollte bereit sein, diese URL zu empfangen und den Anmeldevorgang abzuschließen. Fehlermeldungen können als Query-Parameter angehängt werden.
-   **HTTPS in Produktion**: Stellen Sie unbedingt sicher, dass Ihr Frontend in der Produktion immer über HTTPS ausgeliefert wird. Dies ist entscheidend für die `Secure`-Cookie-Einstellung und die allgemeine Sicherheit.
-   **Error Handling**: Implementieren Sie robustes Error Handling im Frontend, um verschiedene HTTP-Statuscodes und JSON-Fehlerantworten zu verarbeiten und dem Benutzer aussagekräftiges Feedback zu geben.
-   **Rate Limiting**: Berücksichtigen Sie, dass einige Endpunkte Rate Limiting haben (`/api/login`, `/api/password/request-reset`). Informieren Sie den Benutzer über `retry_after`-Header oder Fehlermeldungen.
-   **Benutzerfreundliche Status-Updates**: Nutzen Sie die `/api/agent/status/{sessionId}`-Endpunkte, um den Benutzer über den Fortschritt und das Ergebnis von asynchronen AI-Agenten-Jobs zu informieren.
-   **Input-Validierung**: Führen Sie clientseitige Validierungen durch, um ungültige Anfragen an das Backend zu vermeiden und die Benutzerfreundlichkeit zu erhöhen. Die Backend-Validierung ist die ultimative Autorität, aber clientseitige Validierung verbessert die UX.

---