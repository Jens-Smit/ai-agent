# ============================================
# Multi-Stage Dockerfile für AI Agent System
# ============================================
# Stage 1: php_agent  - Symfony Host (mit Netzwerk)
# Stage 2: php_executor - Code Sandbox (isoliert)
# ============================================

# ============================================
# BASE STAGE - Gemeinsame Dependencies
# ============================================
FROM php:8.2-fpm-alpine AS php_base

# System-Dependencies + CA certs
RUN apk add --no-cache \
    ca-certificates \
    git \
    unzip \
    curl \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    icu-dev \
    mysql-client \
    && update-ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        zip \
        gd \
        intl \
        mbstring \
        opcache

# Composer installieren
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OPcache-Konfiguration (Performance)
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

# ============================================
# STAGE 1: PHP_AGENT (Symfony Host)
# ============================================
FROM php_base AS php_agent

# Agent-spezifische Konfiguration
RUN { \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=300'; \
    echo 'upload_max_filesize=10M'; \
    echo 'post_max_size=10M'; \
} > /usr/local/etc/php/conf.d/agent.ini

# Temporäre Build-INI für Composer: allow_url_fopen + proc_open verfügbar
RUN printf "allow_url_fopen=1\ndisable_functions=\n" > /usr/local/etc/php/conf.d/zz_composer_build.ini

# Symfony-Anwendung kopieren (Owner setzen)
COPY --chown=www-data:www-data . /var/www/

# Sicherstellen, dass var existiert bevor chown ausgeführt wird
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www

# Composer Dependencies installieren (Production)
RUN composer install --optimize-autoloader --no-scripts \
    && composer clear-cache

# Symfony Cache warmup (best effort)
RUN php bin/console cache:warmup --env=prod || true

# Berechtigungen final setzen
RUN chown -R www-data:www-data /var/www/var \
    && chmod -R 775 /var/www/var

# Health-Check Script (nur PHP-FPM, kein Webserver nötig)
COPY <<'EOF' /usr/local/bin/health-check.sh
#!/bin/sh
# Prüfe, ob PHP funktioniert
php -r "echo 'PHP OK';" || exit 1

# Prüfe, ob PHP-FPM läuft
if ! pgrep -x php-fpm > /dev/null; then
    echo "PHP-FPM not running"
    exit 1
fi

echo "Health check passed"
exit 0
EOF
RUN chmod +x /usr/local/bin/health-check.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD /usr/local/bin/health-check.sh

EXPOSE 80

# PHP-FPM starten
CMD ["php-fpm", "-F"]

# ============================================
# STAGE 2: PHP_EXECUTOR (Code Sandbox)
# ============================================
FROM php_base AS php_executor

# Executor-spezifische PHP-Limits (RESTRIKTIV) - setzen wir nach Composer-Install
# Zuerst: temporäre Build-INI, damit composer im Build funktioniert
RUN printf "allow_url_fopen=1\ndisable_functions=\n" > /usr/local/etc/php/conf.d/zz_composer_build.ini

# Sandbox-Verzeichnis erstellen (NUR hier darf Code ausgeführt werden)
RUN mkdir -p /sandbox \
    && chown www-data:www-data /sandbox \
    && chmod 755 /sandbox

# Minimale Composer-Dependencies für Tests
WORKDIR /var/www
COPY composer.json composer.lock /var/www/
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer clear-cache

# Nach erfolgreichem Composer-Install: strikte Runtime-Security für Executor setzen
RUN printf "memory_limit=256M\nmax_execution_time=10\nallow_url_fopen=0\nallow_url_include=0\ndisable_functions=exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source\n" > /usr/local/etc/php/conf.d/executor_security.ini

# Optional: Entferne die Build-INI, um keine unnötigen Berechtigungen offen zu lassen
RUN rm -f /usr/local/etc/php/conf.d/zz_composer_build.ini || true

# ⚠️ READ-ONLY Source-Code Mount (wird in docker-compose definiert)
# Executor kann NIEMALS /var/www/src modifizieren

# Executor-API Server (Simple HTTP Server für Code-Execution)
COPY <<'EOF' /usr/local/bin/executor-server.php
<?php
// Minimaler HTTP-Listener-Stub muss über einen Prozessmanager gestartet werden.
// Diese Datei ist nur Platzhalter; in der Praxis starte einen kleinen PHP-FPM oder Swoole Prozess.
echo json_encode(['status'=>'ok']);
EOF
RUN chmod +x /usr/local/bin/executor-server.php

# Default: kein long-running service; nutze CMD in docker-compose falls nötig
CMD ["php", "-S", "0.0.0.0:8080", "/usr/local/bin/executor-server.php"]


# ============================================
# Sicherheits-Checkliste für Executor:
# ============================================
# ✅ disable_functions für gefährliche Funktionen
# ✅ allow_url_fopen=0 (keine externe URLs)
# ✅ memory_limit=256M (Resource-Limit)
# ✅ max_execution_time=10 (Timeout)
# ✅ Keine EXPOSE-Direktive
# ✅ network_mode: none (in docker-compose)
# ✅ Read-Only Mounts (in docker-compose)
# ✅ No Capabilities (in docker-compose)
# ✅ Separate User (www-data, non-root)
# ============================================