# E-Mail-Funktionalität des AI Agenten

Dieses Dokument beschreibt die Implementierung der E-Mail-Funktionalität für den AI Agenten, die es ihm ermöglicht, E-Mails zu senden und zu empfangen. Die Funktionalität basiert auf den POP3-, IMAP- und SMTP-Einstellungen, die in einer `UserSettings`-Entität des Benutzers gespeichert sind.

## Tools

Es wurden zwei Haupt-Tools entwickelt:

1.  **`SendMailTool`**: Verantwortlich für das Senden von E-Mails über SMTP.
2.  **`ReceiveMailTool`**: Verantwortlich für das Empfangen von E-Mails über IMAP oder POP3.

## Entitäten

Die E-Mail-Konfiguration jedes Benutzers wird in der `UserSettings`-Entität gespeichert, die in einer One-to-One-Beziehung zur `User`-Entität steht. Ein Beispiel für die Struktur der `UserSettings`-Entität ist in der `include.md` zu finden.

## Installation und Konfiguration der Pakete

Die für diese Funktionalität benötigten Composer-Pakete sind:

*   `symfony/mailer`: Für den E-Mail-Versand.
*   `php-imap/php-imap`: Für den E-Mail-Empfang über IMAP/POP3.

Details zur Installation und Konfiguration dieser Pakete sind in der `include.md` beschrieben.

## Verwendung durch den AI Agenten

Die Tools sind mit dem `#[AsTool]`-Attribut annotiert und können vom AI Agenten direkt aufgerufen werden, sobald sie im Symfony-Container registriert sind (siehe Service-Konfigurationen).

### `SendMailTool`

Der Agent kann dieses Tool aufrufen, um eine E-Mail zu versenden. Er muss die `userId` des Absenders, die `to`-Adresse(n), den `subject` und den `body` der E-Mail angeben. Optional kann eine `from`-Adresse angegeben werden, die die Standard-Absenderadresse aus den UserSettings überschreibt.

### `ReceiveMailTool`

Dieses Tool ermöglicht dem Agenten, E-Mails für einen bestimmten Benutzer abzurufen. Der Agent muss die `userId`, das gewünschte `protocol` (IMAP oder POP3) und optional ein `limit` für die Anzahl der abzurufenden E-Mails angeben.

## Fehlerbehandlung und Logging

Beide Tools implementieren eine robuste Fehlerbehandlung und protokollieren wichtige Ereignisse und Fehler über `Psr\Log\LoggerInterface`.