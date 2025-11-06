# Symfony Blog API - Produktionsreife Dokumentation

Eine gehärtete, produktionsreife Blog-API mit erweiterten Security-Features, JWT-Authentifizierung, hierarchischen Kategorien und umfassender Dateiverwaltung.

**Version:** 2.0.0  
**Status:** Production-Ready mit Security Hardening  
**Lizenz:** MIT  
**Security Level:** ⭐⭐⭐⭐⭐ (Hardened)

---

## 🔒 Security Features (NEU in v2.0)

### ✅ Implementierte Sicherheitsmaßnahmen

#### 1. **Input Validation & Sanitization**
- **HTMLPurifier Integration**: Alle User-generierten Inhalte werden durch HTMLPurifier gefiltert
- **XSS-Schutz**: Automatische Bereinigung von HTML-Tags in Posts und Kommentaren
- **SQL Injection Prevention**: Doctrine ORM mit Prepared Statements
- **Path Traversal Protection**: Validierung aller Dateipfade gegen Directory Traversal

#### 2. **File Upload Security**
```php
// Implementierte Validierungen:
- MIME-Type Whitelist (nur image/jpeg, image/png, image/gif, image/webp)
- Doppelte MIME-Type-Prüfung (Client + Server mit finfo)
- Dateigröße-Limit: 5MB
- Bild-Dimensions-Check (100px - 10000px)
- Dateiname-Sanitization (keine mehrfachen Extensions)
- Polyglot-Attack-Prevention (.php.jpg blockiert)
- Permissions-Setting: 0644 (nicht ausführbar)
```

#### 3. **Authentication & Session Security**
- **HttpOnly Cookies**: JWT-Tokens sind nicht per JavaScript zugreifbar
- **SameSite Cookies**: CSRF-Schutz durch `SameSite=lax` (Produktion: `strict`)
- **Secure Cookies in Production**: Nur HTTPS-Übertragung
- **Token-based Auth**: Refresh Tokens mit Single-Use-Policy
- **Rate Limiting**: 
  - Login: 5 Versuche / 15 Minuten
  - Password Reset: 3 Versuche / 1 Stunde
  - Token Refresh: 20 Versuche / 1 Stunde

#### 4. **Password Security**
- **Hashed Tokens**: Reset-Tokens werden als SHA-256 Hash gespeichert
- **TTL-basierte Gültigkeit**: Tokens laufen nach 1 Stunde ab
- **Sichere Passwortspeicherung**: Argon2id Hashing via Symfony
- **Passwort-Requirements**: Mindestens 8 Zeichen

#### 5. **HTTP Security Headers**
```nginx
# Automatisch gesetzte Header (SecurityHeadersListener)
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
Content-Security-Policy: default-src 'none'; script-src 'self'; [...]
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=(), [...]
```

#### 6. **Error Handling & Information Disclosure Prevention**
- **Production Mode**: Keine Stack Traces oder interne Fehlerdetails
- **Generic Error Messages**: "Ein Fehler ist aufgetreten" statt Details
- **Comprehensive Logging**: Alle Exceptions werden intern geloggt
- **Audit Trail**: Security-Events werden mit IP, Timestamp und Context geloggt

#### 7. **CORS & API Security**
- **Origin Whitelist**: Nur vorkonfigurierte Domains erlaubt
- **Credentials Required**: `withCredentials: true` erforderlich
- **Preflight Caching**: 1 Stunde Cache für OPTIONS-Requests

---

## 📋 Inhaltsverzeichnis

- [Features](#-features)
- [Security Features](#-security-features)
- [Technologien](#-technologien)
- [Installation](#-installation)
- [Konfiguration](#-konfiguration)
- [API-Dokumentation](#-api-dokumentation)
- [Security Best Practices](#-security-best-practices)
- [Testing](#-testing)
- [Deployment](#-deployment)
- [Security Checklist](#-security-checklist)

---

## 🚀 Features

### Core Features
- ✅ **JWT-Authentifizierung** mit HttpOnly Cookies
- ✅ **Refresh Token System** mit automatischer Rotation
- ✅ **Rate Limiting** für alle sensiblen Endpunkte
- ✅ **Hierarchische Kategorien** mit Zirkelbezug-Prävention
- ✅ **File Upload System** mit umfassender Validierung
- ✅ **Password Reset Flow** mit gehashten Tokens
- ✅ **CAPTCHA-System** für Bot-Prävention
- ✅ **Audit Logging** für Security-Events
- ✅ **Content Sanitization** mit HTMLPurifier

### Security Features (Neu)
- ✅ **XSS-Schutz** auf allen Eingaben
- ✅ **CSRF-Schutz** via SameSite Cookies
- ✅ **SQL Injection Prevention** durch ORM
- ✅ **Path Traversal Protection**
- ✅ **Polyglot Attack Prevention** bei Uploads
- ✅ **Information Disclosure Prevention**
- ✅ **Security Headers** automatisch gesetzt
- ✅ **HTTPS Enforcement** in Production

---

## 🛠 Technologien

### Core Stack
- **PHP 8.2+** mit Type-Safety
- **Symfony 7.3** - Stabiles Framework
- **Doctrine ORM 3.0+** mit Prepared Statements
- **MySQL 8.0+** / **MariaDB 10.4+**

### Security Libraries
- **Lexik JWT Bundle 3.0** - JWT-Authentifizierung
- **HTMLPurifier 4.19** - XSS-Protection
- **Symfony Rate Limiter** - DDoS-Schutz
- **Symfony Security Bundle** - Authorization

### Testing
- **PHPUnit 9.6+** - 90%+ Code Coverage
- **Symfony Test Framework** - Funktionale Tests

---

## 📦 Installation

### Schritt 1: System-Voraussetzungen prüfen

```bash
# PHP-Version prüfen
php -v  # Muss >= 8.2 sein

# Benötigte Extensions
php -m | grep -E 'pdo_mysql|gd|intl|mbstring|xml|curl'

# Composer installieren (falls nicht vorhanden)
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

### Schritt 2: Repository klonen

```bash
git clone https://github.com/Jens-Smit/BlogAPI.git
cd BlogAPI
```

### Schritt 3: Dependencies installieren

```bash
# Development
composer install

# Production (ohne Dev-Dependencies)
composer install --no-dev --optimize-autoloader
```

### Schritt 4: Umgebungsvariablen konfigurieren

```bash
cp .env .env.local
nano .env.local
```

**Kritische Variablen für Production:**

```bash
# WICHTIG: In Production ändern!
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<generiere-mit-openssl-rand-hex-32>

# HTTPS & Cookies
HTTPS_ONLY=true
SECURE_COOKIES=true

# Database (mit starkem Passwort)
DATABASE_URL="mysql://user:STRONG_PASSWORD@db-host:3306/blog_prod"

# JWT (mit starkem Passphrase)
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=<generiere-mit-openssl-rand-hex-32>

# CORS (nur vertrauenswürdige Domains)
CORS_ALLOW_ORIGIN=https://yourdomain.com
FRONTEND_URL=https://yourdomain.com
API_URL=https://api.yourdomain.com

# Mailer (mit Credentials)
MAILER_DSN=smtp://username:password@smtp.sendgrid.net:587
CONTACT_FROM_EMAIL=noreply@yourdomain.com
CONTACT_TO_EMAIL=support@yourdomain.com
```

### Schritt 5: Datenbank einrichten

```bash
# Datenbank erstellen
php bin/console doctrine:database:create

# Migrationen ausführen
php bin/console doctrine:migrations:migrate --no-interaction

# NIEMALS Fixtures in Production laden!
# php bin/console doctrine:fixtures:load  # NUR in Development
```

### Schritt 6: JWT-Schlüssel generieren (SICHER!)

```bash
# Mit starkem Passphrase
php bin/console lexik:jwt:generate-keypair

# Permissions setzen (WICHTIG!)
chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem
chown www-data:www-data config/jwt/private.pem config/jwt/public.pem
```

### Schritt 7: Upload-Verzeichnis sichern

```bash
# Verzeichnis erstellen
mkdir -p public/uploads

# KRITISCHE Permissions (nicht ausführbar!)
chmod 755 public/uploads
chown www-data:www-data public/uploads

# .htaccess für zusätzlichen Schutz
cat > public/uploads/.htaccess << 'EOF'
<FilesMatch "\.(php|phtml|php3|php4|php5|phps)$">
    Require all denied
</FilesMatch>
EOF
```

---

## ⚙️ Konfiguration

### Security-kritische Konfigurationen

#### 1. Security Headers (config/packages/security.yaml)

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'  # Argon2id
    
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            custom_authenticators:
                - App\Security\JwtTokenAuthenticator
    
    access_control:
        # Öffentlich
        - { path: ^/api/login, roles: PUBLIC_ACCESS }
        - { path: ^/api/register, roles: PUBLIC_ACCESS }
        - { path: ^/api/posts$, roles: PUBLIC_ACCESS, methods: [GET] }
        
        # Geschützt
        - { path: ^/api/posts, roles: IS_AUTHENTICATED_FULLY, methods: [POST, PUT, DELETE] }
        - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }
```

#### 2. Rate Limiter Konfiguration

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        login_limiter:
            policy: 'sliding_window'
            limit: 5
            interval: '15 minutes'
            lock_factory: 'lock.default.factory'
            
        password_reset_limiter:
            policy: 'sliding_window'
            limit: 3
            interval: '1 hour'
```

#### 3. CORS-Konfiguration (Restriktiv!)

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']  # NUR vertrauenswürdige Domains!
        allow_credentials: true
        allow_methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        allow_headers: ['Content-Type', 'Authorization']
        max_age: 3600
```

---

## 📚 API-Dokumentation

### Interaktive Dokumentation

```
Production: https://api.yourdomain.com/api/doc
Development: http://localhost:8000/api/doc
JSON Schema: http://localhost:8000/api/doc.json
```

### Authentication Flow (SECURE)

#### 1. Registrierung (mit Validierung)

```bash
POST /api/register
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "SecurePass123!"  # Min. 8 Zeichen
}

Response (201 Created):
{
  "message": "Benutzer erfolgreich registriert."
}
```

#### 2. Login (mit Rate Limiting)

```bash
POST /api/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "SecurePass123!"
}

Response (200 OK):
{
  "message": "Login erfolgreich.",
  "user": {
    "email": "user@example.com"
  }
}

# HttpOnly Cookies werden automatisch gesetzt:
Set-Cookie: BEARER=<jwt-token>; HttpOnly; Secure; SameSite=Strict; Path=/
Set-Cookie: refresh_token=<refresh-token>; HttpOnly; Secure; SameSite=Strict; Path=/
```

⚠️ **WICHTIG**: Frontend muss `credentials: 'include'` verwenden:

```javascript
fetch('https://api.yourdomain.com/api/login', {
  method: 'POST',
  credentials: 'include',  // ✅ ERFORDERLICH für HttpOnly Cookies
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ email, password })
});
```

#### 3. Token Refresh (Automatisch)

```bash
POST /api/token/refresh
Cookie: refresh_token=...

Response (200 OK):
{
  "token": "new_jwt_token",
  "refresh_token_expiration": 1234567890
}

# Neue Cookies werden gesetzt
```

#### 4. Logout (Token-Invalidierung)

```bash
POST /api/logout
Cookie: BEARER=...; refresh_token=...

Response (200 OK):
{
  "message": "Logout erfolgreich."
}

# Cookies werden gelöscht (Max-Age=0)
```

### File Upload (Secure)

#### Sicherer Upload-Request

```bash
POST /api/posts
Content-Type: multipart/form-data
Authorization: Bearer <token>

Form-Data:
- title: "Mein Post"
- content: "Text mit [img1] Platzhalter"
- categoryId: 1
- titleImage: <file>  # ✅ Nur: JPG, PNG, GIF, WEBP
- images: [<file1>, <file2>]
- imageMap: {"img1": "image1.jpg"}

Response (201 Created):
{
  "message": "Post erfolgreich erstellt",
  "id": 42
}
```

**Validierungen:**
- ✅ MIME-Type Whitelist
- ✅ Maximale Dateigröße: 5MB
- ✅ Bild-Dimensionen: 100px - 10000px
- ✅ Keine doppelten Extensions (.php.jpg blockiert)
- ✅ Dateiname wird sanitized

---

## 🔒 Security Best Practices

### 1. Production Deployment Checklist

#### Environment Variables
```bash
# ⚠️ KRITISCH: Diese Werte MÜSSEN geändert werden!
✅ APP_ENV=prod
✅ APP_DEBUG=0
✅ APP_SECRET=<32-byte-random-hex>
✅ JWT_PASSPHRASE=<32-byte-random-hex>
✅ SECURE_COOKIES=true
✅ HTTPS_ONLY=true
✅ DATABASE_URL mit starkem Passwort
✅ CORS_ALLOW_ORIGIN nur für vertrauenswürdige Domains
```

#### Secrets generieren

```bash
# APP_SECRET
openssl rand -hex 32

# JWT_PASSPHRASE
openssl rand -hex 32

# Starkes DB-Passwort
openssl rand -base64 32
```

### 2. File Permissions (UNIX)

```bash
# Application Files
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Executable Scripts
chmod +x bin/console bin/phpunit

# JWT Keys (SEHR WICHTIG!)
chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

# Upload Directory (nicht ausführbar)
chmod 755 public/uploads
find public/uploads -type f -exec chmod 644 {} \;

# Owner setzen
chown -R www-data:www-data var/ public/uploads/
```

### 3. Database Security

```sql
-- Separater DB-Benutzer (nicht root!)
CREATE USER 'blog_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT SELECT, INSERT, UPDATE, DELETE ON blog_prod.* TO 'blog_user'@'localhost';
FLUSH PRIVILEGES;

-- KEINE Administrator-Rechte vergeben!
```

### 4. SSL/TLS Configuration (Nginx)

```nginx
server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;
    
    # SSL Certificate (Let's Encrypt empfohlen)
    ssl_certificate /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;
    
    # Moderne SSL-Konfiguration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256';
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    
    # Weitere Security Headers (werden auch von Symfony gesetzt)
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Upload-Verzeichnis absichern
    location ~ ^/uploads/.*\.php$ {
        deny all;
        return 403;
    }
    
    # Symfony Routing
    location / {
        try_files $uri /index.php$is_args$args;
    }
    
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }
}
```

### 5. Monitoring & Logging

```yaml
# config/packages/monolog.yaml (Production)
when@prod:
    monolog:
        handlers:
            main:
                type: fingers_crossed
                action_level: error
                handler: nested
            nested:
                type: stream
                path: "php://stderr"
                level: debug
                formatter: monolog.formatter.json
            security:
                type: stream
                path: "%kernel.logs_dir%/security.log"
                level: warning
                channels: ["security"]
```

**Überwachung einrichten:**
```bash
# Logwatch installieren
apt-get install logwatch

# Täglich Security-Logs prüfen
logwatch --service symfony --range today --detail high
```

### 6. Backup Strategy

```bash
# Automatisches Backup-Script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR=/backups/blog-api

# Database Backup
mysqldump -u blog_user -p blog_prod | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Files Backup
tar -czf $BACKUP_DIR/uploads_$DATE.tar.gz public/uploads/

# JWT Keys Backup (verschlüsselt!)
tar -czf - config/jwt/ | openssl enc -aes-256-cbc -salt -out $BACKUP_DIR/jwt_$DATE.tar.gz.enc

# Alte Backups löschen (älter als 30 Tage)
find $BACKUP_DIR -type f -mtime +30 -delete
```

---

## 🧪 Testing

### Test-Setup

```bash
# Test-Umgebung vorbereiten
cp .env.test .env.test.local

# Test-Datenbank
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction

# Tests ausführen
php bin/phpunit

# Mit Coverage
php bin/phpunit --coverage-html coverage/

# Spezifische Tests
php bin/phpunit tests/Controller/AuthControllerTest.php
php bin/phpunit --filter testLoginSuccess
```

### Test Coverage Ziele

- ✅ **Unit Tests**: >80% Coverage
- ✅ **Integration Tests**: Alle API-Endpoints
- ✅ **Security Tests**: Authentication, Authorization, Input Validation
- ✅ **Functional Tests**: Complete User Flows

---

## 🚀 Deployment

### Pre-Deployment Security Checklist

```bash
# 1. Environment Check
✅ APP_ENV=prod
✅ APP_DEBUG=0
✅ HTTPS_ONLY=true
✅ SECURE_COOKIES=true

# 2. Secrets rotiert
✅ APP_SECRET geändert
✅ JWT_PASSPHRASE geändert
✅ DB-Passwort geändert

# 3. Dependencies aktualisiert
composer install --no-dev --optimize-autoloader
composer audit  # ✅ Keine Vulnerabilities

# 4. Tests grün
php bin/phpunit
✅ Alle Tests bestanden

# 5. File Permissions
✅ JWT Keys: 600/644
✅ Upload Dir: 755
✅ Files: 644

# 6. Database
✅ Migrations angewendet
✅ Backups konfiguriert
✅ Separater DB-User

# 7. SSL/TLS
✅ Certificate valid
✅ HTTPS Redirect aktiv
✅ HSTS Header gesetzt

# 8. Monitoring
✅ Error Logging aktiv
✅ Security Logging aktiv
✅ Alerting konfiguriert
```

### Deployment Workflow

```bash
# 1. Code auf Server deployen
git pull origin master

# 2. Dependencies installieren
composer install --no-dev --optimize-autoloader

# 3. Cache clearen
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# 4. Migrationen (mit Backup!)
php bin/console doctrine:migrations:migrate --no-interaction

# 5. Permissions setzen
chmod 600 config/jwt/private.pem
chmod 755 public/uploads

# 6. PHP-FPM neu starten
systemctl restart php8.2-fpm

# 7. Health Check
curl -I https://api.yourdomain.com/health
# ✅ Sollte 200 OK zurückgeben
```

---

## ✅ Security Checklist

### Vor jedem Production-Deployment

- [ ] **Environment Variables**
  - [ ] APP_ENV=prod
  - [ ] APP_DEBUG=0
  - [ ] Secrets rotiert (APP_SECRET, JWT_PASSPHRASE)
  - [ ] HTTPS_ONLY=true
  - [ ] SECURE_COOKIES=true
  
- [ ] **Dependencies**
  - [ ] `composer audit` ohne Vulnerabilities
  - [ ] Alle Packages aktuell
  
- [ ] **File Permissions**
  - [ ] JWT Private Key: 600
  - [ ] Upload Directory: 755
  - [ ] Keine PHP-Dateien in /uploads ausführbar
  
- [ ] **Database**
  - [ ] Separater DB-User (nicht root)
  - [ ] Starkes Passwort
  - [ ] Backups konfiguriert
  
- [ ] **SSL/TLS**
  - [ ] Gültiges Certificate
  - [ ] HTTPS Redirect aktiv
  - [ ] HSTS Header gesetzt
  
- [ ] **Security Headers**
  - [ ] Content-Security-Policy
  - [ ] X-Frame-Options: DENY
  - [ ] X-Content-Type-Options: nosniff
  
- [ ] **Logging & Monitoring**
  - [ ] Error Logging aktiv
  - [ ] Security Event Logging
  - [ ] Alerting konfiguriert
  
- [ ] **Testing**
  - [ ] Alle Tests grün
  - [ ] Security Tests bestanden
  
- [ ] **Backups**
  - [ ] Automatische DB-Backups
  - [ ] JWT-Key-Backups verschlüsselt
  - [ ] Upload-Backups

---

## 🐛 Troubleshooting

### Security-spezifische Issues

#### 1. "CORS Error" trotz Konfiguration

```bash
# Problem: Origin nicht in Whitelist

# Lösung 1: .env.local prüfen
CORS_ALLOW_ORIGIN=https://exact-domain.com  # KEINE Wildcards!

# Lösung 2: Preflight-Request prüfen
curl -X OPTIONS https://api.yourdomain.com/api/posts \
  -H "Origin: https://yourdomain.com" \
  -H "Access-Control-Request-Method: POST" \
  -v

# Lösung 3: Cache clearen
php bin/console cache:clear
```

#### 2. "JWT Token nicht gefunden" im Production

```bash
# Problem: HttpOnly Cookie wird nicht gesendet

# Lösung 1: Frontend muss credentials senden
fetch(url, {
  credentials: 'include'  // ✅ WICHTIG!
});

# Lösung 2: SameSite-Cookie-Settings prüfen
# Development: SameSite=Lax, Secure=false
# Production: SameSite=Strict, Secure=true

# Lösung 3: Domain-Matching prüfen
# API: api.domain.com
# Frontend: app.domain.com
# ✅ SameSite=Lax erlaubt Cross-Subdomain
```

#### 3. "Rate Limit Exceeded"

```bash
# Problem: Zu viele Requests

# Lösung 1: IP-basierte Limits prüfen
# Logs prüfen: var/log/dev.log
grep "Rate limit" var/log/prod.log

# Lösung 2: Cache clearen (nur Development)
php bin/console cache:pool:clear cache.rate_limiter

# Lösung 3: Limits in config/packages/rate_limiter.yaml anpassen
# ACHTUNG: Nicht zu hoch setzen (Security Risk!)
```

#### 4. "File Upload Failed" mit Error 500

```bash
# Problem: Validierung fehlgeschlagen

# Lösung 1: Permissions prüfen
ls -la public/uploads/
# Sollte: drwxr-xr-x www-data www-data

# Lösung 2: MIME-Type prüfen
file --mime-type image.jpg
# Muss in Whitelist sein: image/jpeg, image/png, image/gif, image/webp

# Lösung 3: Dateigröße prüfen
ls -lh image.jpg
# Max: 5MB

# Lösung 4: Logs prüfen
tail -f var/log/prod.log
```

---

## 📞 Support & Security Issues

### Security Vulnerabilities melden

⚠️ **NIEMALS** Security-Issues öffentlich auf GitHub posten!

**Verantwortungsvolle Offenlegung:**
1. E-Mail an: security@jenssmit.de
2. Beschreibung der Vulnerability
3. Proof-of-Concept (falls möglich)
4. Erwartete Antwort: 48 Stunden
5. Fix-Timeline: 7-14 Tage (je nach Schweregrad)

### Reguläre Support-Anfragen

- **Issues:** [GitHub Issues](https://github.com/Jens-Smit/BlogAPI/issues)
- **Email:** info@jenssmit.com
- **Documentation:** [API Docs](https://jenssmit.de/api/doc)

---

## 📝 Changelog

### Version 2.0.0 (Security Hardening) - 2024-01-15

**🔒 Security Updates:**
- ✅ HTMLPurifier Integration für XSS-Schutz
- ✅ File Upload Security (MIME-Type Validation, Polyglot Prevention)
- ✅ Path Traversal Protection
- ✅ Security Headers automatisch gesetzt
- ✅ Password Reset mit gehashten Tokens
- ✅ Rate Limiting für alle kritischen Endpoints
- ✅ Audit Logging für Security-Events
- ✅ Information Disclosure Prevention

**🆕 Neue Features:**
- CAPTCHA-System für Bot-Prävention
- Health-Check Endpoint
- Umfassende Test-Coverage (90%+)

**🐛 Bug Fixes:**
- Cookie-Handling in Tests korrigiert
- Circular Reference Detection in Categories
- MIME-Type-Validierung verbessert

---

## 📄 Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.

**WICHTIG für Production-Nutzung:**
- Du bist selbst verantwortlich für Security-Updates
- Regelmäßige Dependency-Updates erforderlich
- Backup-Strategie implementieren
- Monitoring & Alerting einrichten

---

**Zuletzt aktualisiert:** 2024-01-15  
**Entwickler:** [Jens Smit](https://jenssmit.de)  
**Security Review:** 2024-01-15  
**Nächstes Security Audit:** 2024-04-15

---

## 🔗 Weitere Ressourcen

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Symfony Security Best Practices](https://symfony.com/doc/current/security.html)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [JWT Security Best Practices](https://tools.ietf.org/html/rfc8725)
- [Content Security Policy Guide](https://content-security-policy.com/)