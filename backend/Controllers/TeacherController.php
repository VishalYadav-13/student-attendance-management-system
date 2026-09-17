<?php
/**
 * SAMS - Teacher Management Controller
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\AuditService;
use SAMS\Utils\Response;
use SAMS\Utils\Validator;
use PDO;
use Exception;

class TeacherController
{
    /**
     * List Teachers
     * GET /api/teachers
     */
    public static function index(): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $deptId = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
        $search = trim($_GET['search'] ?? '');

        $conditions = ["1=1"];
        $params = [];

        if ($deptId !== null) {
            $conditions[] = "t.department_id = :dept";
            $params[':dept'] = $deptId;
        }
        if ($search !== '') {
            $conditions[] = "(LOWER(t.full_name) LIKE :q OR LOWER(t.employee_id) LIKE :q OR LOWER(u.email) LIKE :q)";
            $params[':q'] = '%' . strtolower($search) . '%';
        }

        $whereClause = implode(' AND ', $conditions);

        $stmt = $pdo->prepare("
            SELECT 
                t.teacher_id,
                t.user_id,
                t.employee_id,
                t.full_name,
                t.phone,
                t.designation,
                t.status,
                u.email,
                dept.department_name,
                dept.department_code,
                COUNT(DISTINCT ts.subject_id) AS assigned_subjects_count,
                COUNT(DISTINCT ts.class_id) AS assigned_classes_count
            FROM teachers t
            JOIN users u ON t.user_id = u.user_id
            JOIN departments dept ON t.department_id = dept.department_id
            LEFT JOIN teacher_subjects ts ON t.teacher_id = ts.teacher_id
            WHERE {$whereClause}
            GROUP BY t.teacher_id, t.user_id, t.employee_id, t.full_name, t.phone, t.designation, t.status, u.email, dept.department_name, dept.department_code
            ORDER BY t.full_name ASC
        ");
        $stmt->execute($params);
        $teachers = $stmt->fetchAll();

        Response::success($teachers, 'Teachers retrieved.');
    }

    /**
     * Add Teacher (Admin Only)
     * POST /api/teachers
     */
    public static function store(): void
    {
        $admin = RoleMiddleware::adminOnly();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('full_name', 'email', 'employee_id', 'department_id')
            ->email('email')
            ->numeric('department_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $email = strtolower(trim($input['email']));
        $empId = trim($input['employee_id']);
        $name = trim($input['full_name']);
        $phone = $input['phone'] ?? null;
        $deptId = (int)$input['department_id'];
        $designation = $input['designation'] ?? 'Lecturer / Assistant Professor';

        $pdo = Database::getConnection();

        // 1. Check uniqueness of email
        $chk = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email");
        $chk->execute([':email' => $email]);
        if ($chk->fetch()) {
            Response::conflict("An account with email '{$email}' already exists.");
            return;
        }

        // 2. Check uniqueness of employee ID
        $chkEmp = $pdo->prepare("SELECT teacher_id FROM teachers WHERE employee_id = :emp");
        $chkEmp->execute([':emp' => $empId]);
        if ($chkEmp->fetch()) {
            Response::conflict("Employee ID '{$empId}' is already assigned.");
            return;
        }

        // 3. Validate department exists
        $chkDept = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = :did");
        $chkDept->execute([':did' => $deptId]);
        if (!$chkDept->fetch()) {
            Response::validationError(['department_id' => "Selected department (ID {$deptId}) does not exist."]);
            return;
        }

        // Ensure sequences are synchronized before insertion if PostgreSQL
        if (Database::getActiveDriver() === 'pgsql') {
            Database::syncPgsqlSequences($pdo);
        }

        $rawPassword = !empty($input['password']) ? (string)$input['password'] : 'Teacher@12345';
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
                    VALUES (2, :email, :pwd, 'ACTIVE')
                    RETURNING user_id
                ");
                $uStmt->execute([':email' => $email, ':pwd' => $pwdHash]);
                $userId = (int)$uStmt->fetchColumn() ?: (int)$pdo->lastInsertId();
                $uStmt->closeCursor();

                $tStmt = $pdo->prepare("
                    INSERT INTO teachers (user_id, employee_id, full_name, phone, department_id, designation, status)
                    VALUES (:uid, :emp, :name, :phone, :dept, :desig, 'ACTIVE')
                    RETURNING teacher_id
                ");
                $tStmt->execute([
                    ':uid' => $userId,
                    ':emp' => $empId,
                    ':name' => $name,
                    ':phone' => $phone,
                    ':dept' => $deptId,
                    ':desig' => $designation
                ]);
                $teacherId = (int)$tStmt->fetchColumn() ?: (int)$pdo->lastInsertId();
                $tStmt->closeCursor();

                $pdo->commit();
                $created = true;

                AuditService::log($admin['user_id'], 'TEACHER_CREATED', 'teachers', (string)$teacherId);

                Response::success([
                    'teacher_id' => $teacherId,
                    'employee_id' => $empId,
                    'full_name' => $name,
                    'email' => $email
                ], 'Teacher account created successfully.', 201);
                return;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $lastException = $e;

                $isPkeyViolation = str_contains($e->getMessage(), 'users_pkey') || str_contains($e->getMessage(), 'teachers_pkey');
                if ($isPkeyViolation && $attempt < $maxAttempts && Database::getActiveDriver() === 'pgsql') {
                    Database::syncPgsqlSequences($pdo);
                    continue;
                }
                break;
            }
        }

        // Handle failure if not created
        error_log("[SAMS Teacher Creation Error] " . ($lastException ? $lastException->getMessage() : 'Unknown error'));
        $errMsg = $lastException ? $lastException->getMessage() : '';

        if (str_contains($errMsg, '23505') || str_contains($errMsg, 'Unique violation') || str_contains($errMsg, 'UNIQUE constraint failed')) {
            if (str_contains($errMsg, 'users_email_key') || str_contains($errMsg, 'users.email') || str_contains($errMsg, 'email')) {
                Response::conflict("An account with email '{$email}' already exists.");
                return;
            }
            if (str_contains($errMsg, 'teachers_employee_id_key') || str_contains($errMsg, 'teachers.employee_id') || str_contains($errMsg, 'employee_id')) {
                Response::conflict("Employee ID '{$empId}' is already assigned.");
                return;
            }
        }

        Response::error("Unable to register teacher. Please check the entered details.", 'TEACHER_CREATION_FAILED', 500);
    }
}
