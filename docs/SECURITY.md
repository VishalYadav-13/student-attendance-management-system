# SAMS Security Architecture & Compliance

## 1. Authentication & Password Security
- **Bcrypt Hashing**: All user passwords are encrypted using PHP's native `password_hash($password, PASSWORD_BCRYPT)` with an adaptive work factor.
- **Constant-Time Verification**: Uses `password_verify()`, mitigating timing-attack vulnerabilities.
- **Session Regeneration**: Session IDs are regenerated via `session_regenerate_id(true)` immediately after successful login to prevent session fixation.
- **Inactivity Timeout**: Configurable session timeouts invalidate abandoned sessions automatically.

---

## 2. Authorization & RBAC Matrix

| Resource / Endpoint | ADMIN | TEACHER | STUDENT | Unauthenticated |
| :--- | :---: | :---: | :---: | :---: |
| Public Landing / Login | ✅ | ✅ | ✅ | ✅ |
| Admin Dashboard | ✅ | ❌ (403) | ❌ (403) | ❌ (401) |
| Student Management (CRUD) | ✅ | View Assigned | ❌ (403) | ❌ (401) |
| Teacher Management | ✅ | ❌ (403) | ❌ (403) | ❌ (401) |
| Take Attendance (Camera) | ✅ | ✅ | ❌ (403) | ❌ (401) |
| Manual Attendance Override | ✅ | ✅ | ❌ (403) | ❌ (401) |
| View Own Student Dashboard | ✅ | ❌ (403) | ✅ | ❌ (401) |
| View Other Student Profile | ✅ | Assigned Class | ❌ (403) | ❌ (401) |
| System Settings Update | ✅ | ❌ (403) | ❌ (403) | ❌ (401) |

- **IDOR Protection**: `StudentController::show($id)` checks if the authenticated user is a student; if `$id` does not match the authenticated student's own ID, the request is rejected with `403 Forbidden`.
- **Client Route Guards**: `Auth.requireRole()` inspects user context and redirects unauthorized users away from forbidden dashboards.

---

## 3. SQL Injection Defense
- **100% Parameterized Statements**: Every query uses PDO prepared statements (`$stmt = $pdo->prepare(...)`, `$stmt->execute([...])`).
- Emulated prepares are disabled (`PDO::ATTR_EMULATE_PREPARES => false`).

---

## 4. Rate Limiting & Brute-Force Defense
- `RateLimitMiddleware` enforces a strict threshold (maximum 8 login attempts per 5-minute rolling window per IP address).
- When exceeded, returns `HTTP 429 Too Many Requests` with a `Retry-After` header.

---

## 5. Audit Logging
- Every sensitive mutation (user logins/logouts, student registration, attendance overrides, face biometrics enrollment/deletion) writes an immutable record to the `audit_logs` table with user ID, action, entity, entity ID, client IP address, and user agent.
