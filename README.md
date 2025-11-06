# Self-Developing AI Agent - Installation & Tutorial

## 🎯 Überblick

Dieser Guide beschreibt die Installation und Nutzung eines selbst-entwickelnden AI-Agenten, der:
- Fehlende Tools selbstständig erkennt
- PHP-Code (Services, Entities, Tests) generiert
- Code in isolierter Sandbox testet (**Double-Sandboxing**)
- Nur nach expliziter Freigabe produktiven Code implementiert

## 🏗️ Architektur

```
┌─────────────────────────────────────────────────────────────┐
│  User Request (API)                                         │
│  POST /api/agent/prompt                                     │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  Agent Host Container (Symfony 7.3)                         │
│  - AgentController: API Endpoint                            │
│  - SelfDevelopingAgent: Orchestrierung                      │
│  - Gemini LLM: Tool-Analyse & Code-Generierung              │
│  - Review-Queue: Wartet auf User-Approval                   │
└────────────────────┬────────────────────────────────────────┘
                     │ HTTP (Internal Network)
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  Tool Executor Container (Code Sandbox)                     │
│  - network_mode: none (KEINE Außenwelt-Kommunikation)      │
│  - Führt generierten Code aus                               │
│  - Gibt Testergebnisse zurück                               │
│  - Kann NIEMALS Host-System modifizieren                    │
└─────────────────────────────────────────────────────────────┘
```

## 📋 Voraussetzungen

- Docker Desktop / Docker Engine (>= 24.0)
- Docker Compose (>= 2.20)
- Google Gemini API Key ([Console](https://makersuite.google.com/app/apikey))
- Git
- Postman oder cURL

## 🚀 Installation

### 1. Repository klonen

```bash
git clone https://github.com/Jens-Smit/BlogAPI.git ai-agent-setup
cd ai-agent-setup
```

### 2. Environment-Variablen konfigurieren

Erstelle `.env.docker` im Root-Verzeichnis:

```bash
# .env.docker
# ============================================
# 🔐 KRITISCH: Diese Werte MÜSSEN geändert werden!
# ============================================

# Google Gemini API Key (ERFORDERLICH)
GEMINI_API_KEY=<<YOUR_GEMINI_API_KEY_HERE>>

# Symfony Environment
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=<<GENERATE_WITH: openssl rand -hex 32>>

# Database (Agent Host)
DATABASE_URL=mysql://agent:agent_password@db_agent:3306/agent_db?serverVersion=8.0
DB_ROOT_PASSWORD=root_secret_123

# JWT Configuration
JWT_SECRET_KEY=/var/www/config/jwt/private.pem
JWT_PUBLIC_KEY=/var/www/config/jwt/public.pem
JWT_PASSPHRASE=<<GENERATE_WITH: openssl rand -hex 32>>

# CORS (Development)
CORS_ALLOW_ORIGIN=*
FRONTEND_URL=http://localhost:3000

# Executor Sandbox Config
EXECUTOR_INTERNAL_URL=http://tool_executor:8001
EXECUTOR_TIMEOUT=30
EXECUTOR_MAX_MEMORY=256M
EXECUTOR_MAX_EXECUTION_TIME=10
```

**Secrets generieren:**

```bash
# APP_SECRET
openssl rand -hex 32

# JWT_PASSPHRASE
openssl rand -hex 32

# DB_ROOT_PASSWORD (sicheres Passwort)
openssl rand -base64 24
```

### 3. JWT-Schlüssel generieren

```bash
# Verzeichnis erstellen
mkdir -p config/jwt

# Private Key (mit Passphrase aus .env.docker)
openssl genpkey -algorithm RSA -out config/jwt/private.pem \
  -aes256 -pass pass:<<YOUR_JWT_PASSPHRASE>>

# Public Key
openssl rsa -pubout -in config/jwt/private.pem \
  -out config/jwt/public.pem -passin pass:<<YOUR_JWT_PASSPHRASE>>

# Permissions setzen
chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem
```

### 4. Docker Images bauen

```bash
# Multi-Stage Build für beide Container
docker compose build --no-cache

# Erwartete Ausgabe:
# ✓ Building agent_host    [45s]
# ✓ Building tool_executor [30s]
# ✓ Building db_agent      [5s]
```

### 5. Container starten

```bash
# Starte alle Services
docker compose up -d

# Prüfe Container-Status
docker compose ps

# Erwartete Ausgabe:
# NAME            IMAGE                    STATUS       PORTS
# agent_host      ai-agent:php_agent       Up 10s       0.0.0.0:8080->80/tcp
# tool_executor   ai-agent:php_executor    Up 10s       (isolated, no ports)
# db_agent        mysql:8.0                Up 10s       3306/tcp
```

### 6. Datenbank initialisieren

```bash
# Migrations ausführen (Agent Host)
docker compose exec agent_host php bin/console doctrine:database:create --if-not-exists
docker compose exec agent_host php bin/console doctrine:migrations:migrate --no-interaction

# Test-User erstellen (Optional)
docker compose exec agent_host php bin/console app:create-admin \
  admin@example.com SecurePassword123!
```

### 7. Executor-Sandbox testen

```bash
# Health-Check des Executors (sollte von außen NICHT erreichbar sein)
curl http://localhost:8001/health
# Expected: Connection refused (✓ Korrekt isoliert)

# Internal Health-Check (vom Agent Host aus)
docker compose exec agent_host curl http://tool_executor:8001/health
# Expected: {"status":"healthy","sandbox":"isolated"}
```

## 🧪 Funktionstest

### Test 1: Agent-API Endpoint

```bash
curl -X POST http://localhost:8080/api/agent/prompt \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "Erstelle ein Tool, das die aktuelle Uhrzeit zurückgibt",
    "auto_approve": false
  }'

# Erwartete Antwort:
# {
#   "status": "processing",
#   "task_id": "task_abc123",
#   "message": "Agent analysiert Anfrage..."
# }
```

### Test 2: Task-Status abfragen

```bash
curl http://localhost:8080/api/agent/task/task_abc123

# Erwartete Antwort (während Verarbeitung):
# {
#   "task_id": "task_abc123",
#   "status": "awaiting_review",
#   "generated_code": "<?php\nclass TimeService {...}",
#   "test_results": {
#     "passed": true,
#     "output": "Test erfolgreich"
#   }
# }
```

### Test 3: Code-Freigabe erteilen

```bash
curl -X POST http://localhost:8080/api/agent/task/task_abc123/approve \
  -H "Content-Type: application/json" \
  -d '{"approved": true}'

# Erwartete Antwort:
# {
#   "status": "completed",
#   "message": "Code wurde implementiert",
#   "files_created": [
#     "src/Service/TimeService.php",
#     "tests/Service/TimeServiceTest.php"
#   ]
# }
```

## 📮 Postman Collection

### Request 1: Tool-Entwicklung anstoßen

**Endpoint:** `POST http://localhost:8080/api/agent/prompt`

**Headers:**
```
Content-Type: application/json
```

**Body (JSON):**
```json
{
  "prompt": "Entwickle einen Service, der Weather-Daten von einer externen API abruft. Der Service soll cachen und error-handling haben.",
  "auto_approve": false,
  "config": {
    "test_mode": true,
    "max_iterations": 3
  }
}
```

**Erwartete Antwort:**
```json
{
  "status": "processing",
  "task_id": "task_weather_service_001",
  "steps": [
    "1. Tool-Analyse läuft...",
    "2. WeatherService fehlt - Generierung startet",
    "3. Code wird in Sandbox getestet"
  ],
  "eta_seconds": 45
}
```

### Request 2: Status-Polling

**Endpoint:** `GET http://localhost:8080/api/agent/task/task_weather_service_001`

**Response (Awaiting Review):**
```json
{
  "task_id": "task_weather_service_001",
  "status": "awaiting_review",
  "generated_files": [
    {
      "path": "src/Service/WeatherService.php",
      "content": "<?php\nnamespace App\\Service;\n\nclass WeatherService {\n    // Generated code...\n}",
      "type": "service"
    },
    {
      "path": "src/Entity/WeatherCache.php",
      "content": "<?php\nnamespace App\\Entity;\n\n#[ORM\\Entity]...",
      "type": "entity"
    }
  ],
  "test_results": {
    "passed": true,
    "tests_run": 5,
    "failures": 0,
    "output": "All tests passed in sandbox"
  },
  "actions": {
    "approve": "POST /api/agent/task/task_weather_service_001/approve",
    "reject": "POST /api/agent/task/task_weather_service_001/reject"
  }
}
```

### Request 3: Code-Approval

**Endpoint:** `POST http://localhost:8080/api/agent/task/task_weather_service_001/approve`

**Body:**
```json
{
  "approved": true,
  "comment": "Code looks good, proceed with implementation"
}
```

**Response:**
```json
{
  "status": "completed",
  "message": "WeatherService wurde erfolgreich implementiert",
  "files_created": [
    "src/Service/WeatherService.php",
    "src/Entity/WeatherCache.php",
    "tests/Service/WeatherServiceTest.php"
  ],
  "next_steps": [
    "Führe Migrationen aus: docker compose exec agent_host php bin/console doctrine:migrations:migrate",
    "Service ist verfügbar unter: App\\Service\\WeatherService"
  ]
}
```

## 🔒 Sicherheitsverifikation

### 1. Network-Isolation prüfen

```bash
# Executor sollte KEINE Verbindung nach außen haben
docker compose exec tool_executor ping -c 1 google.com
# Expected: Network unreachable (✓)

docker compose exec tool_executor curl https://example.com
# Expected: Could not resolve host (✓)
```

### 2. File-System-Isolation prüfen

```bash
# Executor sollte NUR /sandbox beschreiben können
docker compose exec tool_executor ls -la /var/www
# Expected: Permission denied (✓)

# Prüfe Read-Only Mounts
docker inspect tool_executor | grep -A 10 "Mounts"
# Expected: "RW": false für /var/www/src (✓)
```

### 3. Process-Isolation prüfen

```bash
# Executor sollte KEINE System-Calls machen können
docker compose exec tool_executor ps aux
# Expected: Nur PHP-Prozesse, keine Shells (✓)

# Prüfe Capabilities
docker inspect tool_executor | grep -A 5 "CapDrop"
# Expected: ["ALL"] (✓)
```

## 🐛 Troubleshooting

### Problem: "Connection refused" beim Agent-API

**Ursache:** Container noch nicht bereit

```bash
# Lösung: Logs prüfen
docker compose logs agent_host

# Warte auf "PHP-FPM started"
docker compose exec agent_host tail -f /var/log/php-fpm.log
```

### Problem: Gemini API gibt 401 zurück

**Ursache:** Ungültiger API-Key

```bash
# Lösung: API-Key prüfen
docker compose exec agent_host php -r 'echo getenv("GEMINI_API_KEY");'

# Sollte NICHT leer sein
# Falls leer: .env.docker prüfen und Container neu starten
docker compose restart agent_host
```

### Problem: Executor kann Code nicht ausführen

**Ursache:** Sandbox-Permissions falsch

```bash
# Lösung: Sandbox-Verzeichnis neu erstellen
docker compose exec tool_executor rm -rf /sandbox/*
docker compose exec tool_executor mkdir -p /sandbox
docker compose exec tool_executor chmod 755 /sandbox
```

### Problem: Tests schlagen in Sandbox fehl

**Ursache:** Fehlende Dependencies

```bash
# Lösung: Composer-Install im Executor
docker compose exec tool_executor composer install --working-dir=/sandbox

# Prüfe PHPUnit
docker compose exec tool_executor /sandbox/vendor/bin/phpunit --version
```

## 📊 Monitoring & Logs

### Echtzeit-Logs verfolgen

```bash
# Alle Container
docker compose logs -f

# Nur Agent Host
docker compose logs -f agent_host

# Nur Executor (sollte meist leer sein)
docker compose logs -f tool_executor
```

### Performance-Metriken

```bash
# Container-Ressourcen
docker stats agent_host tool_executor

# Erwartete Werte:
# agent_host:      CPU < 5%,  RAM < 512MB
# tool_executor:   CPU < 1%,  RAM < 256MB (idle)
```

## 🧹 Cleanup

### Sanftes Herunterfahren

```bash
# Stoppe Container (Daten bleiben)
docker compose down

# Mit Volume-Cleanup
docker compose down -v
```

### Komplettes Reset

```bash
# ACHTUNG: Löscht ALLE Daten!
docker compose down -v --rmi all
rm -rf config/jwt/*
rm -rf var/cache/* var/log/*

# Neustart mit Installation (Schritt 3-6)
```

## 🎓 Erweiterte Nutzung

### Eigene Tools entwickeln

1. **Prompt formulieren:**
   ```json
   {
     "prompt": "Erstelle einen EmailNotificationService mit Queue-Support und Retry-Logik"
   }
   ```

2. **Agent-Antwort analysieren:**
   - Prüfe `generated_files` auf Vollständigkeit
   - Teste `test_results` auf Edge-Cases
   - Review Code-Qualität vor Approval

3. **Nach Approval:**
   - Migrations ausführen (falls Entities)
   - Service in eigenen Code injizieren
   - Integration-Tests schreiben

### Sandbox-Entwicklung testen

```bash
# Direkt Code in Sandbox ausführen
docker compose exec tool_executor php /sandbox/test_script.php

# Sandbox-Logs
docker compose exec tool_executor tail -f /var/log/sandbox_execution.log
```

## 📚 Weiterführende Ressourcen

- [Symfony AI Agent Docs](https://symfony.com/doc/current/ai.html)
- [Google Gemini API](https://ai.google.dev/docs)
- [Docker Security Best Practices](https://docs.docker.com/engine/security/)
- [Doctrine ORM Guide](https://www.doctrine-project.org/projects/doctrine-orm/en/current/)

## 🆘 Support

Bei Problemen:
1. Prüfe `docker compose logs`
2. Validiere `.env.docker` gegen Beispiel
3. Stelle sicher, dass Ports 8080, 8001 frei sind
4. Öffne Issue: [GitHub Issues](https://github.com/Jens-Smit/BlogAPI/issues)

---

**Version:** 1.0.0  
**Zuletzt aktualisiert:** 2024-01-15  
**Autor:** DevOps & AI Team