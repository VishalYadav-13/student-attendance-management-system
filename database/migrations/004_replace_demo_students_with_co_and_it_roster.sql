-- =====================================================================
-- SAMS - Migration: Replace Demo Student Roster with Clean CO and IT Dataset
-- Compatible with PostgreSQL (Render/Production) and SQLite (Local Dev)
--
-- Target Dataset:
-- Computer Engineering (CO): 12 students (CO-2101 to CO-2112)
-- Information Technology (IT): 9 students (IT-2101 to IT-2109)
-- Total students: 21
-- All students: ACTIVE, NOT_ENROLLED
-- =====================================================================

-- 1. Safely remove dependent records referencing existing students
DELETE FROM attendance_records;
DELETE FROM attendance_overrides;
DELETE FROM face_profiles;
DELETE FROM student_face_templates;
UPDATE face_verification_logs SET student_id = NULL;

-- 2. Remove all existing student records and student user accounts
DELETE FROM students;
DELETE FROM users WHERE role_id = 3;

-- 3. Insert Clean Student Users (User IDs 7 to 27)
-- Password for all students: Student@12345
INSERT INTO users (user_id, role_id, email, password_hash, status) VALUES
-- CO Students (User IDs 7-18)
(7, 3, 'vishal.yadav@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(8, 3, 'rishi.patil@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(9, 3, 'rushikesh.sharma@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(10, 3, 'parth.deshmukh@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(11, 3, 'aditi.kulkarni@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(12, 3, 'pearl.jain@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(13, 3, 'rekha.shinde@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(14, 3, 'roshani.sawant@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(15, 3, 'anjali.mehta@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(16, 3, 'vighnesh.more@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(17, 3, 'mayank.gupta@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(18, 3, 'aryan.c@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
-- IT Students (User IDs 19-27)
(19, 3, 'nikita.patel@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(20, 3, 'meet.shah@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(21, 3, 'aarya.joshi@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(22, 3, 'nachiket.kulkarni@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(23, 3, 'aryan.verma@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(24, 3, 'vidya.nair@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(25, 3, 'sakshi.deshmukh@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(26, 3, 'srushti.chavan@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(27, 3, 'athar.khan@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE')
ON CONFLICT (user_id) DO UPDATE SET
    email = EXCLUDED.email,
    password_hash = EXCLUDED.password_hash,
    status = EXCLUDED.status;

-- 4. Insert 12 Computer Engineering (CO) Students (Class ID: 1 - SYCO, Division ID: 1 - Div A, Dept ID: 1)
INSERT INTO students (student_id, user_id, roll_number, student_uid, full_name, email, phone, date_of_birth, gender, department_id, course_id, class_id, division_id, batch, admission_year, status, face_verification_status) VALUES
(1, 7, 'CO-2101', 'UID20260001', 'Vishal', 'vishal.yadav@sams.edu', '+91 98111 00001', '2005-04-12', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(2, 8, 'CO-2102', 'UID20260002', 'Rishi', 'rishi.patil@sams.edu', '+91 98111 00002', '2005-06-18', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(3, 9, 'CO-2103', 'UID20260003', 'Rushikesh', 'rushikesh.sharma@sams.edu', '+91 98111 00003', '2005-08-22', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(4, 10, 'CO-2104', 'UID20260004', 'Parth', 'parth.deshmukh@sams.edu', '+91 98111 00004', '2005-02-10', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(5, 11, 'CO-2105', 'UID20260005', 'Aditi', 'aditi.kulkarni@sams.edu', '+91 98111 00005', '2005-11-05', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(6, 12, 'CO-2106', 'UID20260006', 'Pearl', 'pearl.jain@sams.edu', '+91 98111 00006', '2005-09-14', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(7, 13, 'CO-2107', 'UID20260007', 'Rekha', 'rekha.shinde@sams.edu', '+91 98111 00007', '2005-07-29', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(8, 14, 'CO-2108', 'UID20260008', 'Roshani', 'roshani.sawant@sams.edu', '+91 98111 00008', '2005-01-19', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(9, 15, 'CO-2109', 'UID20260009', 'Anjali', 'anjali.mehta@sams.edu', '+91 98111 00009', '2005-12-01', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(10, 16, 'CO-2110', 'UID20260010', 'Vighnesh', 'vighnesh.more@sams.edu', '+91 98111 00010', '2005-03-25', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(11, 17, 'CO-2111', 'UID20260011', 'Mayank', 'mayank.gupta@sams.edu', '+91 98111 00011', '2005-05-15', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(12, 18, 'CO-2112', 'UID20260012', 'Aryan C', 'aryan.c@sams.edu', '+91 98111 00012', '2005-10-30', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
-- 5. Insert 9 Information Technology (IT) Students (Class ID: 2 - SYIT, Division ID: 3 - Div A, Dept ID: 2)
(13, 19, 'IT-2101', 'UID20260013', 'Nikita', 'nikita.patel@sams.edu', '+91 98111 00013', '2005-02-18', 'Female', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(14, 20, 'IT-2102', 'UID20260014', 'Meet', 'meet.shah@sams.edu', '+91 98111 00014', '2005-07-07', 'Male', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(15, 21, 'IT-2103', 'UID20260015', 'Aarya', 'aarya.joshi@sams.edu', '+91 98111 00015', '2005-08-11', 'Female', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(16, 22, 'IT-2104', 'UID20260016', 'Nachiket', 'nachiket.kulkarni@sams.edu', '+91 98111 00016', '2005-04-03', 'Male', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(17, 23, 'IT-2105', 'UID20260017', 'Aryan', 'aryan.verma@sams.edu', '+91 98111 00017', '2005-09-22', 'Male', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(18, 24, 'IT-2106', 'UID20260018', 'Vidya', 'vidya.nair@sams.edu', '+91 98111 00018', '2005-06-05', 'Female', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(19, 25, 'IT-2107', 'UID20260019', 'Sakshi', 'sakshi.deshmukh@sams.edu', '+91 98111 00019', '2005-12-14', 'Female', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(20, 26, 'IT-2108', 'UID20260020', 'Srushti', 'srushti.chavan@sams.edu', '+91 98111 00020', '2005-03-08', 'Female', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED'),
(21, 27, 'IT-2109', 'UID20260021', 'Athar', 'athar.khan@sams.edu', '+91 98111 00021', '2005-05-20', 'Male', 2, 2, 2, 3, 'A1', 2024, 'ACTIVE', 'NOT_ENROLLED')
ON CONFLICT (student_id) DO UPDATE SET
    roll_number = EXCLUDED.roll_number,
    student_uid = EXCLUDED.student_uid,
    full_name = EXCLUDED.full_name,
    email = EXCLUDED.email,
    phone = EXCLUDED.phone,
    gender = EXCLUDED.gender,
    department_id = EXCLUDED.department_id,
    course_id = EXCLUDED.course_id,
    class_id = EXCLUDED.class_id,
    division_id = EXCLUDED.division_id,
    batch = EXCLUDED.batch,
    status = EXCLUDED.status,
    face_verification_status = EXCLUDED.face_verification_status;

-- 6. Ensure Teacher Allocations for SY IT (Class 2, Division 3) to Prof. Kalpesh Sir (Teacher ID 1)
INSERT INTO teacher_subjects (id, teacher_id, subject_id, class_id, division_id, academic_year_id) VALUES
(110, 1, 10, 2, 3, 1), -- Prof. Kalpesh Sir -> Software Engineering (SY IT)
(111, 1, 11, 2, 3, 1), -- Prof. Kalpesh Sir -> Operating Systems (SY IT)
(112, 1, 12, 2, 3, 1)  -- Prof. Kalpesh Sir -> Advanced Computer Networks (SY IT)
ON CONFLICT (id) DO UPDATE SET teacher_id = EXCLUDED.teacher_id;
