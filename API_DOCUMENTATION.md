# API Dokumentation für Frontend-Entwicklung

Diese Dokumentation beschreibt die verfügbaren API-Endpunkte, deren Nutzung, erwartete Anfragen und Antworten sowie die Handhabung von HTTP-Only Cookies. Sie ist primär für Frontend-Entwickler konzipiert.

---

## 1. Basis-URL

Alle Endpunkte basieren auf der Root-URL: `http://127.0.0.1:8000/api`

## 2. Authentifizierung & Autorisierung

Die API verwendet eine Kombination aus JWT-Token (im `Authorization`-Header) und HTTP-Only Cookies für die Authentifizierung und zur Verwaltung des Session-Zustands.

### 2.1. HTTP-Only Cookies

Bestimmte Endpunkte (z.B. Login, Logout) setzen **HTTP-Only Cookies**. Diese Cookies sind:
*   **Sicher:** Sie sind für JavaScript nicht zugänglich, was sie resistent gegen XSS-Angriffe macht.
*   **Automatisch gesendet:** Der Browser sendet sie bei jeder Anfrage an die gleiche Domain automatisch mit.
*   **Automatisch gelöscht:** Sie haben eine Ablaufzeit und werden vom Browser nach Ablauf automatisch gelöscht.

**Wichtig für Frontend:**
*   Das Frontend kann HTTP-Only Cookies **nicht lesen, setzen oder manipulieren**.
*   Nach einem erfolgreichen Login (der ein HTTP-Only Cookie setzt), muss das Frontend keine weiteren Aktionen bezüglich des Cookies durchführen. Die Authentifizierung erfolgt automatisch über den Browser.
*   Beim Logout wird das HTTP-Only Cookie serverseitig gelöscht oder invalidiert.

### 2.2. Bearer Token (für geschützte Endpunkte)

Nach einem erfolgreichen Login wird die API ein JWT (JSON Web Token) im `Authorization`-Header der Response zurückgeben (oder in einem separaten Body-Feld, je nach Implementierung). Dieses Token muss vom Frontend bei *jeder nachfolgenden geschützten Anfrage* im `Authorization`-Header mit dem Präfix `Bearer ` gesendet werden.

**Beispiel für `Authorization`-Header:**
`Authorization: Bearer <your_jwt_token>`

## 3. Fehlerbehandlung

Die API gibt im Fehlerfall standardisierte JSON-Objekte mit einem HTTP-Statuscode ungleich 2xx zurück.

**Beispiel Fehler-Response:**
```json
{
    "status": "error",
    "message": "Benutzername oder Passwort falsch.",
    "code": 401
}
```
Oder für Validierungsfehler:
```json
{
    "status": "error",
    "message": "Validierungsfehler",
    "errors": {
        "email": "Die E-Mail-Adresse ist ungültig.",
        "password": "Das Passwort muss mindestens 8 Zeichen lang sein."
    },
    "code": 422
}
```

---

## 4. Endpunkte

### 4.1. `POST /api/login` - Benutzer anmelden

Meldet einen Benutzer an und setzt ein HTTP-Only Session-Cookie.

*   **Beschreibung:** Authentifiziert einen Benutzer mit E-Mail und Passwort. Bei Erfolg wird ein HTTP-Only Cookie gesetzt und ein JWT-Token zurückgegeben.
*   **Methode:** `POST`
*   **Authentifizierung:** Keine (dies ist der Login-Endpunkt)

**Anfrage:**
*   **Headers:**
    *   `Content-Type: application/json`
*   **Body (JSON):**
    ```json
    {
        "email": "user@example.com",
        "password": "securepassword123"
    }
    ```
    *   `email` (string, required): E-Mail-Adresse des Benutzers.
    *   `password` (string, required): Passwort des Benutzers.

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "message": "Erfolgreich angemeldet.",
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...", // JWT Token
    "user": {
        "id": 123,
        "email": "user@example.com",
        "firstName": "Max",
        "lastName": "Mustermann",
        "roles": ["ROLE_USER"]
    }
}
```
*   `status` (string): "success"
*   `message` (string): Erfolgsmeldung.
*   `token` (string): Das JWT-Token, das für nachfolgende Anfragen verwendet werden sollte.
*   `user` (object): Daten des angemeldeten Benutzers.
    *   `id` (integer): Eindeutige ID des Benutzers.
    *   `email` (string): E-Mail-Adresse des Benutzers.
    *   `firstName` (string, nullable): Vorname des Benutzers.
    *   `lastName` (string, nullable): Nachname des Benutzers.
    *   `roles` (array of string): Zugewiesene Rollen des Benutzers (z.B. ["ROLE_USER", "ROLE_ADMIN"]).

**Fehler-Response (Beispiele):**
*   `401 Unauthorized`: Ungültige Anmeldeinformationen.
*   `400 Bad Request`: Fehlende oder ungültige Body-Parameter.

### 4.2. `POST /api/logout` - Benutzer abmelden

Meldet den aktuellen Benutzer ab und invalidiert das HTTP-Only Cookie.

*   **Beschreibung:** Meldet den aktuell authentifizierten Benutzer ab. Das HTTP-Only Cookie wird serverseitig invalidiert und gelöscht.
*   **Methode:** `POST`
*   **Authentifizierung:** HTTP-Only Cookie (wird durch Browser gesendet)

**Anfrage:**
*   **Headers:**
    *   `Content-Type: application/json` (Body kann leer sein `{}`)
    *   *Browser sendet automatisch das HTTP-Only Cookie mit.*

**Erfolgs-Response (Status: `204 No Content`):**
*   Kein Body.

**Fehler-Response (Beispiel):**
*   `401 Unauthorized`: Wenn kein Benutzer angemeldet ist oder das Cookie bereits abgelaufen ist.

### 4.3. `GET /api/user` - Aktuellen Benutzer abrufen

Ruft die Details des aktuell angemeldeten Benutzers ab.

*   **Beschreibung:** Gibt die vollständigen Profildaten des aktuell authentifizierten Benutzers zurück.
*   **Methode:** `GET`
*   **Authentifizierung:** JWT Bearer Token

**Anfrage:**
*   **Headers:**
    *   `Authorization: Bearer <your_jwt_token>`

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "user": {
        "id": 123,
        "email": "user@example.com",
        "firstName": "Max",
        "lastName": "Mustermann",
        "roles": ["ROLE_USER"],
        "createdAt": "2023-01-15T10:00:00+00:00"
    }
}
```
*   `status` (string): "success"
*   `user` (object): Aktuelle Benutzerdaten (siehe Login-Response für Details, ggf. erweitert).
    *   `createdAt` (string): ISO 8601 Datum und Uhrzeit der Registrierung.

**Fehler-Response (Beispiel):**
*   `401 Unauthorized`: Kein oder ungültiges JWT Token.

### 4.4. `PUT /api/user` - Benutzerdaten aktualisieren

Aktualisiert die Profildaten des aktuell angemeldeten Benutzers.

*   **Beschreibung:** Aktualisiert die Profildaten (z.B. Vorname, Nachname) des aktuell authentifizierten Benutzers.
*   **Methode:** `PUT`
*   **Authentifizierung:** JWT Bearer Token

**Anfrage:**
*   **Headers:**
    *   `Content-Type: application/json`
    *   `Authorization: Bearer <your_jwt_token>`
*   **Body (JSON):**
    ```json
    {
        "firstName": "Maximilian",
        "lastName": "Mustermann"
    }
    ```
    *   `firstName` (string, optional): Neuer Vorname.
    *   `lastName` (string, optional): Neuer Nachname.
    *   *(Weitere Felder je nach Implementierung)*

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "message": "Benutzerdaten erfolgreich aktualisiert.",
    "user": {
        "id": 123,
        "email": "user@example.com",
        "firstName": "Maximilian",
        "lastName": "Mustermann",
        "roles": ["ROLE_USER"],
        "createdAt": "2023-01-15T10:00:00+00:00"
    }
}
```
*   `status` (string): "success"
*   `message` (string): Erfolgsmeldung.
*   `user` (object): Die aktualisierten Benutzerdaten.

**Fehler-Response (Beispiele):**
*   `401 Unauthorized`: Kein oder ungültiges JWT Token.
*   `422 Unprocessable Entity`: Validierungsfehler (z.B. Vorname ist zu kurz).

### 4.5. `GET /api/products` - Produktliste abrufen

Ruft eine Liste von Produkten ab.

*   **Beschreibung:** Gibt eine paginierte Liste aller verfügbaren Produkte zurück.
*   **Methode:** `GET`
*   **Authentifizierung:** Optional (Produkte können öffentlich sein, aber zusätzliche Details bei Authentifizierung)

**Anfrage:**
*   **Headers:**
    *   `Authorization: Bearer <your_jwt_token>` (optional)
*   **Query-Parameter:**
    *   `page` (integer, optional, default: 1): Die aktuelle Seitenzahl.
    *   `limit` (integer, optional, default: 10): Die Anzahl der Produkte pro Seite (max. 50).
    *   `category` (string, optional): Filtert Produkte nach Kategorie-Slug.
    *   `search` (string, optional): Suchbegriff für Produktnamen oder Beschreibung.

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "data": [
        {
            "id": 1,
            "name": "Produkt A",
            "slug": "produkt-a",
            "description": "Kurze Beschreibung von Produkt A.",
            "price": 19.99,
            "currency": "EUR",
            "imageUrl": "https://your-api-domain.com/images/product-a.jpg",
            "category": {
                "id": 101,
                "name": "Elektronik",
                "slug": "elektronik"
            },
            "stock": 50
        },
        {
            "id": 2,
            "name": "Produkt B",
            "slug": "produkt-b",
            "description": "Kurze Beschreibung von Produkt B.",
            "price": 29.50,
            "currency": "EUR",
            "imageUrl": "https://your-api-domain.com/images/product-b.jpg",
            "category": {
                "id": 102,
                "name": "Haushalt",
                "slug": "haushalt"
            },
            "stock": 10
        }
    ],
    "meta": {
        "currentPage": 1,
        "itemsPerPage": 10,
        "totalItems": 100,
        "totalPages": 10
    }
}
```
*   `status` (string): "success"
*   `data` (array of objects): Eine Liste von Produkten.
    *   `id` (integer): Eindeutige Produkt-ID.
    *   `name` (string): Produktname.
    *   `slug` (string): URL-freundlicher Produktname.
    *   `description` (string): Kurzbeschreibung des Produkts.
    *   `price` (float): Preis des Produkts.
    *   `currency` (string): Währungscode (z.B. "EUR", "USD").
    *   `imageUrl` (string): URL zum Produktbild.
    *   `category` (object): Kategorie des Produkts.
        *   `id` (integer)
        *   `name` (string)
        *   `slug` (string)
    *   `stock` (integer): Verfügbarer Lagerbestand.
*   `meta` (object): Metadaten zur Paginierung.
    *   `currentPage` (integer): Die aktuell abgerufene Seite.
    *   `itemsPerPage` (integer): Anzahl der Elemente pro Seite.
    *   `totalItems` (integer): Gesamtzahl der verfügbaren Produkte.
    *   `totalPages` (integer): Gesamtzahl der Seiten.

**Fehler-Response (Beispiel):**
*   `400 Bad Request`: Ungültige Query-Parameter (z.B. `page` ist kein Integer).

### 4.6. `GET /api/products/{slug}` - Einzelnes Produkt abrufen

Ruft die Details eines einzelnen Produkts anhand seines Slugs ab.

*   **Beschreibung:** Gibt die vollständigen Details eines einzelnen Produkts zurück.
*   **Methode:** `GET`
*   **Authentifizierung:** Optional

**Anfrage:**
*   **Headers:**
    *   `Authorization: Bearer <your_jwt_token>` (optional)
*   **Path-Parameter:**
    *   `{slug}` (string, required): Der Slug des Produkts (z.B. `produkt-a`).

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "product": {
        "id": 1,
        "name": "Produkt A",
        "slug": "produkt-a",
        "description": "Detaillierte Beschreibung von Produkt A, inklusive aller Spezifikationen und Merkmale.",
        "price": 19.99,
        "currency": "EUR",
        "imageUrl": "https://your-api-domain.com/images/product-a.jpg",
        "category": {
            "id": 101,
            "name": "Elektronik",
            "slug": "elektronik"
        },
        "stock": 50,
        "features": [
            "Feature 1",
            "Feature 2"
        ],
        "relatedProducts": [
            {"id": 3, "name": "Produkt C", "slug": "produkt-c", "price": 15.00},
            {"id": 4, "name": "Produkt D", "slug": "produkt-d", "price": 25.00}
        ]
    }
}
```
*   `status` (string): "success"
*   `product` (object): Die Details des Produkts (siehe `GET /api/products` für grundlegende Felder, erweitert um):
    *   `features` (array of string): Liste der Produktmerkmale.
    *   `relatedProducts` (array of objects): Eine Liste verwandter Produkte (ggf. nur mit ID, Name, Slug, Price).

**Fehler-Response (Beispiel):**
*   `404 Not Found`: Produkt mit dem angegebenen Slug existiert nicht.

---

### 4.7. `POST /api/orders` - Eine Bestellung aufgeben

Erstellt eine neue Bestellung für den angemeldeten Benutzer.

*   **Beschreibung:** Ermöglicht einem authentifizierten Benutzer, eine neue Bestellung aufzugeben, basierend auf einer Liste von Produkten und Mengen.
*   **Methode:** `POST`
*   **Authentifizierung:** JWT Bearer Token
*   **Autorisierung:** `ROLE_USER`

**Anfrage:**
*   **Headers:**
    *   `Content-Type: application/json`
    *   `Authorization: Bearer <your_jwt_token>`
*   **Body (JSON):**
    ```json
    {
        "items": [
            {
                "productId": 1,
                "quantity": 2
            },
            {
                "productId": 5,
                "quantity": 1
            }
        ],
        "shippingAddress": {
            "street": "Musterstraße 123",
            "city": "Musterstadt",
            "zipCode": "12345",
            "country": "Deutschland"
        },
        "paymentMethod": "credit_card"
    }
    ```
    *   `items` (array of objects, required): Die zu bestellenden Artikel.
        *   `productId` (integer, required): Die ID des Produkts.
        *   `quantity` (integer, required, minimum: 1): Die Menge des Produkts.
    *   `shippingAddress` (object, required): Die Lieferadresse.
        *   `street` (string, required)
        *   `city` (string, required)
        *   `zipCode` (string, required)
        *   `country` (string, required)
    *   `paymentMethod` (string, required): Die gewünschte Zahlungsmethode (z.B. "credit_card", "paypal", "invoice").

**Erfolgs-Response (Status: `201 Created`):**
```json
{
    "status": "success",
    "message": "Bestellung erfolgreich aufgegeben.",
    "order": {
        "id": 501,
        "orderNumber": "ORD-2023-000501",
        "userId": 123,
        "status": "pending",
        "totalAmount": 69.97,
        "currency": "EUR",
        "createdAt": "2023-01-15T12:30:00+00:00",
        "items": [
            {
                "productId": 1,
                "productName": "Produkt A",
                "quantity": 2,
                "price": 19.99
            },
            {
                "productId": 5,
                "productName": "Produkt E",
                "quantity": 1,
                "price": 29.99
            }
        ],
        "shippingAddress": {
            "street": "Musterstraße 123",
            "city": "Musterstadt",
            "zipCode": "12345",
            "country": "Deutschland"
        }
    }
}
```
*   `status` (string): "success"
*   `message` (string): Erfolgsmeldung.
*   `order` (object): Details der neu erstellten Bestellung.
    *   `id` (integer): Eindeutige Bestell-ID.
    *   `orderNumber` (string): Eindeutige Bestellnummer.
    *   `userId` (integer): ID des Benutzers, der die Bestellung aufgegeben hat.
    *   `status` (string): Aktueller Status der Bestellung (z.B. "pending", "processing", "shipped", "completed", "cancelled").
    *   `totalAmount` (float): Der Gesamtbetrag der Bestellung.
    *   `currency` (string): Währung der Bestellung.
    *   `createdAt` (string): ISO 8601 Datum und Uhrzeit der Bestellung.
    *   `items` (array of objects): Die bestellten Artikel mit Detailinformationen.
        *   `productId` (integer)
        *   `productName` (string)
        *   `quantity` (integer)
        *   `price` (float)
    *   `shippingAddress` (object): Die für die Bestellung verwendete Lieferadresse.

**Fehler-Response (Beispiele):**
*   `401 Unauthorized`: Kein oder ungültiges JWT Token.
*   `403 Forbidden`: Benutzer hat nicht die erforderlichen Rollen.
*   `422 Unprocessable Entity`: Validierungsfehler (z.B. ungültige Produkt-ID, Menge unter 1, fehlende Adressfelder).
*   `400 Bad Request`: Produkt nicht auf Lager oder andere Geschäftslogikfehler.

---

### 4.8. `GET /api/orders` - Bestellhistorie abrufen

Ruft die Bestellhistorie des angemeldeten Benutzers ab.

*   **Beschreibung:** Gibt eine paginierte Liste aller Bestellungen des aktuell authentifizierten Benutzers zurück.
*   **Methode:** `GET`
*   **Authentifizierung:** JWT Bearer Token
*   **Autorisierung:** `ROLE_USER`

**Anfrage:**
*   **Headers:**
    *   `Authorization: Bearer <your_jwt_token>`
*   **Query-Parameter:**
    *   `page` (integer, optional, default: 1): Die aktuelle Seitenzahl.
    *   `limit` (integer, optional, default: 10): Die Anzahl der Bestellungen pro Seite (max. 20).
    *   `status` (string, optional): Filtert Bestellungen nach Status (z.B. "pending", "completed").

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "data": [
        {
            "id": 501,
            "orderNumber": "ORD-2023-000501",
            "status": "completed",
            "totalAmount": 69.97,
            "currency": "EUR",
            "createdAt": "2023-01-15T12:30:00+00:00",
            "summary": "2 Artikel (Produkt A, Produkt E)"
        },
        {
            "id": 502,
            "orderNumber": "ORD-2023-000502",
            "status": "pending",
            "totalAmount": 45.00,
            "currency": "EUR",
            "createdAt": "2023-01-16T09:00:00+00:00",
            "summary": "1 Artikel (Produkt F)"
        }
    ],
    "meta": {
        "currentPage": 1,
        "itemsPerPage": 10,
        "totalItems": 2,
        "totalPages": 1
    }
}
```
*   `status` (string): "success"
*   `data` (array of objects): Eine Liste von Bestellungen (Zusammenfassung).
    *   `id` (integer)
    *   `orderNumber` (string)
    *   `status` (string)
    *   `totalAmount` (float)
    *   `currency` (string)
    *   `createdAt` (string)
    *   `summary` (string): Eine kurze Zusammenfassung der Bestellung für die Übersicht.
*   `meta` (object): Paginierungs-Metadaten.

**Fehler-Response (Beispiele):**
*   `401 Unauthorized`: Kein oder ungültiges JWT Token.
*   `403 Forbidden`: Benutzer hat nicht die erforderlichen Rollen.

---

### 4.9. `GET /api/orders/{orderNumber}` - Details einer Bestellung abrufen

Ruft die vollständigen Details einer spezifischen Bestellung des angemeldeten Benutzers ab.

*   **Beschreibung:** Gibt die vollständigen Details einer einzelnen Bestellung zurück, die dem aktuell authentifizierten Benutzer gehört.
*   **Methode:** `GET`
*   **Authentifizierung:** JWT Bearer Token
*   **Autorisierung:** `ROLE_USER`

**Anfrage:**
*   **Headers:**
    *   `Authorization: Bearer <your_jwt_token>`
*   **Path-Parameter:**
    *   `{orderNumber}` (string, required): Die eindeutige Bestellnummer (z.B. `ORD-2023-000501`).

**Erfolgs-Response (Status: `200 OK`):**
```json
{
    "status": "success",
    "order": {
        "id": 501,
        "orderNumber": "ORD-2023-000501",
        "userId": 123,
        "status": "completed",
        "totalAmount": 69.97,
        "currency": "EUR",
        "createdAt": "2023-01-15T12:30:00+00:00",
        "updatedAt": "2023-01-15T14:00:00+00:00",
        "items": [
            {
                "productId": 1,
                "productName": "Produkt A",
                "quantity": 2,
                "price": 19.99,
                "totalItemPrice": 39.98
            },
            {
                "productId": 5,
                "productName": "Produkt E",
                "quantity": 1,
                "price": 29.99,
                "totalItemPrice": 29.99
            }
        ],
        "shippingAddress": {
            "street": "Musterstraße 123",
            "city": "Musterstadt",
            "zipCode": "12345",
            "country": "Deutschland",
            "firstName": "Max",
            "lastName": "Mustermann"
        },
        "billingAddress": {
            "street": "Rechnungsstraße 456",
            "city": "Rechnungsstadt",
            "zipCode": "67890",
            "country": "Deutschland",
            "firstName": "Max",
            "lastName": "Mustermann"
        },
        "paymentMethod": "credit_card",
        "trackingNumber": "TRK123456789",
        "deliveryDate": "2023-01-18"
    }
}
```
*   `status` (string): "success"
*   `order` (object): Vollständige Details der Bestellung (siehe `POST /api/orders` für Basisfelder, erweitert um):
    *   `updatedAt` (string, nullable): ISO 8601 Datum und Uhrzeit der letzten Aktualisierung.
    *   `items` (array of objects): Detailliertere Artikelinformationen.
        *   `totalItemPrice` (float): Gesamtpreis für diese Artikelposition.
    *   `billingAddress` (object): Die Rechnungsadresse (kann von der Lieferadresse abweichen).
    *   `trackingNumber` (string, nullable): Sendungsnummer, falls verfügbar.
    *   `deliveryDate` (string, nullable): Voraussichtliches Lieferdatum (ISO 8601 Datum, z.B. "YYYY-MM-DD").

**Fehler-Response (Beispiele):**
*   `401 Unauthorized`: Kein oder ungültiges JWT Token.
*   `403 Forbidden`: Benutzer hat nicht die Berechtigung, diese Bestellung einzusehen (z.B. gehört ihm nicht).
*   `404 Not Found`: Bestellung mit der angegebenen Nummer existiert nicht.

---

### 4.10. `POST /api/register` - Neuen Benutzer registrieren

Registriert einen neuen Benutzer.

*   **Beschreibung:** Ermöglicht die Registrierung eines neuen Benutzerkontos.
*   **Methode:** `POST`
*   **Authentifizierung:** Keine

**Anfrage:**
*   **Headers:**
    *   `Content-Type: application/json`
*   **Body (JSON):**
    ```json
    {
        "email": "newuser@example.com",
        "password": "StrongPassword123!",
        "firstName": "Anna",
        "lastName": "Musterfrau"
    }
    ```
    *   `email` (string, required): E-Mail-Adresse des neuen Benutzers (muss einzigartig sein).
    *   `password` (string, required): Passwort des neuen Benutzers (muss den Sicherheitsanforderungen entsprechen, z.B. min. 8 Zeichen, Groß-/Kleinbuchstaben, Zahlen, Sonderzeichen).
    *   `firstName` (string, required): Vorname des neuen Benutzers.
    *   `lastName` (string, required): Nachname des neuen Benutzers.

**Erfolgs-Response (Status: `201 Created`):**
```json
{
    "status": "success",
    "message": "Benutzer erfolgreich registriert. Bitte bestätigen Sie Ihre E-Mail-Adresse.",
    "user": {
        "id": 124,
        "email": "newuser@example.com",
        "firstName": "Anna",
        "lastName": "Musterfrau",
        "roles": ["ROLE_USER"]
    }
}
```
*   `status` (string): "success"
*   `message` (string): Erfolgsmeldung, oft mit Hinweis auf E-Mail-Bestätigung.
*   `user` (object): Grundlegende Daten des neu registrierten Benutzers.

**Fehler-Response (Beispiele):**
*   `422 Unprocessable Entity`: Validierungsfehler (z.B. E-Mail ist bereits registriert, Passwort zu schwach, fehlende Felder).

---

## 5. Deployment Hinweise

Diese API-Dokumentation ist ein Entwurf. Die tatsächlichen Endpunkte, Authentifizierungsmechanismen und Datenstrukturen müssen möglicherweise angepasst werden, um mit Ihrer spezifischen Symfony-Implementierung übereinzustimmen.

*   **CORS:** Stellen Sie sicher, dass Ihre API die Cross-Origin Resource Sharing (CORS)-Richtlinien korrekt konfiguriert hat, damit das Frontend von einer anderen Domain aus zugreifen kann.
*   **SSL/TLS:** Alle API-Aufrufe sollten über HTTPS erfolgen, um die Datenübertragung zu sichern.
*   **Rate Limiting:** Zum Schutz vor Missbrauch sollten Rate Limiting-Maßnahmen für bestimmte Endpunkte (z.B. Login, Registrierung) implementiert sein.
*   **API-Versionierung:** Es wird empfohlen, die API zu versionieren (z.B. `/api/v1/login`), um zukünftige Änderungen zu ermöglichen, ohne bestehende Frontends zu beeinträchtigen.
