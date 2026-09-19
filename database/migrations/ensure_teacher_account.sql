-- =====================================================================
-- SAMS - Migration: Ensure Primary Teacher Demo Account (teacher@sams.edu)
-- Production PostgreSQL Migration (Compatible with Render & Local Dev)
-- =====================================================================

DO $$
BEGIN
    -- 1. If teacher.sharma@sams.edu exists, update its email to teacher@sams.edu
    IF EXISTS (SELECT 1 FROM users WHERE LOWER(email) = 'teacher.sharma@sams.edu') THEN
        UPDATE users 
        SET email = 'teacher@sams.edu', 
            status = 'ACTIVE',
            role_id = 2,
            password_hash = '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua'
        WHERE LOWER(email) = 'teacher.sharma@sams.edu';
    END IF;

    -- 2. If teacher@sams.edu does not exist, insert or update user 2
    IF NOT EXISTS (SELECT 1 FROM users WHERE LOWER(email) = 'teacher@sams.edu') THEN
        IF EXISTS (SELECT 1 FROM users WHERE user_id = 2) THEN
            UPDATE users
            SET email = 'teacher@sams.edu',
                status = 'ACTIVE',
                role_id = 2,
                password_hash = '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua'
            WHERE user_id = 2;
        ELSE
            INSERT INTO users (user_id, role_id, email, password_hash, status)
            VALUES (2, 2, 'teacher@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'ACTIVE')
            ON CONFLICT (user_id) DO UPDATE SET
                email = 'teacher@sams.edu',
                role_id = 2,
                status = 'ACTIVE',
                password_hash = '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua';
        END IF;
    ELSE
        -- Ensure active status, role 2, and valid password hash
        UPDATE users
        SET role_id = 2, status = 'ACTIVE'
        WHERE LOWER(email) = 'teacher@sams.edu';
    END IF;

    -- 3. Ensure teacher record exists for user 2
    IF NOT EXISTS (SELECT 1 FROM teachers WHERE user_id = 2) THEN
        INSERT INTO teachers (teacher_id, user_id, employee_id, full_name, phone, department_id, designation, status)
        VALUES (1, 2, 'EMP-CO-01', 'Dr. Rajesh Sharma', '+91 98221 44551', 1, 'HOD & Associate Professor', 'ACTIVE')
        ON CONFLICT (user_id) DO UPDATE SET status = 'ACTIVE';
    END IF;
END $$;
