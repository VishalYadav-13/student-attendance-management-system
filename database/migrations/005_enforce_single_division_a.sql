-- SAMS Migration 005: Enforce Single Division A System-wide
-- Permanently removes Division B and ensures all students, classes, and attendance sessions use Division A.

-- 1. Ensure all students in Computer Engineering and IT belong to Division A
UPDATE students SET division_id = 1 WHERE class_id = 1 AND (division_id != 1 OR division_id IS NULL);
UPDATE students SET division_id = 3 WHERE class_id = 2 AND (division_id != 3 OR division_id IS NULL);
UPDATE students SET division_id = 4 WHERE class_id = 3 AND (division_id != 4 OR division_id IS NULL);
UPDATE students SET division_id = 5 WHERE class_id = 4 AND (division_id != 5 OR division_id IS NULL);
UPDATE students SET division_id = 6 WHERE class_id = 5 AND (division_id != 6 OR division_id IS NULL);
UPDATE students SET division_id = 7 WHERE class_id = 6 AND (division_id != 7 OR division_id IS NULL);
UPDATE students SET division_id = 8 WHERE class_id = 7 AND (division_id != 8 OR division_id IS NULL);
UPDATE students SET division_id = 9 WHERE class_id = 8 AND (division_id != 9 OR division_id IS NULL);
UPDATE students SET division_id = 10 WHERE class_id = 9 AND (division_id != 10 OR division_id IS NULL);

-- 2. Safely migrate attendance sessions to Division A of their respective classes
UPDATE attendance_sessions SET division_id = 1 WHERE class_id = 1 AND (division_id = 2 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 3 WHERE class_id = 2 AND (division_id != 3 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 4 WHERE class_id = 3 AND (division_id != 4 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 5 WHERE class_id = 4 AND (division_id != 5 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 6 WHERE class_id = 5 AND (division_id != 6 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 7 WHERE class_id = 6 AND (division_id != 7 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 8 WHERE class_id = 7 AND (division_id != 8 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 9 WHERE class_id = 8 AND (division_id != 9 OR division_id IS NULL);
UPDATE attendance_sessions SET division_id = 10 WHERE class_id = 9 AND (division_id != 10 OR division_id IS NULL);

-- 3. Safely migrate timetables and teacher_subjects to Division A
UPDATE timetables SET division_id = 1 WHERE class_id = 1 AND (division_id = 2 OR division_id IS NULL);
UPDATE teacher_subjects SET division_id = 1 WHERE class_id = 1 AND (division_id = 2 OR division_id IS NULL);

-- 4. Delete Division B from divisions table so only Division A exists
DELETE FROM divisions WHERE division_name = 'B' OR division_id = 2;
