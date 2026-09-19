-- =====================================================================
-- SAMS - Migration: Simplify User Structure to One Admin & One Teacher
-- Production PostgreSQL Migration (Compatible with Render & Local Dev)
--
-- Target User Structure:
-- ADMIN:   Name: Madhura Mam, Role: ADMIN
-- TEACHER: Name: Prof. Kalpesh Sir, Role: TEACHER (Single active teacher)
-- =====================================================================

DO $$
DECLARE
    target_teacher_id INT;
    target_user_id INT;
BEGIN
    -- 1. Identify active teacher account associated with teacher@sams.edu
    SELECT t.teacher_id, u.user_id INTO target_teacher_id, target_user_id
    FROM users u
    JOIN teachers t ON u.user_id = t.user_id
    WHERE LOWER(u.email) = 'teacher@sams.edu'
    LIMIT 1;

    -- Fallback to lowest teacher_id if teacher@sams.edu mapping not found
    IF target_teacher_id IS NULL THEN
        SELECT teacher_id, user_id INTO target_teacher_id, target_user_id
        FROM teachers
        ORDER BY teacher_id ASC
        LIMIT 1;
    END IF;

    IF target_teacher_id IS NOT NULL THEN
        -- 2. Rename the active teacher account to 'Prof. Kalpesh Sir'
        UPDATE teachers
        SET full_name = 'Prof. Kalpesh Sir',
            designation = 'Senior Faculty - Computer Engineering',
            status = 'ACTIVE'
        WHERE teacher_id = target_teacher_id;

        -- Ensure user account is ACTIVE with TEACHER role (role_id = 2)
        UPDATE users
        SET status = 'ACTIVE',
            role_id = 2
        WHERE user_id = target_user_id;

        -- 3. Migrate ALL legitimate attendance sessions to Prof. Kalpesh Sir
        UPDATE attendance_sessions
        SET teacher_id = target_teacher_id;

        -- 4. Migrate subject allocations to Prof. Kalpesh Sir without unique key collisions
        UPDATE teacher_subjects
        SET teacher_id = target_teacher_id
        WHERE teacher_id != target_teacher_id
          AND NOT EXISTS (
              SELECT 1 FROM teacher_subjects ts2
              WHERE ts2.teacher_id = target_teacher_id
                AND ts2.subject_id = teacher_subjects.subject_id
                AND ts2.class_id = teacher_subjects.class_id
                AND ts2.division_id = teacher_subjects.division_id
                AND ts2.academic_year_id = teacher_subjects.academic_year_id
          );

        -- Also migrate timetables to Prof. Kalpesh Sir
        UPDATE timetables
        SET teacher_id = target_teacher_id;

        -- 5. Safely deactivate all old teachers (preserving FK relationships and audit logs)
        UPDATE teachers
        SET status = 'INACTIVE'
        WHERE teacher_id != target_teacher_id;

        -- Safely deactivate old teacher user accounts
        UPDATE users
        SET status = 'INACTIVE'
        WHERE role_id = 2 AND user_id != target_user_id;
    END IF;

    -- 6. Rename Admin account to 'Madhura Mam'
    UPDATE admins
    SET full_name = 'Madhura Mam'
    WHERE admin_id = 1 OR user_id = 1;

END $$;
