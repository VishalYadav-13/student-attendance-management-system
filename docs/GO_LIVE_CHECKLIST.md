# SAMS Production Go-Live Deployment Checklist

This document provides the authoritative, step-by-step checklist to take the Student Attendance Management System (SAMS) live in production.

---

## 1. Before Deployment

- [ ] **PostgreSQL Created**: Managed PostgreSQL instance provisioned (e.g., Supabase, Neon, AWS RDS, DigitalOcean). Recommended PostgreSQL 14+.
- [ ] **Schema Imported**: Executed `database/schema.sql` to instantiate tables, UUID extensions (`uuid-ossp` or `pgcrypto`), foreign keys, and indexes.
- [ ] **Production User Created**: Created a dedicated database user with least-privilege access restricted to the application database.
- [ ] **Environment Variables Configured**: Copied `.env.example` to `.env` on production backend server; populated production database credentials, application secrets, and environment flags (`APP_ENV=production`, `APP_DEBUG=false`).
- [ ] **Gemini API Key Configured**: Set `GEMINI_API_KEY` on backend server environment only. Verified key is NEVER bundled or exposed to client-side scripts.
- [ ] **CORS Configured**: Updated `CORS_ALLOWED_ORIGINS` in backend configuration with the exact production frontend domain (e.g., `https://sams.institution.edu`). No wildcard (`*`) in production.
- [ ] **HTTPS Configured**: SSL/TLS certificate issued and enforced on both frontend and backend domains with HSTS enabled.
- [ ] **Frontend API URL Configured**: Set `window.__SAMS_API_URL__` in frontend deployment or injected into `frontend/assets/js/config.js` pointing to `https://api.sams.institution.edu/api`.
- [ ] **Demo Credentials Disabled/Replaced**: Ensured development seed records (`seed.sql`) are not used as production administrative credentials. Created new unique administrator account with a strong password.
- [ ] **Backups Configured**: Automated daily database backups and point-in-time recovery (PITR) enabled.

---

## 2. Frontend (Vercel Edge / Static CDN)

- [ ] **Vercel Project Created**: Linked repository to Vercel with Root Directory set to `./` or `frontend/`.
- [ ] **Build Successful**: Static routing rules in `vercel.json` verified. Zero build failures or missing assets.
- [ ] **Production URL Verified**: Deployed custom domain (e.g., `sams.institution.edu`) accessible via HTTPS with valid TLS certificate and HTTP/2 support.
- [ ] **Asset Minification & Caching**: Cache headers active for static CSS, JS, and SVG assets (`Cache-Control: public, max-age=31536000, immutable` for versioned assets).
- [ ] **Console Hygiene**: Confirmed zero 404s, JavaScript runtime errors, or exposed internal debug traces in browser developer tools.

---

## 3. Backend (PHP 8.1+ Production Runtime)

- [ ] **PHP Hosting Configured**: Production server running PHP 8.1+ with extensions: `pdo`, `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `json`.
- [ ] **Composer Dependencies Installed**: Executed `composer install --no-dev --optimize-autoloader` to generate optimized class maps.
- [ ] **API URL Verified**: Root public directory pointed to `backend/public/` with URL rewriting (`mod_rewrite` / `try_files $uri $uri/ /index.php?$query_string;`).
- [ ] **Environment Variables Configured**: Secrets loaded via system environment or secure `.env` file located outside the web root (`public/`).
- [ ] **HTTPS Verified**: API serves requests exclusively over TLS 1.3/1.2. Insecure HTTP requests redirected with 301.
- [ ] **Rate Limiting Storage**: Ensured write permissions for rate-limiter storage directory or configured Redis/Memcached cache driver.

---

## 4. Database (PostgreSQL)

- [ ] **PostgreSQL Connected**: Verified active PDO driver is `pgsql` using the health check endpoint (`GET /api/health`).
- [ ] **Migrations/Schema Verified**: Confirmed all 11 core tables created:
  - `users`
  - `departments`
  - `students`
  - `teachers`
  - `subjects`
  - `teacher_subjects`
  - `attendance_sessions`
  - `attendance_records`
  - `face_embeddings`
  - `audit_logs`
  - `settings`
- [ ] **Indexes Verified**: Foreign key and query optimization indexes present on `attendance_records(session_id, student_id)`, `attendance_sessions(teacher_id, date)`, `audit_logs(user_id, created_at)`.
- [ ] **Connection Pooling**: PgBouncer or connection pooling configured if using serverless compute (e.g., Supabase transaction pooler port 6543).
- [ ] **Backup Verified**: Manual backup snapshot created and tested for restoration.

---

## 5. Security & Hardening

- [ ] **Authentication Tested**: JWT generation, expiration, signature validation, and token revoking functional.
- [ ] **Authorization Tested**: Role-based access control (ADMIN, TEACHER, STUDENT) strictly enforced server-side.
- [ ] **IDOR Tested**: Cross-student resource access (`GET /api/students/{id}/calendar`) and cross-teacher session access blocked with 403.
- [ ] **CORS Tested**: Disallowed origins receive HTTP 403 or missing `Access-Control-Allow-Origin` headers.
- [ ] **CSP Tested**: Content-Security-Policy active without breaking legitimate client scripts or webcam access.
- [ ] **Secrets Checked**: Zero secrets committed to git. Repository scanned for keys, tokens, and plaintext passwords.
- [ ] **Production Error Handling Verified**: `display_errors = Off`, `log_errors = On`. Production error responses return standardized JSON without database queries, stack traces, or file paths.

---

## 6. Final User Testing (Smoke Test Flow)

- [ ] **Admin Login**:
  - Authenticate with administrator credentials.
  - Access Admin Dashboard and view institutional statistics.
  - Inspect Student and Teacher directories.
  - View Attendance Sessions and institutional Settings.
- [ ] **Teacher Login**:
  - Authenticate with teacher credentials.
  - View assigned subjects on Teacher Dashboard.
  - Create and open a new Attendance Session.
- [ ] **Attendance Marking**:
  - Mark attendance for assigned students (Present, Absent, Late).
  - Verify attendance updates in real time.
- [ ] **Face Verification**:
  - Test camera stream initialization in attendance modal.
  - Perform live facial verification against enrolled student.
  - Confirm automatic attendance status update.
- [ ] **Session Closing**:
  - Close the active attendance session.
  - Confirm closed sessions cannot be modified by teachers.
- [ ] **Attendance Reports**:
  - Generate and export CSV attendance report for current month.
  - Verify statistical summary and Gemini AI insights.
- [ ] **Student Login**:
  - Authenticate with student credentials.
  - View student personal dashboard and attendance percentage.
  - Check shortage alert (triggered if overall attendance < 75%).
- [ ] **Calendar View**:
  - Open Student Calendar (`/frontend/student/calendar.html`).
  - Verify monthly grid renders correct attendance badges (Present/Absent/Late).
- [ ] **Face Enrollment**:
  - Access Face Enrollment modal with camera permissions.
  - Complete face enrollment with explicit consent.
- [ ] **Logout**:
  - Trigger user logout; verify session token is cleared from client `localStorage`/`sessionStorage`.
  - Verify back navigation redirects to login screen.

---

**Sign-off Authority**: Lead Infrastructure & Security Engineer  
**Status**: Ready for Deployment upon Cloud PostgreSQL Provisioning
