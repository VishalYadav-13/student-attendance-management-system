# Exadel CompreFace Integration & Deployment Guide for SAMS

This guide explains how to set up, operate, and deploy the **Exadel CompreFace** facial recognition service alongside the **Student Attendance Management System (SAMS)**.

---

## 1. Architectural Overview

```
Teacher Camera / Browser
        │
        ▼ (Captured frame snapshots - controlled interval ~600ms)
SAMS Vercel Frontend
        │
        ▼ (HTTPS POST /api/face/recognize or /api/face/enroll with Bearer JWT)
SAMS Render PHP Backend
        │
        ├──► Exadel CompreFace Service (Private HTTP /api/v1/recognition/*)
        │       • Model: FaceNet / InsightFace embedding matching
        │       • Subject Format: SAMS_STUDENT_<student_id>
        │
        └──► PostgreSQL Database
                • student_face_enrollments (Biometric subject mapping & metadata)
                • attendance_sessions (Session status & teacher ownership verification)
                • students (Eligibility, branch, division verification)
                • attendance_records (Duplicate protection & verified attendance marking)
                • audit_logs (Complete security audit trail)
```

### Key Security & Architectural Principles
1. **Zero Browser Key Exposure**: The browser never receives or stores the `COMPREFACE_API_KEY` or CompreFace service URL. All calls are mediated through the SAMS PHP backend.
2. **Subject Identity Ground Truth**: Subjects are strictly named `SAMS_STUDENT_<student_id>` (e.g. `SAMS_STUDENT_1042`). The student name submitted by the browser is never trusted. The backend parses `student_id`, queries PostgreSQL, and checks enrollment, class eligibility, and session ownership.
3. **No Raw Biometrics in PostgreSQL**: Raw webcam frames are never persisted to the PostgreSQL database. PostgreSQL only stores the metadata mapping in `student_face_enrollments`.
4. **Server-Side Verification Gate**: A face match alone does not mark attendance. The backend validates:
   - Valid JWT token.
   - User is a Teacher or Admin.
   - Teacher owns the attendance session.
   - Session status is `OPEN`.
   - Student exists and is `ACTIVE`.
   - Student belongs to the exact `class_id` and `division_id` of the session.
   - No duplicate attendance record already exists for the student in that session.
5. **Multi-Frame Confirmation**: The frontend buffers consecutive consistent frames before marking attendance, preventing unstable single-frame misfires.
6. **Graceful Fallback**: Manual attendance remains available at all times as a fallback.

---

## 2. Setting Up CompreFace from Downloads Folder

The CompreFace distribution files are located at:
```
C:\Users\Vy975\Downloads\CompreFace-master
```

### Prerequisites
- Docker Desktop for Windows with WSL2 backend enabled.
- 4GB+ RAM allocated to Docker.

### Step-by-Step Launch
1. Open PowerShell or Command Prompt.
2. Navigate to the CompreFace directory:
   ```powershell
   cd C:\Users\Vy975\Downloads\CompreFace-master
   ```
3. Start the CompreFace microservices using Docker Compose:
   ```powershell
   docker compose up -d
   ```
4. Verify all 4 containers are running:
   ```powershell
   docker compose ps
   ```
   Containers started:
   - `compreface-admin`: Angular administrative web console on port `8000`.
   - `compreface-api`: Spring Boot REST API service.
   - `compreface-core`: Python/OpenCV/TensorFlow embedding calculation engine.
   - `compreface-postgres-db`: Internal database for face vector indexing.

---

## 3. Creating the Recognition Service in CompreFace

1. Open your browser and navigate to:
   ```
   http://localhost:8000
   ```
2. Log in (or create the initial administrator account upon first launch).
3. Click **"Applications"** in the left sidebar and click **"Create"**.
   - Name: `SAMS-Attendance`
4. Enter the application and click **"Add Service"**:
   - Service Type: **Recognition**
   - Service Name: `sams-face-recognition`
5. Copy the generated **API Key** (e.g. `00000000-0000-0000-0000-000000000000`).

---

## 4. Configuring SAMS Environment Variables

### Local Environment (`.env`)
Add the following configuration to your root `.env` file:
```ini
# CompreFace Facial Recognition Service Configuration
COMPREFACE_URL=http://localhost:8000
COMPREFACE_API_KEY=your_copied_api_key_here
FACE_SIMILARITY_THRESHOLD=0.80
```

### Production Environment (Render Backend)
In your Render Dashboard:
1. Navigate to your SAMS Web Service settings.
2. Click **Environment**.
3. Add or update the following environment variables:
   - `COMPREFACE_URL`: URL of your deployed CompreFace instance (e.g., `https://compreface.yourdomain.com`).
   - `COMPREFACE_API_KEY`: Secret API key generated in CompreFace for the SAMS service.
   - `FACE_SIMILARITY_THRESHOLD`: `0.80` (or your calibrated institutional threshold).
4. Save and trigger a deployment.

### Vercel Frontend Configuration
Ensure `frontend/assets/js/config.js` points to your backend Render URL.
> **IMPORTANT**: NEVER add `COMPREFACE_API_KEY` to Vercel environment variables or frontend JavaScript files.

---

## 5. PostgreSQL Database Migrations

Run migration `004_create_student_face_enrollments.sql` on your PostgreSQL database:

```sql
CREATE TABLE IF NOT EXISTS student_face_enrollments (
    id SERIAL PRIMARY KEY,
    student_id INT NOT NULL UNIQUE REFERENCES students(student_id) ON DELETE CASCADE,
    compreface_subject VARCHAR(100) NOT NULL UNIQUE,
    enrollment_status VARCHAR(30) DEFAULT 'ENROLLED' CHECK (enrollment_status IN ('ENROLLED', 'PENDING', 'DELETED')),
    sample_count INT DEFAULT 1,
    enrolled_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_face_enrollments_student ON student_face_enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_face_enrollments_subject ON student_face_enrollments(compreface_subject);
```

---

## 6. SAMS API Endpoints

| Method | Endpoint | Description | Auth & RBAC |
|---|---|---|---|
| `POST` | `/api/face/enroll` | Enrolls student face samples (multi-angle frames) to `SAMS_STUDENT_<id>` | `ADMIN`, `TEACHER` (or Student enrolling own profile) |
| `POST` | `/api/face/recognize` | Analyzes video frame, resolves `student_id`, verifies session & class, marks attendance | `TEACHER`, `ADMIN` |
| `POST` | `/api/face/verify` | Dual-mode endpoint (accepts either embedding or camera frame) | `TEACHER`, `ADMIN` |
| `GET` | `/api/face/status/{student_id}` | Retrieves enrollment status, subject identity, sample count, and timestamp | `ADMIN`, `TEACHER`, `STUDENT` (own) |
| `DELETE` | `/api/face/enrollment/{student_id}` | Right to Erasure: removes CompreFace subject and database records | `ADMIN`, `STUDENT` (own profile) |
| `POST` | `/api/face/session/start` | Creates/reconnects an active face attendance session | `TEACHER`, `ADMIN` |
| `POST` | `/api/face/session/end` | Closes active attendance session | Session Owner / `ADMIN` |
| `GET` | `/api/face/session/{id}` | Fetches live session attendance statistics | `TEACHER`, `ADMIN` |

---

## 7. Real-World Face Testing Checklist

Execute the following 20-step verification protocol prior to full rollout:

- [ ] **Step 1: Register Student A**: Add Student A in the Admin Student Directory.
- [ ] **Step 2: Capture Multi-Angle Samples**: Use the Face Enrollment modal to capture 3–5 multi-angle samples (Frontal, Left, Right, Natural).
- [ ] **Step 3: Register Student B**: Add Student B in the Admin Student Directory.
- [ ] **Step 4: Capture Samples for Student B**: Capture multi-angle samples for Student B.
- [ ] **Step 5: Start Teacher Session**: Teacher logs in, selects Class and Division of Student A, and starts Face Verification.
- [ ] **Step 6: Show Student A to Camera**: Hold Student A in front of the camera.
- [ ] **Step 7: Verify Recognition**: Confirm UI announces `✓ Attendance Marked` with Student A's Name, Roll No, Class, and Confidence %.
- [ ] **Step 8: Verify Database Record**: Check `attendance_records` table to confirm status is `PRESENT` with `verification_method = 'FACE_AI'`.
- [ ] **Step 9: Show Student A Again**: Present Student A to the camera a second time during the same session.
- [ ] **Step 10: Verify Duplicate Prevention**: Confirm UI displays `✓ Already Present` and no duplicate record is inserted into PostgreSQL.
- [ ] **Step 11: Show Student B**: If Student B belongs to the same division, verify attendance is marked. If different division, verify `Student belongs to another class/division.`
- [ ] **Step 12: Show Unregistered Person**: Present an unregistered face. Verify UI displays `Face not recognized` and no attendance is marked.
- [ ] **Step 13: Test Lighting Variation**: Dim or change lighting. Verify whether recognition succeeds or informs `Face match confidence too low`.
- [ ] **Step 14: Test Distance Variation**: Move 2–3 meters away. Verify behavior when face is small.
- [ ] **Step 15: Test Angles**: Turn face up to 30 degrees. Verify multi-angle robustness.
- [ ] **Step 16: Test Multiple Faces**: Have two people stand in front of the camera simultaneously. Verify UI displays `Please keep only one student in front of the camera`.
- [ ] **Step 17: Test Camera Denial**: Block browser camera permissions. Verify error banner with retry option and manual fallback link.
- [ ] **Step 18: Test Service Outage Graceful Fallback**: Stop CompreFace (`docker compose stop`). Verify UI cleanly displays `Face recognition service unavailable` without PHP fatal errors.
- [ ] **Step 19: Test Right to Erasure**: Delete biometric profile for Student A via Admin Directory. Verify CompreFace subject is deleted and status resets to `Not Enrolled`.
- [ ] **Step 20: Switch to Manual Attendance**: Confirm manual grid attendance works as fallback for any student.
