-- =====================================================================
-- SAMS Migration 004: Create student_face_enrollments table
-- Integrates CompreFace subject mappings with zero raw biometric storage.
-- =====================================================================

CREATE TABLE IF NOT EXISTS student_face_enrollments (
    id SERIAL PRIMARY KEY,
    student_id INT UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    compreface_subject VARCHAR(100) UNIQUE NOT NULL,
    enrollment_status VARCHAR(30) DEFAULT 'ENROLLED' CHECK (enrollment_status IN ('NOT_ENROLLED', 'PENDING', 'ENROLLED', 'NEEDS_REENROLLMENT', 'REVOKED')),
    sample_count INT DEFAULT 1,
    enrolled_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_face_enroll_student ON student_face_enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_face_enroll_subject ON student_face_enrollments(compreface_subject);
CREATE INDEX IF NOT EXISTS idx_face_enroll_status ON student_face_enrollments(enrollment_status);
