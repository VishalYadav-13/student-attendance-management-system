-- =====================================================================
-- STUDENT ATTENDANCE MANAGEMENT SYSTEM (SAMS)
-- Production PostgreSQL Relational Database Schema
-- Compatible with PostgreSQL 13+
-- =====================================================================

-- Optional UUID extension (omitted for compatibility with restricted non-superuser hosted databases)
-- CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Drop tables with dependencies in reverse order for clean migration
DROP TABLE IF EXISTS system_settings CASCADE;
DROP TABLE IF EXISTS password_resets CASCADE;
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS face_verification_logs CASCADE;
DROP TABLE IF EXISTS face_profiles CASCADE;
DROP TABLE IF EXISTS attendance_overrides CASCADE;
DROP TABLE IF EXISTS attendance_records CASCADE;
DROP TABLE IF EXISTS attendance_sessions CASCADE;
DROP TABLE IF EXISTS timetables CASCADE;
DROP TABLE IF EXISTS student_classes CASCADE;
DROP TABLE IF EXISTS teacher_subjects CASCADE;
DROP TABLE IF EXISTS subjects CASCADE;
DROP TABLE IF EXISTS students CASCADE;
DROP TABLE IF EXISTS teachers CASCADE;
DROP TABLE IF EXISTS divisions CASCADE;
DROP TABLE IF EXISTS classes CASCADE;
DROP TABLE IF EXISTS semesters CASCADE;
DROP TABLE IF EXISTS academic_years CASCADE;
DROP TABLE IF EXISTS courses CASCADE;
DROP TABLE IF EXISTS departments CASCADE;
DROP TABLE IF EXISTS admins CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS roles CASCADE;

-- ---------------------------------------------------------------------
-- 1. ROLES & USERS
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    role_id SERIAL PRIMARY KEY,
    role_name VARCHAR(20) UNIQUE NOT NULL CHECK (role_name IN ('ADMIN', 'TEACHER', 'STUDENT')),
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    role_id INT NOT NULL REFERENCES roles(role_id) ON DELETE RESTRICT,
    email VARCHAR(120) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'SUSPENDED')),
    last_login_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role_id);

CREATE TABLE admins (
    admin_id SERIAL PRIMARY KEY,
    user_id INT UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    designation VARCHAR(100) DEFAULT 'System Administrator',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- 2. ACADEMIC STRUCTURE
-- ---------------------------------------------------------------------
CREATE TABLE departments (
    department_id SERIAL PRIMARY KEY,
    department_code VARCHAR(20) UNIQUE NOT NULL,
    department_name VARCHAR(120) NOT NULL,
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
    course_id SERIAL PRIMARY KEY,
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE RESTRICT,
    course_code VARCHAR(20) UNIQUE NOT NULL,
    course_name VARCHAR(120) NOT NULL,
    duration_years INT DEFAULT 3 CHECK (duration_years > 0),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE academic_years (
    academic_year_id SERIAL PRIMARY KEY,
    year_code VARCHAR(20) UNIQUE NOT NULL, -- e.g. '2026-27'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN DEFAULT false,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE semesters (
    semester_id SERIAL PRIMARY KEY,
    course_id INT NOT NULL REFERENCES courses(course_id) ON DELETE CASCADE,
    semester_number INT NOT NULL CHECK (semester_number BETWEEN 1 AND 8),
    academic_year_id INT NOT NULL REFERENCES academic_years(academic_year_id) ON DELETE CASCADE,
    is_active BOOLEAN DEFAULT true,
    UNIQUE(course_id, semester_number, academic_year_id)
);

CREATE TABLE classes (
    class_id SERIAL PRIMARY KEY,
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE RESTRICT,
    course_id INT NOT NULL REFERENCES courses(course_id) ON DELETE RESTRICT,
    semester_id INT NOT NULL REFERENCES semesters(semester_id) ON DELETE RESTRICT,
    class_name VARCHAR(50) NOT NULL, -- e.g. 'Second Year - Computer Engineering'
    class_code VARCHAR(20) NOT NULL, -- e.g. 'SYCO'
    academic_year_id INT NOT NULL REFERENCES academic_years(academic_year_id) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE divisions (
    division_id SERIAL PRIMARY KEY,
    class_id INT NOT NULL REFERENCES classes(class_id) ON DELETE CASCADE,
    division_name VARCHAR(10) NOT NULL, -- e.g. 'A', 'B'
    max_capacity INT DEFAULT 60 CHECK (max_capacity > 0),
    UNIQUE(class_id, division_name)
);

-- ---------------------------------------------------------------------
-- 3. TEACHERS & STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE teachers (
    teacher_id SERIAL PRIMARY KEY,
    user_id INT UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE RESTRICT,
    designation VARCHAR(100) DEFAULT 'Lecturer / Assistant Professor',
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
    profile_photo VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_teachers_emp_id ON teachers(employee_id);
CREATE INDEX idx_teachers_dept ON teachers(department_id);

CREATE TABLE students (
    student_id SERIAL PRIMARY KEY,
    user_id INT UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    roll_number VARCHAR(50) UNIQUE NOT NULL,
    student_uid VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) UNIQUE NOT NULL,
    phone VARCHAR(20),
    date_of_birth DATE,
    gender VARCHAR(20) CHECK (gender IN ('Male', 'Female', 'Other', 'Prefer not to say')),
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE RESTRICT,
    course_id INT NOT NULL REFERENCES courses(course_id) ON DELETE RESTRICT,
    class_id INT NOT NULL REFERENCES classes(class_id) ON DELETE RESTRICT,
    division_id INT NOT NULL REFERENCES divisions(division_id) ON DELETE RESTRICT,
    batch VARCHAR(20) DEFAULT 'General',
    admission_year INT NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'SUSPENDED')),
    profile_photo VARCHAR(255),
    face_verification_status VARCHAR(30) DEFAULT 'NOT_ENROLLED' CHECK (face_verification_status IN ('NOT_ENROLLED', 'PENDING', 'ENROLLED', 'NEEDS_REENROLLMENT')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_students_roll ON students(roll_number);
CREATE INDEX idx_students_class ON students(class_id, division_id);
CREATE INDEX idx_students_dept ON students(department_id);

-- ---------------------------------------------------------------------
-- 4. SUBJECTS & ALLOCATIONS
-- ---------------------------------------------------------------------
CREATE TABLE subjects (
    subject_id SERIAL PRIMARY KEY,
    subject_code VARCHAR(20) UNIQUE NOT NULL,
    subject_name VARCHAR(120) NOT NULL,
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE RESTRICT,
    semester_number INT NOT NULL,
    credits INT DEFAULT 4 CHECK (credits > 0),
    total_lectures INT DEFAULT 45,
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE teacher_subjects (
    id SERIAL PRIMARY KEY,
    teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(subject_id) ON DELETE CASCADE,
    class_id INT NOT NULL REFERENCES classes(class_id) ON DELETE CASCADE,
    division_id INT NOT NULL REFERENCES divisions(division_id) ON DELETE CASCADE,
    academic_year_id INT NOT NULL REFERENCES academic_years(academic_year_id) ON DELETE CASCADE,
    UNIQUE(teacher_id, subject_id, class_id, division_id, academic_year_id)
);

CREATE TABLE timetables (
    timetable_id SERIAL PRIMARY KEY,
    class_id INT NOT NULL REFERENCES classes(class_id) ON DELETE CASCADE,
    division_id INT NOT NULL REFERENCES divisions(division_id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(subject_id) ON DELETE CASCADE,
    teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    day_of_week VARCHAR(15) NOT NULL CHECK (day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room_number VARCHAR(30) DEFAULT 'Room 204',
    academic_year_id INT NOT NULL REFERENCES academic_years(academic_year_id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------
-- 5. ATTENDANCE SESSIONS & RECORDS
-- ---------------------------------------------------------------------
CREATE TABLE attendance_sessions (
    session_id SERIAL PRIMARY KEY,
    class_id INT NOT NULL REFERENCES classes(class_id) ON DELETE RESTRICT,
    division_id INT NOT NULL REFERENCES divisions(division_id) ON DELETE RESTRICT,
    subject_id INT NOT NULL REFERENCES subjects(subject_id) ON DELETE RESTRICT,
    teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE RESTRICT,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME,
    lecture_number INT DEFAULT 1,
    status VARCHAR(20) DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSED', 'CANCELLED')),
    verification_mode VARCHAR(20) DEFAULT 'HYBRID' CHECK (verification_mode IN ('FACE_AI', 'MANUAL', 'HYBRID')),
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP WITH TIME ZONE
);
CREATE INDEX idx_sessions_date ON attendance_sessions(session_date);
CREATE INDEX idx_sessions_class ON attendance_sessions(class_id, division_id, subject_id);
CREATE INDEX idx_sessions_teacher ON attendance_sessions(teacher_id);

CREATE TABLE attendance_records (
    record_id SERIAL PRIMARY KEY,
    session_id INT NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL CHECK (status IN ('PRESENT', 'ABSENT', 'LATE', 'EXCUSED')),
    marked_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    verification_method VARCHAR(20) DEFAULT 'MANUAL' CHECK (verification_method IN ('FACE_AI', 'MANUAL', 'RFID')),
    confidence_score NUMERIC(5, 4) CHECK (confidence_score BETWEEN 0.0000 AND 1.0000),
    ip_address VARCHAR(45),
    UNIQUE(session_id, student_id) -- CRITICAL: Prevents duplicate attendance marking
);
CREATE INDEX idx_records_student ON attendance_records(student_id, status);
CREATE INDEX idx_records_session ON attendance_records(session_id);

CREATE TABLE attendance_overrides (
    override_id SERIAL PRIMARY KEY,
    record_id INT NOT NULL REFERENCES attendance_records(record_id) ON DELETE CASCADE,
    session_id INT NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    original_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    changed_by INT NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    reason TEXT NOT NULL,
    changed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- 6. BIOMETRICS & AI VERIFICATION
-- ---------------------------------------------------------------------
CREATE TABLE face_profiles (
    profile_id SERIAL PRIMARY KEY,
    student_id INT UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    status VARCHAR(30) DEFAULT 'ENROLLED' CHECK (status IN ('NOT_ENROLLED', 'PENDING', 'ENROLLED', 'NEEDS_REENROLLMENT', 'REVOKED')),
    biometric_hash VARCHAR(255) NOT NULL, -- Cryptographic hash of feature representation
    feature_vector TEXT, -- Encrypted/structured numerical face landmark descriptor
    samples_count INT DEFAULT 3,
    consent_given BOOLEAN DEFAULT true,
    consent_timestamp TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    enrolled_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    enrolled_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE face_verification_logs (
    log_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id) ON DELETE SET NULL,
    session_id INT REFERENCES attendance_sessions(session_id) ON DELETE SET NULL,
    verification_result VARCHAR(30) NOT NULL CHECK (verification_result IN ('SUCCESS', 'FAILED', 'LOW_CONFIDENCE', 'POOR_LIGHTING', 'NO_FACE', 'MULTIPLE_FACES')),
    confidence_score NUMERIC(5, 4),
    quality_score NUMERIC(5, 4),
    method VARCHAR(30) DEFAULT 'GEMINI_ASSISTED_VISION',
    notes TEXT,
    ip_address VARCHAR(45),
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_face_logs_student ON face_verification_logs(student_id);
CREATE INDEX idx_face_logs_time ON face_verification_logs(timestamp);

-- ---------------------------------------------------------------------
-- 7. NOTIFICATIONS, AUDIT LOGS, RESETS & SYSTEM SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    notification_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(30) DEFAULT 'ALERT' CHECK (type IN ('ATTENDANCE', 'ALERT', 'ANNOUNCEMENT', 'SYSTEM')),
    is_read BOOLEAN DEFAULT false,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_notif_user ON notifications(user_id, is_read);

CREATE TABLE audit_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    entity VARCHAR(60) NOT NULL,
    entity_id VARCHAR(60),
    ip_address VARCHAR(45),
    user_agent TEXT,
    metadata JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_time ON audit_logs(created_at);
CREATE INDEX idx_audit_user ON audit_logs(user_id);

CREATE TABLE password_resets (
    id SERIAL PRIMARY KEY,
    email VARCHAR(120) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    used BOOLEAN DEFAULT false,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_pwd_resets_token ON password_resets(token_hash);

CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
