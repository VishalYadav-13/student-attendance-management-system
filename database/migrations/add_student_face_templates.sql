-- =====================================================================
-- SAMS - Migration: Add student_face_templates Table
-- Production PostgreSQL Migration (Compatible with Render & Local Dev)
-- =====================================================================

CREATE TABLE IF NOT EXISTS student_face_templates (
    id SERIAL PRIMARY KEY,
    student_id INT UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    embedding TEXT NOT NULL, -- JSON-encoded 128-dimensional normalized float32 vector array
    model_version VARCHAR(50) DEFAULT 'face-api-v1-128d',
    quality_score NUMERIC(5, 4) DEFAULT 1.0000,
    enrolled_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'REVOKED')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_face_templates_student ON student_face_templates(student_id);
CREATE INDEX IF NOT EXISTS idx_face_templates_status ON student_face_templates(status);

-- Ensure institutional face verification settings exist in system_settings
INSERT INTO system_settings (setting_key, setting_value, description)
VALUES 
    ('face_verification_enabled', 'true', 'Master toggle for biometric facial verification attendance'),
    ('face_model_version', 'face-api-v1-128d', 'Standard biometric embedding model identifier'),
    ('face_verification_threshold', '0.50', 'Maximum Euclidean distance threshold for identity match (lower is stricter)'),
    ('face_liveness_required', 'true', 'Require active liveness check (blink/head rotation) prior to verification')
ON CONFLICT (setting_key) DO NOTHING;
