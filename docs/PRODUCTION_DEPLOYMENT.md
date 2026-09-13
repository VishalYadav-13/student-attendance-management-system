# SAMS Full-Stack Production Deployment Guide

This guide provides end-to-end instructions for deploying the complete SAMS suite in a robust, multi-tier institutional production environment.

---

## 1. Recommended Architecture

```
                       [ Institutional Users / Mobile Clients ]
                                          │
                                          ▼
                         [ Cloudflare / Vercel Edge CDN ]
                          - Serves static assets (HTML/CSS/JS)
                          - Global DDoS protection & SSL
                                          │
                     ┌────────────────────┴────────────────────┐
                     ▼                                         ▼
            [ Frontend Tier ]                          [ API Backend Tier ]
         Hosted on Vercel / Nginx                   PHP 8.2+ (FPM / Docker / Apache)
         (Static, zero backend compute)             Hosted on Railway / Render / AWS EC2
                                                               │
                                               ┌───────────────┴───────────────┐
                                               ▼                               ▼
                                    [ PostgreSQL Database ]         [ Google Gemini API ]
                                    Neon / Supabase / AWS RDS       Vision & AI Analytics
```

---

## 2. Server Requirements

- **Runtime**: PHP 8.1 or higher (PHP 8.2+ recommended)
- **Extensions**: `pdo_pgsql`, `pdo_sqlite` (fallback), `json`, `curl`, `mbstring`, `openssl`
- **Database**: PostgreSQL 14+ (or SQLite 3 for localized offline environments)
- **Web Server**: Nginx + PHP-FPM or Apache 2.4+ with `mod_rewrite` enabled

---

## 3. Step-by-Step Backend Deployment (Docker / Linux Server)

### Step A: Clone & Set Permissions
```bash
git clone https://github.com/VishalYadav-13/student-attendance-management-system.git /var/www/sams
cd /var/www/sams

# Secure directory permissions
chown -R www-data:www-data /var/www/sams
chmod -R 755 /var/www/sams
chmod -R 775 /var/www/sams/database
```

### Step B: Configure Environment Secrets
Create `/var/www/sams/.env`:
```env
APP_NAME="Student Attendance Management System"
APP_SHORT_NAME="SAMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sams-institution.edu
FRONTEND_URL=https://sams.institution.edu

DB_CONNECTION=pgsql
DB_HOST=your-postgres-host.aws.neon.tech
DB_PORT=5432
DB_NAME=sams_db
DB_USER=sams_app
DB_PASSWORD=your_secure_db_password

GEMINI_API_KEY=AIzaSy...your_gemini_api_key
GEMINI_MODEL=gemini-1.5-flash

JWT_SECRET=generate_a_64_character_cryptographic_random_string_here
CORS_ALLOWED_ORIGINS=https://sams.institution.edu,https://sams-frontend.vercel.app

ATTENDANCE_THRESHOLD_PERCENT=75.0
LATE_GRACE_MINUTES=15
```

### Step C: Execute Database Migrations
```bash
psql "$DATABASE_URL" -f database/schema.sql
psql "$DATABASE_URL" -f database/seed.sql
```

### Step D: Nginx Configuration
```nginx
server {
    listen 443 ssl http2;
    server_name api.sams-institution.edu;

    root /var/www/sams/backend/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/api.sams-institution.edu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.sams-institution.edu/privkey.pem;

    # Security Headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\. {
        deny all;
    }
}
```

---

## 4. Pre-Launch Verification

Execute test suite to confirm business rules:
```bash
php tests/SamsTest.php
```

Verify backend health:
```bash
curl -i https://api.sams-institution.edu/api/health
```
*(Returns `{"status":200,"message":"System healthy","data":{...}}`)*

---

## 5. Maintenance & Log Monitoring

- Check PHP Error Logs: `/var/log/php8.2-fpm.log`
- Check SAMS Audit Trails via API: `GET /api/reports/attendance` or querying `audit_logs` in PostgreSQL.
- Perform regular automated PostgreSQL database dumps as detailed in `POSTGRESQL_PRODUCTION_CHECKLIST.md`.
