-- =====================================================================
-- STUDENT ATTENDANCE MANAGEMENT SYSTEM (SAMS)
-- Production Seed Data for "Demo Polytechnic Institute"
-- =====================================================================

-- 1. Roles
INSERT INTO roles (role_id, role_name, description) VALUES
(1, 'ADMIN', 'System Administrator with institution-wide configuration and audit privileges'),
(2, 'TEACHER', 'Faculty Member with class timetable, attendance marking, and grading reports'),
(3, 'STUDENT', 'Enrolled student with personal attendance tracking, biometrics, and calendars')
ON CONFLICT (role_id) DO NOTHING;

-- 2. System Settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('institution_name', 'Demo Polytechnic Institute', 'Official institution name displayed across the portal'),
('institution_code', 'DPI-4021', 'Government/Polytechnic board affiliation code'),
('academic_year', '2026-27', 'Current active academic year'),
('attendance_threshold', '75', 'Minimum percentage required to avoid shortage warning'),
('late_grace_minutes', '15', 'Allowed grace minutes after lecture start before marked Late'),
('late_weight', '1.0', 'Count late as present for attendance denominator (1.0 = present)'),
('face_verification_enabled', 'true', 'Global toggle for AI face verification in classroom sessions'),
('liveness_required', 'true', 'Requires user to follow liveness cues during camera capture'),
('manual_override_allowed', 'true', 'Allows authorized teachers to manually override attendance with reason')
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value;

-- 3. Departments
INSERT INTO departments (department_id, department_code, department_name, description) VALUES
(1, 'CO', 'Computer Engineering', 'Department of Computer Engineering & Software Technologies'),
(2, 'IT', 'Information Technology', 'Department of Information Technology & Cloud Computing'),
(3, 'EJ', 'Electronics & Telecommunication', 'Department of Electronics and Digital Communication')
ON CONFLICT (department_id) DO NOTHING;

-- 4. Courses
INSERT INTO courses (course_id, department_id, course_code, course_name, duration_years) VALUES
(1, 1, 'DCO', 'Diploma in Computer Engineering', 3),
(2, 2, 'DIT', 'Diploma in Information Technology', 3),
(3, 3, 'DEJ', 'Diploma in Electronics & Telecommunication', 3)
ON CONFLICT (course_id) DO NOTHING;

-- 5. Academic Year & Semesters
INSERT INTO academic_years (academic_year_id, year_code, start_date, end_date, is_current) VALUES
(1, '2026-27', '2026-07-01', '2027-05-31', true)
ON CONFLICT (academic_year_id) DO NOTHING;

INSERT INTO semesters (semester_id, course_id, semester_number, academic_year_id, is_active) VALUES
(1, 1, 3, 1, true), -- SY CO Semester 3
(2, 1, 4, 1, false),
(3, 2, 3, 1, true), -- SY IT Semester 3
(4, 3, 3, 1, true),  -- SY EJ Semester 3
(5, 1, 1, 1, true), -- FY CO Semester 1
(6, 1, 5, 1, true), -- TY CO Semester 5
(7, 2, 1, 1, true), -- FY IT Semester 1
(8, 2, 5, 1, true), -- TY IT Semester 5
(9, 3, 1, 1, true), -- FY EJ Semester 1
(10, 3, 5, 1, true) -- TY EJ Semester 5
ON CONFLICT (semester_id) DO NOTHING;

-- 6. Classes & Divisions
INSERT INTO classes (class_id, department_id, course_id, semester_id, class_name, class_code, academic_year_id) VALUES
(1, 1, 1, 1, 'Second Year Computer Engineering', 'SYCO', 1),
(2, 2, 2, 3, 'Second Year Information Technology', 'SYIT', 1),
(3, 3, 3, 4, 'Second Year Electronics', 'SYEJ', 1),
(4, 1, 1, 5, 'First Year Computer Engineering', 'FYCO', 1),
(5, 1, 1, 6, 'Third Year Computer Engineering', 'TYCO', 1),
(6, 2, 2, 7, 'First Year Information Technology', 'FYIT', 1),
(7, 2, 2, 8, 'Third Year Information Technology', 'TYIT', 1),
(8, 3, 3, 9, 'First Year Electronics', 'FYEJ', 1),
(9, 3, 3, 10, 'Third Year Electronics', 'TYEJ', 1)
ON CONFLICT (class_id) DO NOTHING;

INSERT INTO divisions (division_id, class_id, division_name, max_capacity) VALUES
(1, 1, 'A', 60),
(2, 1, 'B', 60),
(3, 2, 'A', 60),
(4, 3, 'A', 60),
(5, 4, 'A', 60),
(6, 5, 'A', 60),
(7, 6, 'A', 60),
(8, 7, 'A', 60),
(9, 8, 'A', 60),
(10, 9, 'A', 60)
ON CONFLICT (division_id) DO NOTHING;

-- 7. Subjects
INSERT INTO subjects (subject_id, subject_code, subject_name, department_id, semester_number, credits, total_lectures) VALUES
(1, '22316', 'Data Structures Using C', 1, 3, 4, 45),
(2, '22317', 'Database Management System', 1, 3, 4, 45),
(3, '22318', 'Computer Graphics', 1, 3, 3, 36),
(4, '22319', 'Object Oriented Programming using C++', 1, 3, 4, 45),
(5, '22320', 'Digital Techniques', 1, 3, 4, 45),
(6, '22014', 'Principles of Electronic Communication', 3, 3, 3, 36),
(7, '22103', 'Basic Mathematics', 1, 1, 4, 45),
(8, '22226', 'Programming in C', 1, 1, 4, 45),
(9, '22517', 'Advanced Java Programming', 1, 5, 4, 45),
(10, '22413', 'Software Engineering', 1, 5, 3, 36)
ON CONFLICT (subject_id) DO NOTHING;

-- 8. Users & Accounts
-- =====================================================================
-- WARNING: DEVELOPMENT & DEMO PURPOSES ONLY
-- For production deployments, all users should change passwords upon first
-- login or use institutional Single Sign-On (SSO / LDAP / Google Workspace).
-- Seed Demo Passwords:
-- Admin:    admin@sams.edu           / Admin@12345
-- Teachers: teacher@sams.edu         / Teacher@12345
-- Students: vishal.yadav@sams.edu    / Student@12345
-- =====================================================================
INSERT INTO users (user_id, role_id, email, password_hash, status) VALUES
(1, 1, 'admin@sams.edu', '$2y$10$T0lhi.fBoPbG16ePMKbaBuayUjpPfXVz1B9sHZL3pi5cCDhNXgdES', 'ACTIVE'),
-- Teachers (IDs 2-6) - Single active teacher: Prof. Kalpesh Sir
(2, 2, 'teacher@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'ACTIVE'),
(3, 2, 'teacher.patel@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'INACTIVE'),
(4, 2, 'teacher.verma@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'INACTIVE'),
(5, 2, 'teacher.iyer@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'INACTIVE'),
(6, 2, 'teacher.deshmukh@sams.edu', '$2y$10$IlX.kOQ3AbLVA4sUJGYNnOR1GvV8T94bqwgurdr3n8U7ZTLZ0AUua', 'INACTIVE'),
-- Students (IDs 7-26)
(7, 3, 'vishal.yadav@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(8, 3, 'aarav.sharma@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(9, 3, 'ananya.iyer@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(10, 3, 'rohan.joshi@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(11, 3, 'sneha.kulkarni@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(12, 3, 'aditya.chavan@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(13, 3, 'diya.patil@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(14, 3, 'siddharth.nair@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(15, 3, 'tanvi.shinde@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(16, 3, 'rahul.desai@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(17, 3, 'neha.mehta@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(18, 3, 'kunal.singh@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(19, 3, 'pooja.more@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(20, 3, 'varun.rao@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(21, 3, 'ishita.gupta@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(22, 3, 'harsh.sawant@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(23, 3, 'meera.bhatt@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(24, 3, 'aman.khan@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(25, 3, 'sakshi.pawar@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(26, 3, 'pranav.salunkhe@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
-- -- FY CO Students (User IDs 201-210)
(201, 3, 'aryan.patil@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(202, 3, 'ishaan.deshmukh@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(203, 3, 'riya.sharma@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(204, 3, 'vedant.joshi@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(205, 3, 'tanmay.kulkarni@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(206, 3, 'ananya.more@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(207, 3, 'saurabh.chavan@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(208, 3, 'shreya.jadhav@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(209, 3, 'rohit.sawant@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(210, 3, 'prachi.nair@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
-- TY CO Students (User IDs 211-220)
(211, 3, 'chinmay.bapat@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(212, 3, 'gaurav.kale@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(213, 3, 'mansi.rane@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(214, 3, 'nikhil.shinde@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(215, 3, 'pallavi.vaidya@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(216, 3, 'ruturaj.thorat@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(217, 3, 'sayali.ghatke@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(218, 3, 'swapnil.mane@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(219, 3, 'tejas.wagh@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
(220, 3, 'vaishnavi.shete@sams.edu', '$2y$10$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE')
ON CONFLICT (user_id) DO NOTHING;

-- 9. Admin Profile
INSERT INTO admins (admin_id, user_id, full_name, phone, designation) VALUES
(1, 1, 'Madhura Mam', '+91 98230 11223', 'Dean & Chief Administrator')
ON CONFLICT (admin_id) DO UPDATE SET full_name = 'Madhura Mam';

-- 10. Teacher Profiles (Single active teacher: Prof. Kalpesh Sir)
INSERT INTO teachers (teacher_id, user_id, employee_id, full_name, phone, department_id, designation, status) VALUES
(1, 2, 'EMP-CO-01', 'Prof. Kalpesh Sir', '+91 98221 44551', 1, 'Senior Faculty - Computer Engineering', 'ACTIVE'),
(2, 3, 'EMP-CO-02', 'Prof. Priya Patel', '+91 98221 44552', 1, 'Assistant Professor (Computer)', 'INACTIVE'),
(3, 4, 'EMP-IT-01', 'Prof. Amit Verma', '+91 98221 44553', 2, 'Senior Lecturer (IT)', 'INACTIVE'),
(4, 5, 'EMP-IT-02', 'Prof. Sneha Iyer', '+91 98221 44554', 2, 'Assistant Professor (IT)', 'INACTIVE'),
(5, 6, 'EMP-EJ-01', 'Prof. Vikram Deshmukh', '+91 98221 44555', 3, 'Lecturer (Electronics)', 'INACTIVE')
ON CONFLICT (teacher_id) DO UPDATE SET full_name = EXCLUDED.full_name, status = EXCLUDED.status;

-- 11. Student Profiles (20 students in SY CO Division A)
INSERT INTO students (student_id, user_id, roll_number, student_uid, full_name, email, phone, date_of_birth, gender, department_id, course_id, class_id, division_id, batch, admission_year, face_verification_status) VALUES
(1, 7, 'CO-2101', 'UID2026001', 'Vishal Yadav', 'vishal.yadav@sams.edu', '+91 98111 00001', '2005-04-12', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(2, 8, 'CO-2102', 'UID2026002', 'Aarav Sharma', 'aarav.sharma@sams.edu', '+91 98111 00002', '2005-06-18', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(3, 9, 'CO-2103', 'UID2026003', 'Ananya Iyer', 'ananya.iyer@sams.edu', '+91 98111 00003', '2005-08-22', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(4, 10, 'CO-2104', 'UID2026004', 'Rohan Joshi', 'rohan.joshi@sams.edu', '+91 98111 00004', '2005-02-10', 'Male', 1, 1, 1, 1, 'B1', 2024, 'NOT_ENROLLED'),
(5, 11, 'CO-2105', 'UID2026005', 'Sneha Patil', 'sneha.patil@sams.edu', '+91 98111 00005', '2005-11-05', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(6, 12, 'CO-2106', 'UID2026006', 'Aditya Kulkarni', 'aditya.kulkarni@sams.edu', '+91 98111 00006', '2005-09-14', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(7, 13, 'CO-2107', 'UID2026007', 'Pooja Deshmukh', 'pooja.deshmukh@sams.edu', '+91 98111 00007', '2005-07-29', 'Female', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(8, 14, 'CO-2108', 'UID2026008', 'Rahul Shinde', 'rahul.shinde@sams.edu', '+91 98111 00008', '2005-01-19', 'Male', 1, 1, 1, 1, 'B2', 2024, 'ENROLLED'),
(9, 15, 'CO-2109', 'UID2026009', 'Priya Nair', 'priya.nair@sams.edu', '+91 98111 00009', '2005-12-01', 'Female', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(10, 16, 'CO-2110', 'UID2026010', 'Kunal More', 'kunal.more@sams.edu', '+91 98111 00010', '2005-03-25', 'Male', 1, 1, 1, 1, 'B2', 2024, 'ENROLLED'),
(11, 17, 'CO-2111', 'UID2026011', 'Tanvi Mehta', 'tanvi.mehta@sams.edu', '+91 98111 00011', '2005-05-15', 'Female', 1, 1, 1, 1, 'B1', 2024, 'NOT_ENROLLED'),
(12, 18, 'CO-2112', 'UID2026012', 'Siddharth Chavan', 'siddharth.chavan@sams.edu', '+91 98111 00012', '2005-10-30', 'Male', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(13, 19, 'CO-2113', 'UID2026013', 'Neha Bhosale', 'neha.bhosale@sams.edu', '+91 98111 00013', '2005-02-18', 'Female', 1, 1, 1, 1, 'B1', 2024, 'NOT_ENROLLED'),
(14, 20, 'CO-2114', 'UID2026014', 'Varun Rao', 'varun.rao@sams.edu', '+91 98111 00014', '2005-07-07', 'Male', 1, 1, 1, 1, 'B1', 2024, 'NOT_ENROLLED'),
(15, 21, 'CO-2115', 'UID2026015', 'Ishita Gupta', 'ishita.gupta@sams.edu', '+91 98111 00015', '2005-08-11', 'Female', 1, 1, 1, 1, 'B1', 2024, 'ENROLLED'),
(16, 22, 'CO-2116', 'UID2026016', 'Harsh Sawant', 'harsh.sawant@sams.edu', '+91 98111 00016', '2005-04-03', 'Male', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(17, 23, 'CO-2117', 'UID2026017', 'Meera Bhatt', 'meera.bhatt@sams.edu', '+91 98111 00017', '2005-09-22', 'Female', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(18, 24, 'CO-2118', 'UID2026018', 'Aman Khan', 'aman.khan@sams.edu', '+91 98111 00018', '2005-06-05', 'Male', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(19, 25, 'CO-2119', 'UID2026019', 'Sakshi Pawar', 'sakshi.pawar@sams.edu', '+91 98111 00019', '2005-12-14', 'Female', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
(20, 26, 'CO-2120', 'UID2026020', 'Pranav Salunkhe', 'pranav.salunkhe@sams.edu', '+91 98111 00020', '2005-03-08', 'Male', 1, 1, 1, 1, 'B2', 2024, 'NOT_ENROLLED'),
-- FY CO Students (Class ID 4, Division ID 5, Student IDs 201-210)
(201, 201, 'CO-1101', 'UID2026101', 'Aryan Patil', 'aryan.patil@sams.edu', '+91 98111 10001', '2006-04-12', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(202, 202, 'CO-1102', 'UID2026102', 'Ishaan Deshmukh', 'ishaan.deshmukh@sams.edu', '+91 98111 10002', '2006-06-18', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(203, 203, 'CO-1103', 'UID2026103', 'Riya Sharma', 'riya.sharma@sams.edu', '+91 98111 10003', '2006-08-22', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(204, 204, 'CO-1104', 'UID2026104', 'Vedant Joshi', 'vedant.joshi@sams.edu', '+91 98111 10004', '2006-02-10', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(205, 205, 'CO-1105', 'UID2026105', 'Tanmay Kulkarni', 'tanmay.kulkarni@sams.edu', '+91 98111 10005', '2006-11-05', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(206, 206, 'CO-1106', 'UID2026106', 'Ananya More', 'ananya.more@sams.edu', '+91 98111 10006', '2006-09-14', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
(207, 207, 'CO-1107', 'UID2026107', 'Saurabh Chavan', 'saurabh.chavan@sams.edu', '+91 98111 10007', '2006-07-29', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
(208, 208, 'CO-1108', 'UID2026108', 'Shreya Jadhav', 'shreya.jadhav@sams.edu', '+91 98111 10008', '2006-01-19', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
(209, 209, 'CO-1109', 'UID2026109', 'Rohit Sawant', 'rohit.sawant@sams.edu', '+91 98111 10009', '2006-12-01', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
(210, 210, 'CO-1110', 'UID2026110', 'Prachi Nair', 'prachi.nair@sams.edu', '+91 98111 10010', '2006-03-25', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
-- TY CO Students (Class ID 5, Division ID 6, Student IDs 211-220)
(211, 211, 'CO-3101', 'UID2026301', 'Chinmay Bapat', 'chinmay.bapat@sams.edu', '+91 98111 30001', '2004-04-12', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(212, 212, 'CO-3102', 'UID2026302', 'Gaurav Kale', 'gaurav.kale@sams.edu', '+91 98111 30002', '2004-06-18', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(213, 213, 'CO-3103', 'UID2026303', 'Mansi Rane', 'mansi.rane@sams.edu', '+91 98111 30003', '2004-08-22', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(214, 214, 'CO-3104', 'UID2026304', 'Nikhil Shinde', 'nikhil.shinde@sams.edu', '+91 98111 30004', '2004-02-10', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(215, 215, 'CO-3105', 'UID2026305', 'Pallavi Vaidya', 'pallavi.vaidya@sams.edu', '+91 98111 30005', '2004-11-05', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(216, 216, 'CO-3106', 'UID2026306', 'Ruturaj Thorat', 'ruturaj.thorat@sams.edu', '+91 98111 30006', '2004-09-14', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
(217, 217, 'CO-3107', 'UID2026307', 'Sayali Ghatke', 'sayali.ghatke@sams.edu', '+91 98111 30007', '2004-07-29', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
(218, 218, 'CO-3108', 'UID2026308', 'Swapnil Mane', 'swapnil.mane@sams.edu', '+91 98111 30008', '2004-01-19', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
(219, 219, 'CO-3109', 'UID2026309', 'Tejas Wagh', 'tejas.wagh@sams.edu', '+91 98111 30009', '2004-12-01', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
(220, 220, 'CO-3110', 'UID2026310', 'Vaishnavi Shete', 'vaishnavi.shete@sams.edu', '+91 98111 30010', '2004-03-25', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED')
ON CONFLICT (student_id) DO NOTHING;

-- 12. Biometric Profiles (Simulated face feature representations for enrolled students)
INSERT INTO face_profiles (student_id, status, biometric_hash, feature_vector, samples_count, consent_given) VALUES
(1, 'ENROLLED', 'sha256_hash_student_01_feature_vec', '{"landmarks":128,"embedding_version":"sams-v1","clarity":0.94}', 3, true),
(2, 'ENROLLED', 'sha256_hash_student_02_feature_vec', '{"landmarks":128,"embedding_version":"sams-v1","clarity":0.91}', 3, true),
(3, 'ENROLLED', 'sha256_hash_student_03_feature_vec', '{"landmarks":128,"embedding_version":"sams-v1","clarity":0.96}', 3, true),
(5, 'ENROLLED', 'sha256_hash_student_05_feature_vec', '{"landmarks":128,"embedding_version":"sams-v1","clarity":0.93}', 3, true),
(6, 'ENROLLED', 'sha256_hash_student_06_feature_vec', '{"landmarks":128,"embedding_version":"sams-v1","clarity":0.89}', 3, true)
ON CONFLICT (student_id) DO NOTHING;

-- 13. Subject Allocations to Teachers (Allocated to Prof. Kalpesh Sir)
INSERT INTO teacher_subjects (id, teacher_id, subject_id, class_id, division_id, academic_year_id) VALUES
(1, 1, 1, 1, 1, 1), -- Prof. Kalpesh Sir -> Data Structures (SY CO A)
(2, 1, 2, 1, 1, 1), -- Prof. Kalpesh Sir -> DBMS (SY CO A)
(3, 1, 4, 1, 1, 1), -- Prof. Kalpesh Sir -> OOP C++ (SY CO A)
(4, 1, 3, 1, 1, 1), -- Prof. Kalpesh Sir -> Computer Graphics (SY CO A)
(5, 1, 5, 1, 1, 1), -- Prof. Kalpesh Sir -> Digital Techniques (SY CO A)
(6, 1, 8, 4, 5, 1), -- Prof. Kalpesh Sir -> Programming in C (FY CO A)
(7, 1, 9, 5, 6, 1)  -- Prof. Kalpesh Sir -> Advanced Java (TY CO A)
ON CONFLICT (id) DO UPDATE SET teacher_id = EXCLUDED.teacher_id;

-- 14. Timetable (Teacher Schedule)
INSERT INTO timetables (timetable_id, class_id, division_id, subject_id, teacher_id, day_of_week, start_time, end_time, room_number, academic_year_id) VALUES
(1, 1, 1, 1, 1, 'Monday', '08:00:00', '09:00:00', 'Room 204', 1),
(2, 1, 1, 2, 1, 'Monday', '09:00:00', '10:00:00', 'Lab 1', 1),
(3, 1, 1, 4, 1, 'Monday', '10:15:00', '11:15:00', 'Room 204', 1),
(4, 1, 1, 1, 1, 'Tuesday', '08:00:00', '09:00:00', 'Room 204', 1),
(5, 1, 1, 3, 1, 'Tuesday', '09:00:00', '10:00:00', 'CAD Lab', 1),
(6, 1, 1, 5, 1, 'Wednesday', '08:00:00', '09:00:00', 'Digital Lab', 1),
(7, 1, 1, 1, 1, 'Wednesday', '09:00:00', '10:00:00', 'Room 204', 1),
(8, 1, 1, 2, 1, 'Thursday', '08:00:00', '09:00:00', 'Room 204', 1),
(9, 1, 1, 4, 1, 'Thursday', '09:00:00', '10:00:00', 'Lab 2', 1),
(10, 1, 1, 1, 1, 'Friday', '08:00:00', '09:00:00', 'Room 204', 1),
(11, 1, 1, 3, 1, 'Friday', '09:00:00', '10:00:00', 'CAD Lab', 1)
ON CONFLICT (timetable_id) DO UPDATE SET teacher_id = EXCLUDED.teacher_id;

-- 15. Historical Attendance Sessions (Over 25 lectures covering August-September 2026)
-- Data Structures sessions conducted by Dr. Rajesh Sharma
INSERT INTO attendance_sessions (session_id, class_id, division_id, subject_id, teacher_id, session_date, start_time, end_time, lecture_number, status, verification_mode) VALUES
(1, 1, 1, 1, 1, '2026-08-03', '08:00:00', '09:00:00', 1, 'CLOSED', 'FACE_AI'),
(2, 1, 1, 1, 1, '2026-08-04', '08:00:00', '09:00:00', 2, 'CLOSED', 'HYBRID'),
(3, 1, 1, 1, 1, '2026-08-05', '09:00:00', '10:00:00', 3, 'CLOSED', 'FACE_AI'),
(4, 1, 1, 1, 1, '2026-08-07', '08:00:00', '09:00:00', 4, 'CLOSED', 'MANUAL'),
(5, 1, 1, 1, 1, '2026-08-10', '08:00:00', '09:00:00', 5, 'CLOSED', 'FACE_AI'),
(6, 1, 1, 1, 1, '2026-08-11', '08:00:00', '09:00:00', 6, 'CLOSED', 'FACE_AI'),
(7, 1, 1, 1, 1, '2026-08-12', '09:00:00', '10:00:00', 7, 'CLOSED', 'FACE_AI'),
(8, 1, 1, 1, 1, '2026-08-14', '08:00:00', '09:00:00', 8, 'CLOSED', 'HYBRID'),
(9, 1, 1, 1, 1, '2026-08-17', '08:00:00', '09:00:00', 9, 'CLOSED', 'FACE_AI'),
(10, 1, 1, 1, 1, '2026-08-18', '08:00:00', '09:00:00', 10, 'CLOSED', 'FACE_AI'),
(11, 1, 1, 1, 1, '2026-08-19', '09:00:00', '10:00:00', 11, 'CLOSED', 'MANUAL'),
(12, 1, 1, 1, 1, '2026-08-21', '08:00:00', '09:00:00', 12, 'CLOSED', 'FACE_AI'),
(13, 1, 1, 1, 1, '2026-08-24', '08:00:00', '09:00:00', 13, 'CLOSED', 'FACE_AI'),
(14, 1, 1, 1, 1, '2026-08-25', '08:00:00', '09:00:00', 14, 'CLOSED', 'FACE_AI'),
(15, 1, 1, 1, 1, '2026-08-26', '09:00:00', '10:00:00', 15, 'CLOSED', 'HYBRID'),
(16, 1, 1, 1, 1, '2026-08-28', '08:00:00', '09:00:00', 16, 'CLOSED', 'FACE_AI'),
(17, 1, 1, 1, 1, '2026-08-31', '08:00:00', '09:00:00', 17, 'CLOSED', 'FACE_AI'),
(18, 1, 1, 1, 1, '2026-09-01', '08:00:00', '09:00:00', 18, 'CLOSED', 'FACE_AI'),
(19, 1, 1, 1, 1, '2026-09-02', '09:00:00', '10:00:00', 19, 'CLOSED', 'FACE_AI'),
(20, 1, 1, 1, 1, '2026-09-04', '08:00:00', '09:00:00', 20, 'CLOSED', 'HYBRID'),
(21, 1, 1, 1, 1, '2026-09-07', '08:00:00', '09:00:00', 21, 'CLOSED', 'FACE_AI'),
(22, 1, 1, 1, 1, '2026-09-08', '08:00:00', '09:00:00', 22, 'CLOSED', 'FACE_AI'),
(23, 1, 1, 1, 1, '2026-09-09', '09:00:00', '10:00:00', 23, 'CLOSED', 'FACE_AI'),
(24, 1, 1, 1, 1, '2026-09-11', '08:00:00', '09:00:00', 24, 'CLOSED', 'FACE_AI'),
-- Today's OPEN session for live demonstration!
(25, 1, 1, 1, 1, CURRENT_DATE, '08:00:00', '09:00:00', 25, 'OPEN', 'FACE_AI')
ON CONFLICT (session_id) DO NOTHING;

-- 16. Attendance Records Generation for Student 1 (Vishal Yadav - 68% low attendance demo case)
-- 24 historical sessions: 16 Present, 1 Late, 7 Absent = 17 / 24 = 70.8% (triggers low-attendance warning)
INSERT INTO attendance_records (session_id, student_id, status, verification_method, confidence_score) VALUES
(1, 1, 'PRESENT', 'FACE_AI', 0.9421),
(2, 1, 'PRESENT', 'FACE_AI', 0.9610),
(3, 1, 'ABSENT', 'MANUAL', NULL),
(4, 1, 'PRESENT', 'MANUAL', NULL),
(5, 1, 'PRESENT', 'FACE_AI', 0.9125),
(6, 1, 'ABSENT', 'MANUAL', NULL),
(7, 1, 'PRESENT', 'FACE_AI', 0.9542),
(8, 1, 'LATE', 'MANUAL', NULL),
(9, 1, 'ABSENT', 'MANUAL', NULL),
(10, 1, 'PRESENT', 'FACE_AI', 0.9234),
(11, 1, 'ABSENT', 'MANUAL', NULL),
(12, 1, 'PRESENT', 'FACE_AI', 0.9780),
(13, 1, 'PRESENT', 'FACE_AI', 0.9341),
(14, 1, 'ABSENT', 'MANUAL', NULL),
(15, 1, 'PRESENT', 'FACE_AI', 0.9110),
(16, 1, 'PRESENT', 'FACE_AI', 0.9455),
(17, 1, 'ABSENT', 'MANUAL', NULL),
(18, 1, 'PRESENT', 'FACE_AI', 0.9678),
(19, 1, 'PRESENT', 'FACE_AI', 0.9230),
(20, 1, 'PRESENT', 'FACE_AI', 0.9512),
(21, 1, 'ABSENT', 'MANUAL', NULL),
(22, 1, 'PRESENT', 'FACE_AI', 0.9419),
(23, 1, 'PRESENT', 'FACE_AI', 0.9328),
(24, 1, 'PRESENT', 'FACE_AI', 0.9744)
ON CONFLICT (session_id, student_id) DO NOTHING;

-- Records for Student 2 (Aarav Sharma - High attendance 91.6%)
INSERT INTO attendance_records (session_id, student_id, status, verification_method, confidence_score) VALUES
(1, 2, 'PRESENT', 'FACE_AI', 0.9612),
(2, 2, 'PRESENT', 'FACE_AI', 0.9540),
(3, 2, 'PRESENT', 'FACE_AI', 0.9821),
(4, 2, 'PRESENT', 'MANUAL', NULL),
(5, 2, 'PRESENT', 'FACE_AI', 0.9320),
(6, 2, 'PRESENT', 'FACE_AI', 0.9712),
(7, 2, 'PRESENT', 'FACE_AI', 0.9433),
(8, 2, 'PRESENT', 'FACE_AI', 0.9540),
(9, 2, 'PRESENT', 'FACE_AI', 0.9665),
(10, 2, 'PRESENT', 'FACE_AI', 0.9511),
(11, 2, 'ABSENT', 'MANUAL', NULL),
(12, 2, 'PRESENT', 'FACE_AI', 0.9723),
(13, 2, 'PRESENT', 'FACE_AI', 0.9641),
(14, 2, 'PRESENT', 'FACE_AI', 0.9532),
(15, 2, 'PRESENT', 'FACE_AI', 0.9810),
(16, 2, 'PRESENT', 'FACE_AI', 0.9490),
(17, 2, 'PRESENT', 'FACE_AI', 0.9654),
(18, 2, 'PRESENT', 'FACE_AI', 0.9711),
(19, 2, 'PRESENT', 'FACE_AI', 0.9345),
(20, 2, 'ABSENT', 'MANUAL', NULL),
(21, 2, 'PRESENT', 'FACE_AI', 0.9754),
(22, 2, 'PRESENT', 'FACE_AI', 0.9632),
(23, 2, 'PRESENT', 'FACE_AI', 0.9543),
(24, 2, 'PRESENT', 'FACE_AI', 0.9812)
ON CONFLICT (session_id, student_id) DO NOTHING;

-- Seed attendance for remaining students in session 24 to populate class roster stats
INSERT INTO attendance_records (session_id, student_id, status, verification_method, confidence_score) VALUES
(24, 3, 'PRESENT', 'FACE_AI', 0.9820),
(24, 4, 'ABSENT', 'MANUAL', NULL),
(24, 5, 'PRESENT', 'FACE_AI', 0.9645),
(24, 6, 'PRESENT', 'FACE_AI', 0.9123),
(24, 7, 'PRESENT', 'FACE_AI', 0.9541),
(24, 8, 'PRESENT', 'FACE_AI', 0.9321),
(24, 9, 'LATE', 'MANUAL', NULL),
(24, 10, 'PRESENT', 'FACE_AI', 0.9412),
(24, 11, 'PRESENT', 'FACE_AI', 0.9715),
(24, 12, 'ABSENT', 'MANUAL', NULL),
(24, 13, 'PRESENT', 'FACE_AI', 0.9234),
(24, 14, 'PRESENT', 'MANUAL', NULL),
(24, 15, 'PRESENT', 'FACE_AI', 0.9542),
(24, 16, 'PRESENT', 'FACE_AI', 0.9611),
(24, 17, 'PRESENT', 'FACE_AI', 0.9423),
(24, 18, 'PRESENT', 'FACE_AI', 0.9751),
(24, 19, 'PRESENT', 'FACE_AI', 0.9532),
(24, 20, 'PRESENT', 'FACE_AI', 0.9641)
ON CONFLICT (session_id, student_id) DO NOTHING;

-- 17. Audit Logs
INSERT INTO audit_logs (user_id, action, entity, entity_id, ip_address, user_agent, metadata) VALUES
(1, 'SYSTEM_INIT', 'SYSTEM', '1', '127.0.0.1', 'CLI Seed Script', '{"version":"1.0.0","environment":"development"}'),
(2, 'SESSION_CREATED', 'attendance_sessions', '25', '192.168.1.102', 'Mozilla/5.0 SAMS Client', '{"subject":"Data Structures","class":"SYCO-A"}');

-- 18. Notifications
INSERT INTO notifications (user_id, title, message, type) VALUES
(7, 'Low Attendance Warning', 'Your attendance in Data Structures is currently 70.8%, which is below the mandatory 75% threshold.', 'ALERT'),
(7, 'Attendance Marked', 'You were marked Present in Data Structures on Sep 11 at 08:04 AM via AI Face Verification.', 'ATTENDANCE'),
(1, 'System Announcement', 'Mid-term attendance audit scheduled for next week. Ensure all sessions are reconciled.', 'ANNOUNCEMENT'),
(2, 'Class Timetable Reminder', 'Your lecture for Data Structures (SY CO Division A) starts at 08:00 AM in Room 204.', 'ALERT');

-- =====================================================================
-- 19. Synchronize All PostgreSQL Auto-Increment Sequences
-- Advances sequences to MAX(id) so new INSERTs receive the next ID
-- =====================================================================
DO $$
DECLARE
    rec RECORD;
    seq_name TEXT;
    max_val BIGINT;
    curr_seq BIGINT;
    is_called BOOL;
BEGIN
    FOR rec IN
        SELECT 
            c.table_schema,
            c.table_name,
            c.column_name
        FROM information_schema.columns c
        JOIN information_schema.tables t 
            ON c.table_name = t.table_name AND c.table_schema = t.table_schema
        WHERE c.table_schema = 'public'
          AND t.table_type = 'BASE TABLE'
          AND (
              c.column_default LIKE 'nextval(%'
              OR c.is_identity = 'YES'
          )
    LOOP
        seq_name := pg_get_serial_sequence(quote_ident(rec.table_schema) || '.' || quote_ident(rec.table_name), rec.column_name);
        IF seq_name IS NOT NULL THEN
            EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I.%I', rec.column_name, rec.table_schema, rec.table_name) INTO max_val;
            IF max_val > 0 THEN
                BEGIN
                    EXECUTE format('SELECT last_value, is_called FROM %s', seq_name) INTO curr_seq, is_called;
                    IF curr_seq < max_val OR (curr_seq = max_val AND NOT is_called) THEN
                        EXECUTE format('SELECT setval(%L::regclass, %s, true)', seq_name, max_val);
                    END IF;
                EXCEPTION WHEN OTHERS THEN
                    EXECUTE format('SELECT setval(%L::regclass, %s, true)', seq_name, max_val);
                END;
            ELSE
                BEGIN
                    EXECUTE format('SELECT setval(%L::regclass, 1, false)', seq_name);
                EXCEPTION WHEN OTHERS THEN
                    NULL;
                END;
            END IF;
        END IF;
    END LOOP;
END $$;
