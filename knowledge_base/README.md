# Projekt Dokumentation: AI-Agent Symfony Anwendung

Dieses Dokument bietet eine detaillierte Übersicht über die Struktur und die Funktionalitäten der AI-Agent Symfony Anwendung. Es beschreibt die einzelnen Komponenten im `src`-Verzeichnis und die wichtigsten Konfigurationsdateien.

## Verzeichnisstruktur

```
.
├── config/
│   ├── bundles.php
│   └── preload.php
├── src/
│   ├── Controller/
│   │   ├── AuthController.php
│   │   ├── FileGeneratorController.php
│   │   └── HealthCheckController.php
│   ├── DataFixtures/
│   │   └── AppFixtures.php
│   ├── DTO/
│   │   ├── AgentPromptRequest.php
│   │   └── RegisterRequestDTO.php
│   ├── Entity/
│   │   ├── RefreshToken.php
│   │   └── User.php
│   ├── EventListener/
│   │   ├── ExceptionListener.php
│   │   └── SecurityHeadersListener.php
│   ├── Kernel.php
│   ├── Repository/
│   │   └── UserRepository.php
│   ├── Security/
│   │   ├── AppCustomAuthenticator.php
│   │   └── JwtTokenAuthenticator.php
│   ├── Service/
│   │   ├── AuditLoggerService.php
│   │   ├── AuthService.php
│   │   ├── FileSecurityService.php
│   │   └── PasswordService.php
│   └── Tool/
│       ├── AnalyzeCodeTool.php
│       ├── CodeSaverTool.php
│       └── KnowledgeIndexerTool.php
└── README.md (dieses Dokument)
```

## Detaillierte Dateibeschreibungen

### `src/Controller/AuthController.php`

Dieser Controller verwaltet alle Authentifizierungs- und Benutzerverwaltungs-Endpunkte. Er bietet Funktionalitäten für Benutzerregistrierung, Login, Logout und Passwortverwaltung (Reset und Änderung).

**Wichtige Methoden:**
*   `login(Request $request)`: Authentifiziert Benutzer, setzt JWT- und Refresh-Token-Cookies. Enthält Rate Limiting für Login-Versuche.
*   `logout(Request $request)`: Löscht die Authentifizierungs-Cookies.
*   `register(Request $request)`: Registriert einen neuen Benutzer.
*   `requestPasswordReset(Request $request)`: Initiiert den Passwort-Reset-Prozess, sendet eine E-Mail mit einem Reset-Link. Enthält Rate Limiting.
*   `resetPassword(Request $request)`: Setzt das Passwort eines Benutzers unter Verwendung eines gültigen Tokens zurück.
*   `changePassword(Request $request)`: Ermöglicht einem authentifizierten Benutzer, sein eigenes Passwort zu ändern.

**Abhängigkeiten:** `AuthService`, `PasswordService`, `JWTTokenManagerInterface`, `UserRepository`, `UserPasswordHasherInterface`, `RefreshTokenManagerInterface`, `RefreshTokenGeneratorInterface`, `EntityManagerInterface`, `RateLimiterFactory`.

### `src/Controller/FileGeneratorController.php`

Dieser Controller stellt Endpunkte zur Verfügung, um die AI-Agent-Funktionalitäten zu steuern, insbesondere die Generierung von Dateien basierend auf Prompts und das Indexieren der Wissensbasis.

**Wichtige Methoden:**
*   `generateFile(Request $request, AgentInterface $agent)`: Nimmt einen Benutzer-Prompt entgegen und übergibt ihn an einen AI-Agenten (`ai.agent.file_generator`), der Code generiert und in Dateien speichert. Behandelt die Ausgabe des Agenten und meldet den Status.
*   `indexKnowledge(KnowledgeIndexerTool $indexer)`: Löst manuell das Indexieren der Wissensbasis aus, um neue oder aktualisierte Dokumente in den Vektor-Speicher zu laden.

**Abhängigkeiten:** `LoggerInterface`, `AgentInterface` (speziell `ai.agent.file_generator`), `KnowledgeIndexerTool`.

### `src/Controller/HealthCheckController.php`

Ein einfacher Controller, der einen Health-Check-Endpunkt bereitstellt, um den Status der Anwendung und der Datenbankverbindung zu überprüfen.

**Wichtige Methoden:**
*   `health(Connection $connection)`: Prüft die Datenbankverbindung durch Ausführen einer einfachen Abfrage und gibt den Status (`healthy` oder `unhealthy`) zurück.

**Abhängigkeiten:** `Doctrine\DBAL\Connection`.

### `src/DataFixtures/AppFixtures.php`

Diese Klasse wird verwendet, um initiale Daten (Fixtures) in die Datenbank zu laden, was besonders nützlich für die Entwicklung und Tests ist.

**Wichtige Methoden:**
*   `load(ObjectManager $manager)`: Erstellt einen Beispiel-Benutzer, eine Kategorie und einen Post und speichert diese in der Datenbank.

**Abhängigkeiten:** `UserPasswordHasherInterface`, `ObjectManager`.

### `src/DTO/AgentPromptRequest.php`

Ein Data Transfer Object (DTO) zur Validierung des Prompts für den AI-Agenten.

**Eigenschaften:**
*   `$prompt`: Der Text-Prompt für den AI-Agenten. Enthält eine `#[Assert\NotBlank]` Validierung.

### `src/DTO/RegisterRequestDTO.php`

Ein Data Transfer Object (DTO) zum Kapseln der Registrierungsdaten.

**Eigenschaften:**
*   `$email`: Die E-Mail-Adresse des zu registrierenden Benutzers.
*   `$password`: Das Passwort des zu registrierenden Benutzers.

### `src/Entity/RefreshToken.php`

Erweitert die `BaseRefreshToken`-Klasse des `GesdinetJWTRefreshTokenBundle` und ist die Entität für Refresh-Tokens.

**Zweck:** Speichert die Refresh-Tokens in der Datenbank.

### `src/Entity/User.php`

Die zentrale Benutzerentität der Anwendung, implementiert `UserInterface` und `PasswordAuthenticatedUserInterface` für die Symfony-Sicherheit.

**Wichtige Eigenschaften:**
*   `$id`: Eindeutiger Identifikator.
*   `$email`: Benutzer-E-Mail (einzigartig).
*   `$roles`: Benutzerrollen.
*   `$password`: Gehashtes Passwort.
*   `$resetTokenHash`: Hash des Passwort-Reset-Tokens (persisted).
*   `$resetToken`: Der Klartext-Reset-Token (transient, nur für immediate use, z.B. E-Mail).
*   `$resetTokenExpiresAt`: Ablaufdatum des Reset-Tokens.

**Wichtige Methoden:**
*   `getUserIdentifier()`: Gibt die E-Mail-Adresse als Benutzeridentifikator zurück.
*   `getRoles()`: Gibt die Rollen des Benutzers zurück (garantiert `ROLE_USER`).
*   `setPassword()`: Setzt das gehashte Passwort.
*   `setResetTokenPlain()`: Setzt den Reset-Token (Klartext und Hash) und das Ablaufdatum.
*   `verifyResetToken()`: Validiert einen gegebenen Reset-Token gegen den gespeicherten Hash und das Ablaufdatum.
*   `isResetTokenValid()`: Prüft, ob der Reset-Token noch gültig ist.

### `src/EventListener/ExceptionListener.php`

Dieser Event Listener fängt Ausnahmen der Anwendung ab und formatiert die JSON-Antwort, um in Produktion keine sensiblen Details preiszugeben.

**Wichtige Methoden:**
*   `onKernelException(ExceptionEvent $event)`: Protokolliert die Ausnahme und erstellt eine JSON-Antwort. In der `prod`-Umgebung wird eine generische Fehlermeldung gesendet, in `dev` detaillierte Fehlerinformationen.

**Abhängigkeiten:** `LoggerInterface`.

### `src/EventListener/SecurityHeadersListener.php`

Dieser Event Listener fügt Sicherheits-Header zu HTTP-Antworten hinzu und erzwingt HTTPS in der Produktion.

**Wichtige Methoden:**
*   `onKernelResponse(ResponseEvent $event)`:
    *   Leitet HTTP-Anfragen in der `prod`-Umgebung auf HTTPS um.
    *   Setzt `Strict-Transport-Security` (HSTS) Header.
    *   Setzt `Content-Security-Policy` (CSP) Header, mit speziellen Regeln für `/api/doc` (Swagger UI).
    *   Setzt weitere Sicherheits-Header wie `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`.
    *   Entfernt identifizierende Header wie `Server` und `X-Powered-By`.

### `src/Kernel.php`

Die Haupt-Kernel-Klasse der Symfony-Anwendung, die das `MicroKernelTrait` verwendet.

**Zweck:** Startpunkt der Anwendung, Konfiguration der Bundles und Routing.

### `src/Repository/UserRepository.php`

Das Repository für die `User`-Entität, das Methoden zum Abrufen und Speichern von Benutzerdaten bereitstellt. Implementiert `PasswordUpgraderInterface`.

**Wichtige Methoden:**
*   `upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword)`: Wird von Symfony Security aufgerufen, um ein Benutzerpasswort bei Bedarf zu aktualisieren/neu zu hashen.

### `src/Security/AppCustomAuthenticator.php`

Ein benutzerdefinierter Authenticator für den Anmeldevorgang via `/api/login`. Erwartet E-Mail und Passwort im Request-Body.

**Wichtige Methoden:**
*   `supports(Request $request)`: Prüft, ob der Authenticator für die aktuelle Anfrage zuständig ist.
*   `authenticate(Request $request)`: Extrahiert Anmeldedaten und erstellt ein `Passport`-Objekt.
*   `onAuthenticationSuccess()`: Erzeugt ein JWT bei erfolgreicher Authentifizierung.
*   `onAuthenticationFailure()`: Gibt eine JSON-Fehlerantwort bei fehlgeschlagener Authentifizierung zurück.

**Abhängigkeiten:** `JWTTokenManagerInterface`.

### `src/Security/JwtTokenAuthenticator.php`

Ein Authenticator, der JWT-Tokens aus Cookies liest und den Benutzer anhand des Tokens authentifiziert.

**Wichtige Methoden:**
*   `supports(Request $request)`: Prüft, ob ein `BEARER`-Cookie mit einem Token vorhanden ist.
*   `authenticate(Request $request)`: Parsed das JWT aus dem Cookie, validiert es und lädt den Benutzer.
*   `onAuthenticationSuccess()`: Setzt die Authentifizierung fort.
*   `onAuthenticationFailure()`: Gibt eine JSON-Fehlerantwort bei fehlgeschlagener Token-Validierung zurück.
*   `start(Request $request, AuthenticationException $authException = null)`: Wird aufgerufen, wenn ein unauthentifizierter Zugriff auf einen geschützten Endpunkt versucht wird, und gibt einen 401-Fehler zurück.

**Abhängigkeiten:** `JWTTokenManagerInterface`, `UserProviderInterface`.

### `src/Service/AuditLoggerService.php`

Ein Dienst zum Protokollieren von sicherheitsrelevanten Ereignissen.

**Wichtige Methoden:**
*   `logSecurityEvent(string $event, array $context = [])`: Allgemeine Methode zum Protokollieren von Sicherheitsereignissen mit Kontextinformationen (IP, User-Agent, etc.).
*   `logAuthAttempt(string $email, bool $success, string $reason = '')`: Protokolliert Anmeldeversuche.
*   `logUnauthorizedAccess(string $resource, int $userId)`: Protokolliert unbefugte Zugriffe.
*   `logSuspiciousActivity(string $activity, array $details = [])`: Protokolliert verdächtige Aktivitäten.

**Abhängigkeiten:** `LoggerInterface`, `RequestStack`.

### `src/Service/AuthService.php`

Dieser Dienst kapselt die Geschäftslogik für die Benutzerauthentifizierung und -registrierung.

**Wichtige Methoden:**
*   `register(RegisterRequestDTO $dto)`: Registriert einen neuen Benutzer, prüft auf vorhandene E-Mails und hashed das Passwort.

**Abhängigkeiten:** `EntityManagerInterface`, `UserPasswordHasherInterface`.

### `src/Service/FileSecurityService.php`

Ein Dienst, der Funktionen für die sichere Validierung, Speicherung und Löschung von hochgeladenen Dateien bereitstellt, insbesondere für Bilder.

**Wichtige Konstanten:**
*   `ALLOWED_MIMES`: Eine Whitelist von erlaubten MIME-Typen.
*   `MAX_FILE_SIZE`: Maximal erlaubte Dateigröße.
*   `MIN_IMAGE_DIMENSION`, `MAX_IMAGE_DIMENSION`: Min/Max Bildabmessungen.

**Wichtige Methoden:**
*   `validateUploadedFile(UploadedFile $file)`: Führt eine Reihe von Validierungen durch: Dateigröße, Client- und Server-seitiger MIME-Type, Bildintegrität (getimagesize), Bildabmessungen und Dateinamen (Polyglot-Erkennung).
*   `validateFilename(string $filename)` (private): Prüft auf verdächtige Dateinamen (z.B. `.php.jpg`).
*   `saveUploadedFile(UploadedFile $file)`: Speichert eine validierte Datei sicher mit einem eindeutigen, slugifizierten Namen.
*   `secureDelete(string $filename)`: Löscht eine Datei sicher, indem sie mit Zufallsdaten überschrieben und dann gelöscht wird, um Datenwiederherstellung zu erschweren. Enthält Path-Traversal-Prävention.

**Abhängigkeiten:** `UploadedFile`.

### `src/Service/PasswordService.php`

Dieser Dienst verwaltet die Geschäftslogik für das Zurücksetzen und Ändern von Passwörtern.

**Wichtige Methoden:**
*   `requestPasswordReset(string $email)`: Generiert einen sicheren Reset-Token, speichert den Hash und das Ablaufdatum in der Datenbank und sendet eine Reset-E-Mail an den Benutzer. Gibt den Klartext-Token zurück (für Tests).
*   `resetPassword(string $token, string $newPassword)`: Setzt das Passwort eines Benutzers unter Verwendung eines validen Reset-Tokens zurück. Validiert den Token und das neue Passwort.
*   `changePassword(User $user, string $currentPassword, string $newPassword)`: Ermöglicht einem Benutzer, sein aktuelles Passwort zu ändern. Prüft das alte Passwort und validiert das neue.
*   `sendResetEmail(User $user, string $token)` (private): Sendet die Passwort-Reset-E-Mail.

**Abhängigkeiten:** `UserRepository`, `EntityManagerInterface`, `UserPasswordHasherInterface`, `MailerInterface`.

### `src/Tool/AnalyzeCodeTool.php`

Ein Tool, das Code-Analyse für das Projekt durchführt. Es scannt PHP-Dateien in den `src`- und `config`-Verzeichnissen und liefert Metriken und optional den Inhalt der Dateien.

**Wichtige Methoden:**
*   `__invoke(bool $includeContents = true)`: Scannt PHP-Dateien, zählt Zeilen und Dateigrößen. Kann optional Dateiinhalte zurückgeben, unterliegt aber Größenbeschränkungen.
*   `isProbablyTextFile(string $path)` (private): Eine Heuristik, um zu prüfen, ob eine Datei wahrscheinlich eine Textdatei ist (keine Null-Bytes).

**Abhängigkeiten:** `KernelInterface`, `Symfony\Component\Finder\Finder`.

### `src/Tool/CodeSaverTool.php`

Ein Tool für den AI-Agenten, um generierten Code in Dateien im Verzeichnis `generated_code/` zu speichern.

**Wichtige Methoden:**
*   `__invoke(string $filename, string $content)`: Speichert den bereitgestellten Code in einer Datei mit dem angegebenen Namen. Enthält grundlegende Validierung des Dateinamens und Logging. Stellt sicher, dass das Zielverzeichnis existiert.

**Abhängigkeiten:** `Psr\Log\LoggerInterface`.

### `src/Tool/KnowledgeIndexerTool.php`

Ein Tool für den AI-Agenten, das Dokumentationsdateien (RST und Markdown) aus dem `knowledge_base/` Verzeichnis in einen Vektor-Speicher indexiert.

**Wichtige Methoden:**
*   `__invoke()`: Lädt Dokumente, teilt sie in Chunks, vektorisiert diese Chunks und fügt sie dem Vektor-Speicher hinzu. Enthält Logging für den Prozess und Batch-Verarbeitung für die Vektorisierung.

**Abhängigkeiten:** `PlatformInterface`, `StoreInterface`, `LoggerInterface`, `Symfony\Component\Finder\Finder`.

## Konfigurationsdateien

### `config/bundles.php`

Diese Datei registriert alle Symfony-Bundles, die in der Anwendung verwendet werden. Jedes Bundle wird einem Umgebungstyp zugeordnet (`all`, `dev`, `test`), um die Aktivierung zu steuern.

**Wichtige Bundles in diesem Projekt:**
*   `Symfony\Bundle\FrameworkBundle`: Das Kern-Framework.
*   `Doctrine\Bundle\DoctrineBundle`: Integration von Doctrine ORM.
*   `Lexik\Bundle\JWTAuthenticationBundle`: JWT-Authentifizierung.
*   `Gesdinet\JWTRefreshTokenBundle`: JWT Refresh-Token Management.
*   `Nelmio\ApiDocBundle`: OpenAPI (Swagger) Dokumentation.
*   `Symfony\AI\AiBundle`: Das AI-Bundle für Agenten und Tools.

### `config/preload.php`

Diese Datei wird verwendet, um Klassen in PHP vorzuladen, um die Leistung in Produktionsumgebungen zu verbessern. Sie ist typischerweise automatisch generiert und beinhaltet den Kernel-Container.