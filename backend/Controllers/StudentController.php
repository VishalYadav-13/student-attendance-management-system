<?php
/**
 * SAMS - Student Management Controller
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\AttendanceService;
use SAMS\Services\AuditService;
use SAMS\Utils\Response;
use SAMS\Utils\Validator;
use PDO;
use Exception;

class StudentController
{
    /**
     * List Students with Search, Filters, and Pagination
     * GET /api/students
     */
    public static function index(): void
    {
        $user = AuthMiddleware::authenticate();
        // Students cannot list all other students
        if ($user['role_name'] === 'STUDENT') {
            Response::forbidden("Students cannot view the institution-wide student directory.");
        }

        $pdo = Database::getConnection();

        $search = trim($_GET['search'] ?? '');
        $deptId = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
        $classId = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $divisionId = !empty($_GET['division_id']) ? (int)$_GET['division_id'] : null;
        $status = $_GET['status'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $conditions = ["1=1"];
        $params = [];

        if ($search !== '') {
            $conditions[] = "(LOWER(s.full_name) LIKE :q OR LOWER(s.roll_number) LIKE :q OR LOWER(s.email) LIKE :q OR LOWER(s.student_uid) LIKE :q)";
            $params[':q'] = '%' . strtolower($search) . '%';
        }
        if ($deptId !== null) {
            $conditions[] = "s.department_id = :dept";
            $params[':dept'] = $deptId;
        }
        if ($classId !== null) {
            $conditions[] = "s.class_id = :cid";
            $params[':cid'] = $classId;
        }
        if ($divisionId !== null) {
            $conditions[] = "s.division_id = :did";
            $params[':did'] = $divisionId;
        }
        if ($status !== null && in_array($status, ['ACTIVE', 'INACTIVE', 'SUSPENDED'], true)) {
            $conditions[] = "s.status = :stat";
            $params[':stat'] = $status;
        }

        $whereClause = implode(' AND ', $conditions);

        // Count total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Fetch page
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id,
                s.roll_number,
                s.student_uid,
                s.full_name,
                s.email,
                s.phone,
                s.gender,
                s.batch,
                s.status,
                s.face_verification_status,
                dept.department_code,
                dept.department_name,
                c.class_name,
                c.class_code,
                d.division_name
            FROM students s
            JOIN departments dept ON s.department_id = dept.department_id
            JOIN classes c ON s.class_id = c.class_id
            JOIN divisions d ON s.division_id = d.division_id
            WHERE {$whereClause}
            ORDER BY s.roll_number ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll();

        Response::success([
            'students' => $students,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_records' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ], 'Students retrieved.');
    }

    /**
     * Show Single Student Profile & Attendance Overview
     * GET /api/students/{id}
     */
    public static function show(int $studentId): void
    {
        $user = AuthMiddleware::authenticate();

        // STRICT ACCESS CONTROL: Student A cannot view Student B
        if ($user['role_name'] === 'STUDENT' && (int)$user['student_id'] !== $studentId) {
            Response::forbidden("Access denied: You cannot view attendance or profile information for another student.");
            return;
        }

        $pdo = Database::getConnection();

        // IDOR PROTECTION: Teachers can only view students in their assigned classes or sessions
        if ($user['role_name'] === 'TEACHER') {
            $teacherId = (int)$user['teacher_id'];
            $authCheck = $pdo->prepare("
                SELECT 1 FROM students s
                WHERE s.student_id = :sid AND (
                    EXISTS (
                        SELECT 1 FROM teacher_subjects ts 
                        WHERE ts.teacher_id = :tid AND ts.class_id = s.class_id AND ts.division_id = s.division_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM attendance_sessions ses
                        JOIN attendance_records ar ON ar.session_id = ses.session_id
                        WHERE ses.teacher_id = :tid2 AND ar.student_id = :sid2
                    )
                )
            ");
            $authCheck->execute([':sid' => $studentId, ':tid' => $teacherId, ':tid2' => $teacherId, ':sid2' => $studentId]);
            if (!$authCheck->fetch()) {
                Response::forbidden("Access denied: You can only view profiles of students in your assigned classes.");
                return;
            }
        }
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, s.user_id, s.roll_number, s.student_uid, s.full_name, s.email, s.phone,
                s.date_of_birth, s.gender, s.batch, s.admission_year, s.status, s.face_verification_status,
                dept.department_id, dept.department_name, dept.department_code,
                c.class_id, c.class_name, c.class_code,
                d.division_id, d.division_name
            FROM students s
            JOIN departments dept ON s.department_id = dept.department_id
            JOIN classes c ON s.class_id = c.class_id
            JOIN divisions d ON s.division_id = d.division_id
            WHERE s.student_id = :sid
        ");
        $stmt->execute([':sid' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            Response::notFound("Student #{$studentId} not found.");
        }

        $stats = AttendanceService::getStudentStats($studentId);
        $breakdown = AttendanceService::getStudentSubjectBreakdown($studentId);

        Response::success([
            'student' => $student,
            'attendance_stats' => $stats,
            'subject_breakdown' => $breakdown
        ], 'Student details retrieved.');
    }

    /**
     * Add Student (Admin Only)
     * POST /api/students
     */
    public static function store(): void
    {
        $admin = RoleMiddleware::adminOnly();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('full_name', 'email', 'roll_number', 'student_uid', 'department_id', 'class_id', 'division_id')
            ->email('email')
            ->numeric('department_id')
            ->numeric('class_id')
            ->numeric('division_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $email = strtolower(trim($input['email']));
        $roll = trim($input['roll_number']);
        $uid = trim($input['student_uid']);
        $name = trim($input['full_name']);
        $phone = $input['phone'] ?? null;
        $gender = $input['gender'] ?? 'Other';
        $deptId = (int)$input['department_id'];
        $classId = (int)$input['class_id'];
        $divId = (int)$input['division_id'];
        $batch = $input['batch'] ?? 'General';
        $admYear = (int)($input['admission_year'] ?? date('Y'));

        $pdo = Database::getConnection();

        // 1. Check uniqueness of email in users and students
        $chkUserEmail = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email");
        $chkUserEmail->execute([':email' => $email]);
        if ($chkUserEmail->fetch()) {
            Response::conflict("An account with email '{$email}' already exists.");
            return;
        }

        $chkStudentEmail = $pdo->prepare("SELECT student_id FROM students WHERE LOWER(email) = :email");
        $chkStudentEmail->execute([':email' => $email]);
        if ($chkStudentEmail->fetch()) {
            Response::conflict("Student email '{$email}' is already registered.");
            return;
        }

        // 2. Check uniqueness of roll number in students
        $chkRoll = $pdo->prepare("SELECT student_id FROM students WHERE roll_number = :roll");
        $chkRoll->execute([':roll' => $roll]);
        if ($chkRoll->fetch()) {
            Response::conflict("Roll number '{$roll}' is already assigned to another student.");
            return;
        }

        // 3. Check uniqueness of student UID in students
        $chkUid = $pdo->prepare("SELECT student_id FROM students WHERE student_uid = :suid");
        $chkUid->execute([':suid' => $uid]);
        if ($chkUid->fetch()) {
            Response::conflict("Student UID '{$uid}' is already assigned to another student.");
            return;
        }

        // 4. Validate academic references exist
        $chkDept = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = :did");
        $chkDept->execute([':did' => $deptId]);
        if (!$chkDept->fetch()) {
            Response::validationError(['department_id' => "Selected department (ID {$deptId}) does not exist."]);
            return;
        }

        $chkClass = $pdo->prepare("SELECT class_id FROM classes WHERE class_id = :cid");
        $chkClass->execute([':cid' => $classId]);
        if (!$chkClass->fetch()) {
            Response::validationError(['class_id' => "Selected class (ID {$classId}) does not exist."]);
            return;
        }

        $chkDiv = $pdo->prepare("SELECT division_id FROM divisions WHERE division_id = :did");
        $chkDiv->execute([':did' => $divId]);
        if (!$chkDiv->fetch()) {
            Response::validationError(['division_id' => "Selected division (ID {$divId}) does not exist."]);
            return;
        }

        // Ensure sequences are synchronized before insertion if PostgreSQL
        if (Database::getActiveDriver() === 'pgsql') {
            Database::syncPgsqlSequences($pdo);
        }

        $rawPassword = !empty($input['password']) ? (string)$input['password'] : 'Student@12345';
        $pwdHash = password_hash($rawPassword, PASSWORD_BCRYPT);

        $maxAttempts = 2;
        $attempt = 0;
        $created = false;
        $lastException = null;

        while ($attempt < $maxAttempts && !$created) {
            $attempt++;
            $pdo->beginTransaction();
            try {
                $uStmt = $pdo->prepare("
                    INSERT INTO users (role_id, email, password_hash, status)
                    VALUES (3, :email, :pwd, 'ACTIVE')
                    RETURNING user_id
                ");
                $uStmt->execute([':email' => $email, ':pwd' => $pwdHash]);
                $newUserId = (int)$uStmt->fetchColumn() ?: (int)$pdo->lastInsertId();
                $uStmt->closeCursor();

                $sStmt = $pdo->prepare("
                    INSERT INTO students (user_id, roll_number, student_uid, full_name, email, phone, gender, department_id, course_id, class_id, division_id, batch, admission_year, status, face_verification_status)
                    VALUES (:uid, :roll, :suid, :name, :email, :phone, :gender, :dept, 1, :cid, :did, :batch, :yr, 'ACTIVE', 'NOT_ENROLLED')
                    RETURNING student_id
                ");
                $sStmt->execute([
                    ':uid' => $newUserId,
                    ':roll' => $roll,
                    ':suid' => $uid,
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':gender' => $gender,
                    ':dept' => $deptId,
                    ':cid' => $classId,
                    ':did' => $divId,
                    ':batch' => $batch,
                    ':yr' => $admYear
                ]);
                $newStudentId = (int)$sStmt->fetchColumn() ?: (int)$pdo->lastInsertId();
                $sStmt->closeCursor();

                $pdo->commit();
                $created = true;

                AuditService::log($admin['user_id'], 'STUDENT_CREATED', 'students', (string)$newStudentId, ['roll_number' => $roll]);

                Response::success([
                    'student_id' => $newStudentId,
                    'roll_number' => $roll,
                    'full_name' => $name,
                    'email' => $email
                ], 'Student registered successfully. Initial credentials generated.', 201);
                return;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $lastException = $e;

                // If sequence primary key collision occurred on first attempt, synchronize and retry
                $isPkeyViolation = str_contains($e->getMessage(), 'users_pkey') || str_contains($e->getMessage(), 'students_pkey');
                if ($isPkeyViolation && $attempt < $maxAttempts && Database::getActiveDriver() === 'pgsql') {
                    Database::syncPgsqlSequences($pdo);
                    continue;
                }
                break;
            }
        }

        // Handle failure if not created
        error_log("[SAMS Student Creation Error] " . ($lastException ? $lastException->getMessage() : 'Unknown error'));
        $errMsg = $lastException ? $lastException->getMessage() : '';

        // Friendly error messages for constraint conflicts without leaking raw SQLSTATE
        if (str_contains($errMsg, '23505') || str_contains($errMsg, 'Unique violation') || str_contains($errMsg, 'UNIQUE constraint failed')) {
            if (str_contains($errMsg, 'users_email_key') || str_contains($errMsg, 'users.email') || str_contains($errMsg, 'email')) {
                Response::conflict("An account with email '{$email}' already exists.");
                return;
            }
            if (str_contains($errMsg, 'students_roll_number_key') || str_contains($errMsg, 'students.roll_number') || str_contains($errMsg, 'roll_number')) {
                Response::conflict("Roll number '{$roll}' is already assigned to another student.");
                return;
            }
            if (str_contains($errMsg, 'students_student_uid_key') || str_contains($errMsg, 'students.student_uid') || str_contains($errMsg, 'student_uid')) {
                Response::conflict("Student UID '{$uid}' is already assigned to another student.");
                return;
            }
        }

        if (str_contains($errMsg, '23503') || str_contains($errMsg, 'foreign key constraint')) {
            Response::validationError(['academic_structure' => "Invalid academic assignment (department, class, or division)."]);
            return;
        }

        Response::error("Unable to register student. Please check the entered details.", 'STUDENT_CREATION_FAILED', 500);
    }

    /**
     * Update Student
     * PUT /api/students/{id}
     */
    public static function update(int $studentId): void
    {
        $admin = RoleMiddleware::adminOnly();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT student_id, user_id FROM students WHERE student_id = :sid");
        $stmt->execute([':sid' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            Response::notFound("Student #{$studentId} not found.");
        }

        $name = trim($input['full_name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $status = $input['status'] ?? 'ACTIVE';

        $upd = $pdo->prepare("
            UPDATE students 
            SET full_name = COALESCE(NULLIF(:name, ''), full_name),
                phone = COALESCE(NULLIF(:phone, ''), phone),
                status = :status
            WHERE student_id = :sid
        ");
        $upd->execute([':name' => $name, ':phone' => $phone, ':status' => $status, ':sid' => $studentId]);

        AuditService::log($admin['user_id'], 'STUDENT_UPDATED', 'students', (string)$studentId);
        Response::success(null, "Student details updated successfully.");
    }

    /**
     * Deactivate / Delete Student
     * DELETE /api/students/{id}
     */
    public static function destroy(int $studentId): void
    {
        $admin = RoleMiddleware::adminOnly();
        $pdo = Database::getConnection();

        $upd = $pdo->prepare("UPDATE students SET status = 'INACTIVE' WHERE student_id = :sid");
        $upd->execute([':sid' => $studentId]);

        AuditService::log($admin['user_id'], 'STUDENT_DEACTIVATED', 'students', (string)$studentId);
        Response::success(null, "Student status marked as INACTIVE.");
    }

    /**
     * Monthly Calendar Data for a Student
     * GET /api/students/{id}/calendar?year=2026&month=9
     */
    public static function calendar(int $studentId): void
    {
        $user = AuthMiddleware::authenticate();

        // Students can only view their own calendar
        if ($user['role_name'] === 'STUDENT' && (int)$user['student_id'] !== $studentId) {
            Response::forbidden("Access denied: You cannot view another student's attendance calendar.");
            return;
        }

        $pdo = Database::getConnection();

        // Verify student exists
        $checkStmt = $pdo->prepare("SELECT student_id, full_name FROM students WHERE student_id = :sid");
        $checkStmt->execute([':sid' => $studentId]);
        $student = $checkStmt->fetch();

        if (!$student) {
            Response::notFound("Student #{$studentId} not found.");
        }

        $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

        // Clamp values
        $year  = max(2020, min(2040, $year));
        $month = max(1, min(12, $month));

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));

        $stmt = $pdo->prepare("
            SELECT
                ses.session_date,
                r.status,
                r.verification_method,
                r.confidence_score,
                r.marked_at,
                sub.subject_name,
                sub.subject_code,
                t.full_name AS teacher_name,
                ses.start_time
            FROM attendance_records r
            JOIN attendance_sessions ses ON r.session_id = ses.session_id
            JOIN subjects sub ON ses.subject_id = sub.subject_id
            JOIN teachers t ON ses.teacher_id = t.teacher_id
            WHERE r.student_id = :sid
              AND ses.session_date BETWEEN :start AND :end
            ORDER BY ses.session_date ASC, ses.start_time ASC
        ");
        $stmt->execute([':sid' => $studentId, ':start' => $startDate, ':end' => $endDate]);
        $records = $stmt->fetchAll();

        // Group by date — a date can have multiple subjects
        $byDate = [];
        foreach ($records as $rec) {
            $date = $rec['session_date'];
            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
            }
            $byDate[$date][] = [
                'status'              => $rec['status'],
                'subject_name'        => $rec['subject_name'],
                'subject_code'        => $rec['subject_code'],
                'teacher_name'        => $rec['teacher_name'],
                'start_time'          => $rec['start_time'],
                'verification_method' => $rec['verification_method'],
                'confidence_score'    => $rec['confidence_score'] !== null ? round((float)$rec['confidence_score'] * 100) : null,
                'marked_at'           => $rec['marked_at']
            ];
        }

        Response::success([
            'student_id'  => $studentId,
            'student_name'=> $student['full_name'],
            'year'        => $year,
            'month'       => $month,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'records_by_date' => $byDate
        ], 'Monthly calendar data retrieved.');
    }
}

