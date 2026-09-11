# SAMS REST API Reference

All requests and responses use JSON (`Content-Type: application/json`). Authenticated endpoints require a Bearer token in the `Authorization` header:
```
Authorization: Bearer <jwt_token>
```

---

## 1. Authentication Endpoints

### `POST /api/auth/login`
Authenticate user credentials and retrieve a session JWT.
- **Request Body**:
  ```json
  {
    "email": "admin@sams.edu",
    "password": "Admin@12345"
  }
  ```
- **Success Response (200)**:
  ```json
  {
    "success": true,
    "message": "Login successful.",
    "data": {
      "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6Ik...",
      "user": {
        "user_id": 1,
        "email": "admin@sams.edu",
        "role": "ADMIN",
        "full_name": "Prof. Arvind Kulkarni"
      },
      "redirect_url": "/frontend/admin/dashboard.html"
    }
  }
  ```
- **Status Codes**: `200 OK`, `401 Unauthorized`, `422 Unprocessable Entity`, `429 Too Many Requests`.

### `POST /api/auth/logout`
Terminates user session and logs audit event.
- **Success Response (200)**:
  ```json
  { "success": true, "message": "Logged out successfully.", "data": null }
  ```

### `GET /api/auth/me`
Inspects currently authenticated user context.

### `POST /api/auth/forgot-password`
Initiates password reset flow without user enumeration.
- **Request Body**: `{ "email": "user@sams.edu" }`

---

## 2. Dashboard Endpoints

### `GET /api/dashboard/admin`
*Requires ADMIN role.*
Returns institutional statistics, Chart.js trend series, low attendance student alerts, and Gemini AI insights.

### `GET /api/dashboard/teacher`
*Requires TEACHER role.*
Returns today's timetable schedule, assigned classes, attendance stats, and active session status.

### `GET /api/dashboard/student`
*Requires STUDENT role.*
Returns student's cumulative attendance percentage, subject-by-subject breakdown, threshold warning (<75%), and recent attendance timeline.

---

## 3. Attendance Management Endpoints

### `POST /api/attendance/session`
*Requires ADMIN or TEACHER role.*
Opens a new attendance lecture session.
- **Request Body**:
  ```json
  {
    "class_id": 1,
    "division_id": 1,
    "subject_id": 1,
    "session_date": "2026-09-12",
    "start_time": "08:00:00",
    "verification_mode": "HYBRID"
  }
  ```

### `GET /api/attendance/session/{id}/students`
Returns class roster for a session with current marked attendance statuses and confidence scores.

### `POST /api/attendance/mark`
Marks individual student attendance. Enforces unique session+student constraint.
- **Request Body**:
  ```json
  {
    "session_id": 25,
    "student_id": 1,
    "status": "PRESENT",
    "verification_method": "FACE_AI",
    "confidence_score": 0.962
  }
  ```
- **Errors**: Returns `409 Conflict` if duplicate record detected.

### `POST /api/attendance/batch-save`
Persists whole class roster attendance in a single atomic transaction.

### `POST /api/attendance/override`
Applies an audited manual override to an attendance record.
- **Request Body**:
  ```json
  {
    "record_id": 104,
    "new_status": "PRESENT",
    "reason": "Student was present in classroom; camera lighting was insufficient."
  }
  ```

---

## 4. Face Verification & Biometric Endpoints

### `POST /api/face/quality-check`
Analyzes webcam frame with Google Gemini AI or local heuristic.
- **Request Body**: `{ "image": "data:image/jpeg;base64,..." }`
- **Response**:
  ```json
  {
    "face_visible": true,
    "face_count": 1,
    "image_quality": "good",
    "lighting": "sufficient",
    "quality_score": 0.94
  }
  ```

### `POST /api/face/verify`
Matches image frame against class roster biometrics and optionally auto-marks attendance.

### `POST /api/face/enroll`
Enrolls student facial landmarks with explicit consent.

### `DELETE /api/face/{studentId}`
Purges student biometric profiles in compliance with privacy retention policies.

---

## 5. Reports & System Settings

- `GET /api/reports/attendance`: Filterable attendance report by date range, department, class, subject.
- `GET /api/reports/export-csv`: Generates and streams downloadable CSV report.
- `GET /api/reports/ai-insights`: Generates natural language institutional insights via Gemini.
- `GET /api/settings`: Returns public system settings.
- `POST /api/settings`: Updates thresholds, grace periods, and AI verification toggles.
