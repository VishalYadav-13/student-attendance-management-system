# SAMS — Production Deployment & Architecture Guide

This document specifies the official, production-ready deployment architecture for the **Student Attendance Management System (SAMS)**.

---

## 1. Architectural Overview & Vercel Compatibility

> [!IMPORTANT]
> **Vercel Hosting Reality**: Vercel is a modern serverless edge platform designed for static frontend assets and JavaScript/TypeScript serverless runtimes. **Vercel does not natively host a traditional PHP-FPM or Apache PHP application.**
> 
> Therefore, SAMS is architected as a clean, decoupled **Two-Tier System**:
> 1. **Frontend**: Static HTML5, CSS3, and JavaScript hosted globally on **Vercel**.
> 2. **Backend**: Lightweight PHP 8.2+ REST API deployed on a PHP-compatible host (such as **Railway**, **Render**, **Fly.io**, **AWS EC2 / App Runner**, or standard **Apache/Nginx VPS**).
> 3. **Database**: Managed **PostgreSQL** (e.g. **Neon**, **Supabase**, or **Railway Postgres**).
> 4. **AI Vision & Insights**: **Google Gemini API** invoked exclusively server-side by the PHP backend.

```
+-------------------------------------------------------------+
|                     End User (Browser / Mobile)             |
+-------------------------------------------------------------+
                              |
              +---------------+---------------+
              |                               |
        (Static Assets)                 (REST API Requests)
              v                               v
+-----------------------------+ +-----------------------------+
|       Vercel Hosting        | |       PHP Backend API       |
|  - HTML5 / CSS3 / Vanilla JS | |  - PHP 8.2+ REST Router     |
|  - vercel.json rewrites     | |  - Auth & RBAC Middleware   |
|  - Global CDN edge caching  | |  - Rate Limiting / CSRF     |
+-----------------------------+ +-----------------------------+
                                              |
                              +---------------+---------------+
                              |                               |
                     (PDO Prepared Queries)          (Server cURL HTTPS)
                              v                               v
               +-----------------------------+ +-----------------------------+
               |     Managed PostgreSQL      | |      Google Gemini API      |
               |  - Supabase / Neon / Railway| |  - Vision Quality Analysis  |
               |  - Relational Schema & Seed | |  - Natural Language Insights|
               +-----------------------------+ +-----------------------------+
```

---

## 2. Frontend Deployment on Vercel

### Step 1: Push Repository to GitHub / GitLab
Push your SAMS repository to your Git provider.

### Step 2: Import into Vercel
1. Open the [Vercel Dashboard](https://vercel.com/dashboard) and select **"Add New Project"**.
2. Select your repository: `VishalYadav-13/student-attendance-management-system`.
3. Configure the Project Settings:
   - **Framework Preset**: `Other`
   - **Root Directory**: `./` (leave default)
   - **Output Directory**: (leave empty — Vercel will serve files according to `vercel.json`)
4. Click **Deploy**.

### Step 3: Configure Frontend API Base URL
In `frontend/assets/js/config.js`, configure your production backend URL:
```javascript
window.SAMS_CONFIG = {
  APP_NAME: "Student Attendance Management System",
  SHORT_NAME: "SAMS",
  TAGLINE: "Smart Attendance. Better Academics.",
  INSTITUTION_NAME: "Demo Polytechnic Institute",
  API_BASE_URL: "https://api-sams.up.railway.app", // Your deployed PHP backend URL
  DEFAULT_THRESHOLD: 75.0,
  AUTO_LOGOUT_MINUTES: 120
};
```

---

## 3. Backend Deployment on PHP Host (e.g., Railway / Render / VPS)

### Option A: Railway (Recommended Cloud Deployment)
1. In Railway, click **"New Project"** -> **"Deploy from GitHub repo"**.
2. Select this repository.
3. Configure environment variables (see Section 4).
4. Set the Start Command:
   ```bash
   php -S 0.0.0.0:$PORT -t backend/public
   ```
5. Railway provides an HTTPS domain, e.g.: `https://api-sams.up.railway.app`.

### Option B: Traditional Apache or Nginx VPS
1. Point your web server document root to the `backend/public/` directory.
2. In Apache, enable `mod_rewrite` and `AllowOverride All`.
3. In Nginx, use standard PHP fastcgi configuration:
   ```nginx
   server {
       listen 80;
       server_name api.sams.edu;
       root /var/www/sams/backend/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
       }
   }
   ```

---

## 4. Managed PostgreSQL Setup

1. Create a PostgreSQL instance on **Neon** ([neon.tech](https://neon.tech)) or **Supabase** ([supabase.com](https://supabase.com)).
2. Connect using `psql` or the web SQL editor and execute:
   ```bash
   psql "postgresql://user:pass@ep-sample.neon.tech/sams_db?sslmode=require" -f database/schema.sql
   psql "postgresql://user:pass@ep-sample.neon.tech/sams_db?sslmode=require" -f database/seed.sql
   ```
3. Copy your database connection string and set `DATABASE_URL` in your backend environment variables.

---

## 5. Environment Variables Reference

| Variable | Type | Example / Description |
| :--- | :--- | :--- |
| `APP_NAME` | string | `"Student Attendance Management System"` |
| `APP_ENV` | string | `production` (or `development`) |
| `APP_DEBUG` | boolean | `false` in production |
| `DATABASE_URL` | string | `postgresql://user:pass@host:5432/sams_db?sslmode=require` |
| `GEMINI_API_KEY` | string | Your Google AI Studio API key |
| `GEMINI_MODEL` | string | `gemini-1.5-flash` |
| `JWT_SECRET` | string | 64+ character random cryptographic string |
| `SESSION_SECRET` | string | 64+ character random string |
| `CORS_ALLOWED_ORIGINS` | string | Comma-separated Vercel domains, e.g.: `https://sams.vercel.app` |
| `ATTENDANCE_THRESHOLD_PERCENT`| float | `75.0` |

---

## 6. Local Development Quickstart

To run the entire system locally with zero external dependencies:

```bash
# 1. Clone repository
git clone https://github.com/VishalYadav-13/student-attendance-management-system.git
cd student-attendance-management-system

# 2. Setup environment
cp .env.example .env

# 3. Start PHP development server
php -S localhost:8000 backend/public/index.php
```

Open your browser at:
- Landing Page: `http://localhost:8000/frontend/index.html`
- Sign In Portal: `http://localhost:8000/frontend/login.html`
