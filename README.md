# Projekt-Dokumentation

Diese README-Datei bietet eine detaillierte Dokumentation des Quellcodes dieses Symfony-Projekts. Sie beschreibt die Architektur, die Funktionalitäten der einzelnen Komponenten (Controller, Services, Entitäten, Tools, Listener) und wichtige Implementierungsdetails.

## Inhaltsverzeichnis

1.  [Projektübersicht](#1-projektübersicht)
2.  [Architektur](#2-architektur)
3.  [Verzeichnisse und Dateien](#3-verzeichnisse-und-dateien)
    *   [src/Controller](#srccontroller)
    *   [src/DataFixtures](#srcdatafixtures)
    *   [src/DTO](#srcdto)
    *   [src/Entity](#srcentity)
    *   [src/EventListener](#srceventlistener)
    *   [src/Kernel.php](#srckernelphp)
    *   [src/Repository](#srcrepository)
    *   [src/Security](#srcsecurity)
    *   [src/Service](#srcservice)
    *   [src/Tool](#srctool)
    *   [config](#config)
4.  [Wichtige Funktionalitäten](#4-wichtige-funktionalitäten)
    *   [Authentifizierung & Autorisierung](#authentifizierung--autorisierung)
    *   [Passwort-Management](#passwort-management)
    *   [Sicherheits-Header](#sicherheits-header)
    *   [Fehlerbehandlung](#fehlerbehandlung)
    *   [AI-Agent Integration](#ai-agent-integration)
    *   [Dateisicherheit](#dateisicherheit)
    *   [Knowledge Base Indexierung](#knowledge-base-indexierung)
    *   [Sandbox-Ausführung](#sandbox-ausführung)
    *   [Code-Analyse-Tool](#code-analyse-tool)
    *   [Deployment-Tool](#deployment-tool)
5.  [Setup und Installation](#5-setup-und-installation)
6.  [Tests](#6-tests)
7.  [Nutzung der API (Swagger/OpenAPI)](#7-nutzung-der-api-swaggeropenapi)

---

## 1. Projektübersicht

Dieses Projekt ist eine Symfony-Anwendung, die als Backend für eine API dient. Es beinhaltet Funktionalitäten zur Benutzerauthentifizierung, -registrierung und Passwortverwaltung. Darüber hinaus integriert es einen AI-Agenten für Dateigenerierung und bietet Tools für Code-Analyse, Sandbox-Ausführung und Deployment. Ein starker Fokus liegt auf Sicherheit und Wartbarkeit.

## 2. Architektur

Die Anwendung folgt dem Model-View-Controller (MVC)-Pattern, wie es typisch für Symfony ist, wobei der "View"-Teil hier primär über JSON-Responses für eine API bereitgestellt wird. Die Geschäftslogik ist in Services gekapselt, und die Datenbankinteraktion erfolgt über Doctrine-Entitäten und -Repositories.

Besondere Architekturaspekte:
*   **API-zentriert:** Alle Interaktionen erfolgen über RESTful-API-Endpunkte, die mit OpenAPI-Annotationen dokumentiert sind.
*   **Sicherheitsfokus:** Umfassende Sicherheitsmechanismen sind implementiert, darunter JWT-Authentifizierung, Refresh-Tokens, Rate Limiting, strikte Sicherheits-Header und sichere Dateiverwaltung.
*   **AI-Agent Integration:** Das Projekt erweitert die Standard-Symfony-Funktionalität um AI-Agenten-Fähigkeiten, die es ermöglichen, dynamisch Code zu generieren, Dokumentation zu indizieren und Code in einer Sandbox auszuführen.
*   **Modularität:** Services und Tools sind klar getrennt und folgen dem Single Responsibility Principle.

## 3. Verzeichnisse und Dateien

Hier ist eine detaillierte Beschreibung der wichtigsten Verzeichnisse und Dateien im Projekt.

### src/Controller

Enthält die Controller, die HTTP-Anfragen entgegennehmen, die Geschäftslogik an Services delegieren und HTTP-Antworten zurückgeben.

*   `AuthController.php`
    *   **Funktion:** Verwaltet alle Authentifizierungs- und Autorisierungs-bezogenen API-Endpunkte.
    *   **Endpunkte:**
        *   `/api/login` (POST): Authentifiziert einen Benutzer, setzt JWT Access- und Refresh-Token als Cookies. Inklusive Rate Limiting.
        *   `/api/logout` (POST): Löscht Access- und Refresh-Token-Cookies.
        *   `/api/register` (POST): Registriert einen neuen Benutzer.
        *   `/api/password/request-reset` (POST): Fordert einen Passwort-Reset an und sendet eine E-Mail mit einem Token. Inklusive Rate Limiting.
        *   `/api/password/reset` (POST): Setzt das Passwort mit einem gültigen Reset-Token zurück.
        *   `/api/password/change` (POST): Ermöglicht einem angemeldeten Benutzer, sein eigenes Passwort zu ändern.
    *   **Abhängigkeiten:** `AuthService`, `PasswordService`, `JWTTokenManagerInterface`, `UserRepository`, `UserPasswordHasherInterface`, `RefreshTokenManagerInterface`, `RefreshTokenGeneratorInterface`, `EntityManagerInterface`, `RateLimiterFactory`.
    *   **Sicherheitsaspekte:** Implementiert Rate Limiting für Login- und Passwort-Reset-Vorgänge, verwendet sichere Token-Generierung und Hashing.

*   `FileGeneratorController.php`
    *   **Funktion:** Stellt einen API-Endpunkt bereit, um den AI-Agenten zur Dateigenerierung aufzurufen und den Status des Agenten zu verfolgen.
    *   **Endpunkte:**
        *   `/api/generate-file` (POST): Nimmt einen Prompt entgegen und leitet ihn an den AI-Agenten weiter, um Dateien zu generieren. Gibt den Generierungsstatus und eventuell erstellte Dateien zurück.
        *   `/api/index-knowledge` (POST): Löst die manuelle Indexierung der Knowledge Base aus.
    *   **Abhängigkeiten:** `LoggerInterface`, `AgentStatusService`, `AgentInterface` (für `ai.agent.file_generator`), `KnowledgeIndexerTool`.
    *   **Besonderheiten:** Nutzt `AgentStatusService` zur Echtzeit-Statusverfolgung des AI-Agenten. Behandelt die Ausgabe des Agenten und sucht nach neu erstellten Dateien im `generated_code`-Verzeichnis.

*   `HealthCheckController.php`
    *   **Funktion:** Bietet einen einfachen Gesundheitscheck-Endpunkt für die Anwendung.
    *   **Endpunkte:**
        *   `/health` (GET): Prüft die Datenbankverbindung und gibt den Systemstatus zurück (`healthy` oder `unhealthy`).
    *   **Abhängigkeiten:** `Doctrine\DBAL\Connection`.
    *   **Besonderheiten:** Ideal für Load Balancer und Monitoring-Systeme.

### src/DataFixtures

Enthält Klassen zum Laden von initialen Daten in die Datenbank.

*   `AppFixtures.php`
    *   **Funktion:** Lädt Beispieldaten (Benutzer, Kategorie, Post) in die Datenbank. Nützlich für Entwicklung und Tests.
    *   **Abhängigkeiten:** `UserPasswordHasherInterface`, `ObjectManager`.
    *   **Besonderheiten:** Erstellt einen Standardbenutzer (`test@example.com` / `password123`) und einen Beispielpost.

### src/DTO

Enthält Data Transfer Objects (DTOs), die zur Strukturierung von Eingabedaten für API-Anfragen verwendet werden.

*   `AgentPromptRequest.php`
    *   **Funktion:** DTO für Anfragen an den AI-Agenten, die einen `prompt` enthalten.
    *   **Validierung:** `#[Assert\NotBlank]` stellt sicher, dass der Prompt nicht leer ist.

*   `RegisterRequestDTO.php`
    *   **Funktion:** DTO für die Registrierung neuer Benutzer.
    *   **Properties:** `email`, `password`. `readonly` sorgt für Unveränderlichkeit nach der Initialisierung.

### src/Entity

Definiert die Doctrine ORM-Entitäten, die die Datenbanktabellen repräsentieren.

*   `RefreshToken.php`
    *   **Funktion:** Erweitert `Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken` zur Speicherung von Refresh-Tokens.
    *   **Mapping:** `#[ORM\Entity]`, `#[ORM\Table(name: 'refresh_tokens')]`.

*   `User.php`
    *   **Funktion:** Repräsentiert die Benutzer-Entität. Implementiert `UserInterface` und `PasswordAuthenticatedUserInterface` für Symfony Security.
    *   **Properties:** `id`, `email`, `roles`, `password`, `resetTokenHash`, `resetTokenExpiresAt`. `resetToken` (transient) wird für die E-Mail-Kommunikation verwendet.
    *   **Methoden:** Enthält Methoden zum Abrufen und Setzen von Benutzerdaten, Rollen, Passwort-Hashing, sowie zur Verwaltung von Passwort-Reset-Tokens (`setResetTokenPlain`, `verifyResetToken`, `isResetTokenValid`, `clearResetToken`).
    *   **Sicherheitsaspekte:** Speichert Passwort-Reset-Tokens nur als Hash und mit Ablaufdatum.

### src/EventListener

Enthält Event Listener, die auf bestimmte Symfony-Events reagieren, um globale Logik auszuführen.

*   `ExceptionListener.php`
    *   **Funktion:** Fängt alle unbehandelten Exceptions in der Anwendung ab.
    *   **Verhalten:** Loggt detaillierte Fehlerinformationen (für interne Nutzung) und gibt eine benutzerfreundliche JSON-Fehlerantwort zurück. In der `prod`-Umgebung werden keine Details offengelegt, in `dev` werden sie zum Debugging bereitgestellt.
    *   **Sicherheitsaspekte:** Verhindert Information Disclosure in der Produktion.

*   `SecurityHeadersListener.php`
    *   **Funktion:** Fügt wichtige Sicherheits-HTTP-Header zu allen Responses hinzu.
    *   **Header:** `Strict-Transport-Security` (HSTS), `Content-Security-Policy` (CSP), `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`.
    *   **Besonderheiten:**
        *   Erzwingt HTTPS-Redirect in `prod`-Umgebung.
        *   Spezifische, weniger restriktive CSP für den `/api/doc` (Swagger UI) Endpunkt, ansonsten sehr strikt.
        *   Entfernt identifizierende Server-Header (`Server`, `X-Powered-By`).
    *   **Sicherheitsaspekte:** Hilft, eine Vielzahl von Web-Sicherheitslücken zu mindern (XSS, Clickjacking, MIME-Sniffing, etc.).

### src/Kernel.php

Der Kern der Symfony-Anwendung.

*   `Kernel.php`
    *   **Funktion:** Startpunkt der Symfony-Anwendung, nutzt `MicroKernelTrait`.

### src/Repository

Enthält Doctrine Repository-Klassen für den Zugriff auf Entitätsdaten.

*   `UserRepository.php`
    *   **Funktion:** Stellt Methoden für den Datenbankzugriff auf `User`-Entitäten bereit.
    *   **Implementiert:** `PasswordUpgraderInterface` zum automatischen Re-Hashing von Passwörtern bei Bedarf.
    *   **Methoden:** `upgradePassword`, sowie generische `find`, `findOneBy`, `findAll`, `findBy`.

### src/Security

Enthält Klassen, die für die Authentifizierung und Autorisierung zuständig sind.

*   `AppCustomAuthenticator.php`
    *   **Funktion:** Ein benutzerdefinierter Authenticator für den `/api/login` Endpunkt (klassische E-Mail/Passwort-Authentifizierung).
    *   **Abhängigkeiten:** `JWTTokenManagerInterface`.
    *   **Besonderheiten:** Erstellt bei erfolgreichem Login ein JWT.

*   `JwtTokenAuthenticator.php`
    *   **Funktion:** Ein Authenticator, der JWTs aus einem Cookie (`BEARER`) extrahiert und den Benutzer authentifiziert.
    *   **Implementiert:** `AuthenticationEntryPointInterface`, um eine korrekte 401-Antwort zu liefern, wenn kein Token vorhanden ist.
    *   **Abhängigkeiten:** `JWTTokenManagerInterface`, `UserProviderInterface`.
    *   **Sicherheitsaspekte:** Überprüft die Gültigkeit des Tokens und behandelt Fehler bei ungültigen oder abgelaufenen Tokens.

### src/Service

Enthält die Geschäftslogik der Anwendung.

*   `AgentStatusService.php`
    *   **Funktion:** Ein einfacher Service zum Sammeln und Bereitstellen von Statusmeldungen, die während der Ausführung eines AI-Agenten generiert werden.
    *   **Methoden:** `addStatus`, `getStatuses`, `clearStatuses`.
    *   **Besonderheiten:** Nützlich, um dem Frontend Live-Updates über den Fortschritt eines langlaufenden AI-Vorgangs zu geben.

*   `AuditLoggerService.php`
    *   **Funktion:** Zentraler Service für das Audit-Logging von sicherheitsrelevanten Ereignissen.
    *   **Abhängigkeiten:** `LoggerInterface`, `RequestStack`.
    *   **Methoden:** `logSecurityEvent`, `logAuthAttempt`, `logUnauthorizedAccess`, `logSuspiciousActivity`.
    *   **Sicherheitsaspekte:** Erfasst wichtige Kontextinformationen wie IP-Adresse, User-Agent, Methode, Pfad und Zeitstempel. Loggt auf dem `warning`-Level in einem dedizierten `security`-Kanal.

*   `AuthService.php`
    *   **Funktion:** Kapselt die Geschäftslogik für die Benutzerregistrierung.
    *   **Abhängigkeiten:** `EntityManagerInterface`, `UserPasswordHasherInterface`.
    *   **Methoden:** `register`.
    *   **Sicherheitsaspekte:** Prüft auf existierende E-Mails, hashst Passwörter.

*   `FileSecurityService.php`
    *   **Funktion:** Stellt Funktionen zur sicheren Validierung, Speicherung und Löschung von hochgeladenen Dateien bereit.
    *   **Konstanten:** `ALLOWED_MIMES`, `MAX_FILE_SIZE`, `MIN_IMAGE_DIMENSION`, `MAX_IMAGE_DIMENSION`.
    *   **Methoden:**
        *   `validateUploadedFile`: Führt umfangreiche Prüfungen durch (Größe, MIME-Type (Client- & Server-seitig), Bild-Header, Dimensionen, verdächtige Dateinamen).
        *   `validateFilename`: Prüft auf Polyglot-Dateinamen und gefährliche Extensions.
        *   `saveUploadedFile`: Speichert eine validierte Datei sicher mit einem eindeutigen, sluggifizierten Dateinamen.
        *   `secureDelete`: Löscht eine Datei sicher, indem der Inhalt mit Zufallsdaten überschrieben wird, um Wiederherstellung zu erschweren, und verhindert Path Traversal.
    *   **Sicherheitsaspekte:** Extrem wichtig für die Abwehr von Datei-Upload-Schwachstellen (z.B. Remote Code Execution, DoS durch große Dateien, Bild-Magick-Exploits).

*   `PasswordService.php`
    *   **Funktion:** Verwaltet die Logik für Passwort-Reset- und Passwort-Änderungsprozesse.
    *   **Abhängigkeiten:** `UserRepository`, `EntityManagerInterface`, `UserPasswordHasherInterface`, `MailerInterface`.
    *   **Methoden:**
        *   `requestPasswordReset`: Generiert einen sicheren Token, speichert den Hash und das Ablaufdatum und sendet eine Reset-E-Mail. Verhindert Benutzer-Enumeration, indem immer eine "erfolgreich gesendet"-Meldung zurückgegeben wird, unabhängig davon, ob die E-Mail existiert.
        *   `resetPassword`: Setzt das Passwort eines Benutzers mit einem gültigen Token zurück.
        *   `changePassword`: Ermöglicht einem angemeldeten Benutzer, sein Passwort zu ändern.
        *   `sendResetEmail`: Hilfsfunktion zum Versenden der Passwort-Reset-E-Mail.
    *   **Sicherheitsaspekte:** Verwendet sichere, gehashte Reset-Tokens mit Ablaufdatum, prüft Token-Gültigkeit, implementiert Passwort-Komplexitätsregeln (Minimallänge).

### src/Tool

Enthält benutzerdefinierte Tools, die vom AI-Agenten verwendet werden können. Diese Tools sind Symfony Services, die mit dem `#[AsTool]`-Attribut annotiert sind.

*   `AnalyzeCodeTool.php`
    *   **Funktion:** Analysiert PHP-Dateien im Projekt und liefert Metriken wie Zeilenanzahl, Dateigröße und optional den Dateiinhalte (begrenzt).
    *   **Abhängigkeiten:** `KernelInterface`, `Finder`.
    *   **Methoden:** `__invoke`.
    *   **Besonderheiten:** Ignoriert Standardverzeichnisse wie `vendor`, `node_modules`, `var`. Implementiert eine Heuristik, um binäre von Textdateien zu unterscheiden. Beschränkt die Größe der eingelesenen Dateien, um Speicherprobleme zu vermeiden.

*   `CodeSaverTool.php`
    *   **Funktion:** Speichert den bereitgestellten Code in einer Datei im `generated_code`-Verzeichnis.
    *   **Abhängigkeiten:** `LoggerInterface`.
    *   **Methoden:** `__invoke`.
    *   **Sicherheitsaspekte:** Verhindert Path Traversal mit `basename()`. Schränkt erlaubte Dateiendungen ein. Stellt sicher, dass das Zielverzeichnis existiert.

*   `DeployGeneratedCodeTool.php`
    *   **Funktion:** Generiert ein Shell-Skript, um Dateien aus dem `generated_code`-Verzeichnis an bestimmte Zielorte im Projekt zu kopieren.
    *   **Abhängigkeiten:** `KernelInterface`, `Filesystem`, `LoggerInterface`.
    *   **Methoden:** `__invoke`.
    *   **Sicherheitsaspekte:** **WICHTIG:** Das Skript muss vom Benutzer manuell überprüft und ausgeführt werden, um unbeabsichtigte oder bösartige Änderungen am Produktionscode zu verhindern. Implementiert Path Traversal Prevention für Quell- und Zielpfade.

*   `KnowledgeIndexerTool.php`
    *   **Funktion:** Indiziert RST- und Markdown-Dokumentationsdateien aus dem `knowledge_base`-Verzeichnis in einen Vektor-Store.
    *   **Abhängigkeiten:** `PlatformInterface`, `StoreInterface`, `LoggerInterface`, `Finder`, `TextFileLoader`, `TextSplitTransformer`, `Vectorizer`.
    *   **Methoden:** `__invoke`.
    *   **Besonderheiten:** Nutzt `TextSplitTransformer` um Dokumente in kleinere Chunks aufzuteilen und `Vectorizer` von der AI Platform, um diese Chunks in Vektoren umzuwandeln. Verarbeitet in Batches, um API-Limits zu respektieren und Fehler zu vermeiden.

*   `SandboxExecutorTool.php`
    *   **Funktion:** Führt eine PHP-Datei aus dem `generated_code`-Verzeichnis in einer isolierten Docker-Sandbox aus.
    *   **Abhängigkeiten:** `KernelInterface`, `Filesystem`, `LoggerInterface`, `Process`.
    *   **Methoden:** `__invoke`.
    *   **Sicherheitsaspekte:** Erstellt einen temporären Docker-Kontext, kopiert nur benötigte Dateien (composer.json, lock, die auszuführende Datei) und führt `composer install` sowie das PHP-Skript in einem Wegwerf-Container aus. Verhindert so, dass bösartiger Code das Host-System beeinträchtigt. Bereinigt temporäre Verzeichnisse.

### config

Enthält die Konfigurationsdateien der Symfony-Anwendung.

*   `bundles.php`
    *   **Funktion:** Registriert alle Bundles, die in der Anwendung verwendet werden.
    *   **Besonderheiten:** Listet wichtige Bundles wie `LexikJWTAuthenticationBundle`, `GesdinetJWTRefreshTokenBundle`, `NelmioApiDocBundle` und `AiBundle` auf.

*   `preload.php`
    *   **Funktion:** Ermöglicht PHP OPcache Preloading, um die Anwendungsleistung in der Produktion zu verbessern.

## 4. Wichtige Funktionalitäten

### Authentifizierung & Autorisierung

Das Projekt verwendet JWT (JSON Web Tokens) für die Authentifizierung.
*   **Login:** Benutzer melden sich über `/api/login` an. Bei Erfolg erhalten sie ein Access Token (als `BEARER` Cookie) und ein Refresh Token (als `refresh_token` Cookie).
*   **Access Token:** Kurzlebig (z.B. 1 Stunde), wird für jede geschützte Anfrage gesendet.
*   **Refresh Token:** Langlebiger, wird verwendet, um ein neues Access Token zu erhalten, wenn das alte abgelaufen ist (durch das `gesdinet/jwt-refresh-token-bundle` gehandhabt).
*   **Authenticator:** `JwtTokenAuthenticator` verarbeitet das `BEARER` Cookie.
*   **Rate Limiting:** `/api/login` und `/api/password/request-reset` sind durch Rate Limiter geschützt, um Brute-Force-Angriffe zu erschweren.

### Passwort-Management

Der `PasswordService` bietet umfassende Funktionen:
*   **Registrierung:** `AuthService::register` erstellt neue Benutzer mit gehashten Passwörtern.
*   **Passwort-Reset:** `PasswordService::requestPasswordReset` sendet eine E-Mail mit einem einmaligen, zeitlich begrenzten Token. Der Token-Hash wird in der Datenbank gespeichert, um Angriffe zu erschweren. `PasswordService::resetPassword` ermöglicht das Setzen eines neuen Passworts mit diesem Token.
*   **Passwort-Änderung:** Angemeldete Benutzer können ihr Passwort über `PasswordService::changePassword` ändern.
*   **Sicherheit:** Passwörter werden immer gehasht gespeichert. Reset-Tokens sind nur für eine begrenzte Zeit gültig.

### Sicherheits-Header

Der `SecurityHeadersListener` ist entscheidend für die Absicherung der Anwendung gegen gängige Web-Angriffe:
*   **HSTS (Strict-Transport-Security):** Erzwingt HTTPS für alle zukünftigen Anfragen (nur bei HTTPS-Verbindung).
*   **CSP (Content-Security-Policy):** Definiert, welche Ressourcen von wo geladen werden dürfen, um XSS zu verhindern. Eine speziell angepasste CSP wird für die Swagger UI bereitgestellt.
*   **X-Frame-Options: DENY:** Verhindert Clickjacking.
*   **X-Content-Type-Options: nosniff:** Verhindert MIME-Sniffing.
*   **X-XSS-Protection: 1; mode=block:** Aktiviert XSS-Filter im Browser.
*   **Referrer-Policy: strict-origin-when-cross-origin:** Kontrolliert, welche Referrer-Informationen gesendet werden.
*   **Permissions-Policy:** Deaktiviert unerwünschte Browser-APIs.
*   **Header-Bereinigung:** Entfernt `Server` und `X-Powered-By` Header, um Fingerprinting zu erschweren.

### Fehlerbehandlung

Der `ExceptionListener` sorgt für eine robuste Fehlerbehandlung:
*   Alle Exceptions werden zentral geloggt.
*   In der `prod`-Umgebung erhalten Benutzer nur generische Fehlermeldungen, um Information Disclosure zu verhindern.
*   In der `dev`-Umgebung werden detaillierte Fehlerinformationen für das Debugging bereitgestellt.

### AI-Agent Integration

Das Projekt integriert `symfony/ai` Bundles, um AI-Agenten für bestimmte Aufgaben zu nutzen:
*   Der `FileGeneratorController` orchestriert die Kommunikation mit einem AI-Agenten, der auf "file_generator" getauft ist.
*   `AgentStatusService` ermöglicht die Rückmeldung von Prozess-Status an das Frontend.
*   Custom Tools (`AnalyzeCodeTool`, `CodeSaverTool`, `DeployGeneratedCodeTool`, `KnowledgeIndexerTool`, `SandboxExecutorTool`) erweitern die Fähigkeiten des AI-Agenten, ihm zu erlauben, Code zu analysieren, zu speichern, auszuführen und Wissen zu indexieren.

### Dateisicherheit

Der `FileSecurityService` ist eine kritische Komponente für jede Anwendung, die Dateiuploads erlaubt:
*   **Whitelisting:** Nur explizit erlaubte MIME-Typen sind zulässig.
*   **Größenbeschränkung:** Uploads sind auf 5MB begrenzt.
*   **MIME-Type-Prüfung:** Client-seitige und Server-seitige (`finfo`) MIME-Type-Prüfung, um Manipulationen zu erkennen.
*   **Bildvalidierung:** `getimagesize` wird verwendet, um die Integrität von Bilddateien zu prüfen und Dimensionen zu validieren.
*   **Dateinamen-Validierung:** Verhindert Polyglot-Dateinamen (z.B. `.php.jpg`) und andere verdächtige Muster.
*   **Sichere Speicherung:** Generiert eindeutige, sluggifizierte Dateinamen, um Kollisionen und Directory Traversal zu vermeiden.
*   **Sicheres Löschen:** Überschreibt Dateiinhalte mit Zufallsdaten, um die Wiederherstellung zu erschweren, und prüft auf Path Traversal.

### Knowledge Base Indexierung

Das `KnowledgeIndexerTool` ermöglicht es dem AI-Agenten, die interne Dokumentation zu "lesen" und für seine Antworten zu nutzen:
*   Scannt das `knowledge_base/`-Verzeichnis nach `.md` und `.rst` Dateien.
*   Lädt die Dokumente und teilt sie in kleinere "Chunks" auf (`TextSplitTransformer`).
*   Vektorisiert diese Chunks mithilfe der AI Platform (`Vectorizer`).
*   Speichert die Vektor-Repräsentationen im konfigurierten Vektor-Store, sodass der AI-Agent relevante Informationen abrufen kann (Retrieval Augmented Generation - RAG).

### Sandbox-Ausführung

Das `SandboxExecutorTool` bietet eine sichere Umgebung zur Ausführung von generiertem PHP-Code:
*   Erstellt einen temporären Docker-Container.
*   Kopiert nur notwendige Projektteile (`composer.json`, `composer.lock`, die auszuführende PHP-Datei) in den Container.
*   Führt `composer install` und dann das PHP-Skript in dieser isolierten Umgebung aus.
*   Sorgt für die Bereinigung des temporären Verzeichnisses nach der Ausführung.
*   **Sicherheitsaspekt:** Schützt das Host-System vor potenziell schädlichem oder fehlerhaftem generiertem Code.

### Code-Analyse-Tool

Das `AnalyzeCodeTool` hilft dem AI-Agenten, den bestehenden Code zu verstehen:
*   Durchsucht `src/` und `config/` nach PHP-Dateien.
*   Ermittelt Zeilenanzahl, Dateigröße und gibt optional den Inhalt (gekürzt) zurück.
*   Ignoriert automatisch `vendor/`, `node_modules/` etc.

### Deployment-Tool

Das `DeployGeneratedCodeTool` unterstützt bei der Integration von generiertem Code:
*   Generiert ein Bash-Skript, das Dateien aus `generated_code/` in die Zielverzeichnisse des Projekts kopiert.
*   **Kritischer Hinweis:** Dieses Skript muss **immer** manuell vom Entwickler überprüft und ausgeführt werden, um sicherzustellen, dass nur beabsichtigte Änderungen vorgenommen werden.

## 5. Setup und Installation

Dieses Kapitel beschreibt die Schritte zur Einrichtung und Installation des Projekts.

### Voraussetzungen

*   PHP 8.2 oder höher
*   Composer
*   Docker (für Sandbox-Ausführung und Tests)
*   Symfony CLI (optional, aber empfohlen)

### Schritte

1.  **Repository klonen:**
    ```bash
    git clone [URL_DEINES_REPOS]
    cd [DEIN_PROJEKTVERZEICHNIS]
    ```

2.  **Composer-Abhängigkeiten installieren:**
    ```bash
    composer install
    ```

3.  **Umgebungsvariablen konfigurieren:**
    Kopiere `.env` nach `.env.local` und passe die Werte an. Insbesondere Datenbank-Zugangsdaten und Mailer-Konfiguration.
    ```bash
    cp .env .env.local
    ```

4.  **Datenbank einrichten:**
    ```bash
    php bin/console doctrine:database:create
    php bin/console doctrine:migrations:migrate
    php bin/console doctrine:fixtures:load
    ```

5.  **JWT-Schlüssel generieren:**
    ```bash
    php bin/console lexik:jwt:generate-keypair
    ```

6.  **Knowledge Base indizieren (für AI-Agent):**
    Wenn Sie die AI-Agenten-Funktionalität nutzen möchten, indizieren Sie die Knowledge Base:
    ```bash
    # Dies ist ein Symfony-Command, der den KnowledgeIndexerTool aufruft
    php bin/console app:ai:index-knowledge
    ```
    Alternativ kann der `/api/index-knowledge` Endpunkt genutzt werden.

7.  **Webserver starten (Symfony CLI):**
    ```bash
    symfony serve
    ```
    Die Anwendung ist nun unter `https://127.0.0.1:8000` (oder ähnlich) erreichbar.

## 6. Tests

Das Projekt sollte mit PHPUnit getestet werden.

### PHPUnit ausführen

```bash
php bin/phpunit
```

Es wird erwartet, dass jede generierte Produktivcode-Datei eine entsprechende Testdatei im `tests/` Verzeichnis hat, die eine Code-Abdeckung von mindestens 70% erreicht. Mocks und Stubs sollten verwendet werden, um Abhängigkeiten zu isolieren.

## 7. Nutzung der API (Swagger/OpenAPI)

Die API ist vollständig mit OpenAPI-Annotationen dokumentiert und kann über die Swagger UI erkundet werden.

*   **Swagger UI aufrufen:**
    Navigieren Sie im Browser zu `/api/doc` (z.B. `https://127.0.0.1:8000/api/doc`).

Hier können Sie alle verfügbaren Endpunkte, deren Parameter und erwartete Responses einsehen und direkt testen.
