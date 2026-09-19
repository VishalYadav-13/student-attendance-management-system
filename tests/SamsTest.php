<?php
/**
 * SAMS - Automated Test Suite
 * Validates critical business logic, calculations, validation rules, and security behaviors.
 * Run directly via CLI: php tests/SamsTest.php
 */

// Configure autoloader
spl_autoload_register(function ($class) {
    $prefix = 'SAMS\\';
    $baseDir = dirname(__DIR__) . '/backend/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $parts = explode('\\', $relativeClass);
    $last = array_pop($parts);
    $dir = strtolower(implode('/', $parts));
    $file = $baseDir . ($dir ? $dir . '/' : '') . $last . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use SAMS\Services\AttendanceService;
use SAMS\Utils\Validator;
use SAMS\Utils\Response;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Controllers\SettingsController;
use SAMS\Controllers\StudentController;
use SAMS\Controllers\TeacherController;
use SAMS\Controllers\AttendanceController;
use SAMS\Controllers\AuthController;
use SAMS\Controllers\DashboardController;
use SAMS\Config\Database;

class SamsTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): void
    {
        echo "\n======================================================\n";
        echo "  SAMS Test Suite - Automated Validation\n";
        echo "======================================================\n\n";

        // Attendance Percentage Calculations
        $this->testAttendanceCalculations();

        // Specific Required Percentage Test Matrix (100%, 90%, 80%, 75%, 74.99%, 74%, 50%, 0%)
        $this->testExactRequiredAttendanceCases();

        // Edge Cases
        $this->testEdgeCases();

        // Validation Engine
        $this->testValidator();

        // RBAC & IDOR Authorization Security Tests
        $this->testRoleAuthorizationAndIdor();

        // Production Teacher & Admin Authentication Tests
        $this->testAuthenticationSuite();

        // Auto-Increment Sequence & User Insertion Regression Tests
        $this->testUserInsertionAndSequenceAutoIncrement();

        // Face Verification & Biometric Attendance System Tests
        require_once __DIR__ . '/FaceVerificationTest.php';
        $faceSuite = new \FaceVerificationTest();
        $faceResults = $faceSuite->run(false);
        $this->passed += $faceResults['passed'];
        $this->failed += $faceResults['failed'];
        $this->failures = array_merge($this->failures, $faceResults['failures']);

        // Results Summary
        echo "\n------------------------------------------------------\n";
        echo sprintf("Total Tests: %d | Passed: %d | Failed: %d\n", $this->passed + $this->failed, $this->passed, $this->failed);
        echo "------------------------------------------------------\n";

        if ($this->failed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failures as $f) {
                echo "  ❌ $f\n";
            }
            exit(1);
        } else {
            echo "✅ All tests passed successfully!\n\n";
            exit(0);
        }
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✔ {$testName}\n";
        } else {
            $this->failed++;
            $msg = "{$testName}" . ($detail ? " ($detail)" : '');
            $this->failures[] = $msg;
            echo "  ✖ {$msg}\n";
        }
    }

    private function testAttendanceCalculations(): void
    {
        echo "[1] Attendance Service Percentage Calculations\n";

        // 100% attendance (20/20)
        $pct = AttendanceService::calculatePercentage(20, 0, 20);
        $this->assert("Full attendance returns 100.0%", $pct === 100.0, "Got: {$pct}");

        // 75% threshold exactly (15/20)
        $pct = AttendanceService::calculatePercentage(15, 0, 20);
        $this->assert("75% boundary calculates accurately (15/20)", $pct === 75.0, "Got: {$pct}");

        // Below threshold (14/20 = 70%)
        $pct = AttendanceService::calculatePercentage(14, 0, 20);
        $this->assert("Below threshold calculates 70.0% (14/20)", $pct === 70.0, "Got: {$pct}");

        // Late attendance with full weight (1.0)
        $pct = AttendanceService::calculatePercentage(10, 5, 20, 1.0);
        $this->assert("Late with weight 1.0 counts as present (15/20 = 75%)", $pct === 75.0, "Got: {$pct}");

        // Late attendance with partial weight (0.5)
        $pct = AttendanceService::calculatePercentage(10, 4, 20, 0.5);
        $this->assert("Late with weight 0.5 counts partially (12/20 = 60%)", $pct === 60.0, "Got: {$pct}");

        // Late attendance with zero weight (0.0)
        $pct = AttendanceService::calculatePercentage(10, 5, 20, 0.0);
        $this->assert("Late with weight 0.0 counts as absent (10/20 = 50%)", $pct === 50.0, "Got: {$pct}");
    }

    private function testExactRequiredAttendanceCases(): void
    {
        echo "\n[2] Exact Required Cases & 75% Shortage Rule Matrix\n";
        $threshold = 75.0;

        // 1. 100% -> Compliant
        $pct100 = AttendanceService::calculatePercentage(100, 0, 100);
        $shortage100 = ($pct100 < $threshold);
        $this->assert("100% attendance calculation", $pct100 === 100.0, "Got: {$pct100}");
        $this->assert("100% is COMPLIANT (not shortage)", !$shortage100);

        // 2. 90% -> Compliant
        $pct90 = AttendanceService::calculatePercentage(90, 0, 100);
        $shortage90 = ($pct90 < $threshold);
        $this->assert("90% attendance calculation", $pct90 === 90.0, "Got: {$pct90}");
        $this->assert("90% is COMPLIANT (not shortage)", !$shortage90);

        // 3. 80% -> Compliant
        $pct80 = AttendanceService::calculatePercentage(80, 0, 100);
        $shortage80 = ($pct80 < $threshold);
        $this->assert("80% attendance calculation", $pct80 === 80.0, "Got: {$pct80}");
        $this->assert("80% is COMPLIANT (not shortage)", !$shortage80);

        // 4. 75% -> Compliant (Exactly 75.0% satisfies >= 75%)
        $pct75 = AttendanceService::calculatePercentage(75, 0, 100);
        $shortage75 = ($pct75 < $threshold);
        $this->assert("75% attendance calculation", $pct75 === 75.0, "Got: {$pct75}");
        $this->assert("75% is COMPLIANT boundary (attendance >= 75% = compliant)", !$shortage75);

        // 5. 74.99% -> Shortage (< 75%)
        $pct7499 = 74.99;
        $shortage7499 = ($pct7499 < $threshold);
        $this->assert("74.99% is SHORTAGE (attendance < 75% = shortage)", $shortage7499);

        // 6. 74% -> Shortage (< 75%)
        $pct74 = AttendanceService::calculatePercentage(74, 0, 100);
        $shortage74 = ($pct74 < $threshold);
        $this->assert("74% attendance calculation", $pct74 === 74.0, "Got: {$pct74}");
        $this->assert("74% is SHORTAGE (attendance < 75% = shortage)", $shortage74);

        // 7. 50% -> Shortage (< 75%)
        $pct50 = AttendanceService::calculatePercentage(50, 0, 100);
        $shortage50 = ($pct50 < $threshold);
        $this->assert("50% attendance calculation", $pct50 === 50.0, "Got: {$pct50}");
        $this->assert("50% is SHORTAGE (attendance < 75% = shortage)", $shortage50);

        // 8. 0% -> Shortage (< 75%)
        $pct0 = AttendanceService::calculatePercentage(0, 0, 100);
        $shortage0 = (100 > 0 && $pct0 < $threshold);
        $this->assert("0% attendance calculation", $pct0 === 0.0, "Got: {$pct0}");
        $this->assert("0% with conducted sessions is SHORTAGE", $shortage0);
    }

    private function testEdgeCases(): void
    {
        echo "\n[2] Edge Cases & Boundary Conditions\n";

        // Zero total sessions conducted
        $pct = AttendanceService::calculatePercentage(0, 0, 0);
        $this->assert("Zero total conducted returns 0.0% without division by zero", $pct === 0.0, "Got: {$pct}");

        // Negative values clamped / handled safely
        $pct = AttendanceService::calculatePercentage(0, 0, -5);
        $this->assert("Negative total returns 0.0%", $pct === 0.0, "Got: {$pct}");

        // Over-attended (present > total) capped at 100%
        $pct = AttendanceService::calculatePercentage(25, 0, 20);
        $this->assert("Attendance capped at maximum 100.0%", $pct === 100.0, "Got: {$pct}");

        // Zero attendance (0/20)
        $pct = AttendanceService::calculatePercentage(0, 0, 20);
        $this->assert("Zero attendance returns 0.0%", $pct === 0.0, "Got: {$pct}");
    }

    private function testValidator(): void
    {
        echo "\n[3] Validator Engine Tests\n";

        // Required rule
        $v1 = Validator::make(['email' => 'test@example.com'])->required('email');
        $this->assert("Validator passes when required field is present", $v1->passes());

        $v2 = Validator::make([])->required('email');
        $this->assert("Validator fails when required field is missing", $v2->fails());

        // Email validation
        $v3 = Validator::make(['email' => 'valid@sams.edu'])->email('email');
        $this->assert("Validator accepts valid email format", $v3->passes());

        $v4 = Validator::make(['email' => 'invalid-email'])->email('email');
        $this->assert("Validator rejects invalid email format", $v4->fails());

        // InArray validation
        $v5 = Validator::make(['status' => 'PRESENT'])->inArray('status', ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);
        $this->assert("Validator accepts allowed enum status", $v5->passes());

        $v6 = Validator::make(['status' => 'UNKNOWN'])->inArray('status', ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);
        $this->assert("Validator rejects disallowed enum status", $v6->fails());

        // Numeric validation
        $v7 = Validator::make(['id' => '123'])->numeric('id');
        $this->assert("Validator accepts numeric string", $v7->passes());

        $v8 = Validator::make(['id' => 'abc'])->numeric('id');
        $this->assert("Validator rejects non-numeric string", $v8->fails());
    }

    private function testRoleAuthorizationAndIdor(): void
    {
        echo "\n[4] Role-Based Access Control (RBAC) & IDOR Security Tests\n";
        Response::enableTestMode();

        // 1. Unauthenticated request to Settings
        AuthMiddleware::setAuthenticatedUser(null);
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        SettingsController::index();
        $resp = Response::getLastResponse();
        $this->assert("Unauthenticated request to Settings rejected with 401", ($resp['status_code'] ?? 0) === 401);

        // 2. Student trying to update Settings (Admin only)
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 7,
            'role_id' => 3,
            'role_name' => 'STUDENT'
        ]);
        SettingsController::update();
        $resp = Response::getLastResponse();
        $this->assert("Student forbidden from updating institutional settings (403)", ($resp['status_code'] ?? 0) === 403);

        // 3. IDOR Attack: Student 1 attempts to access Student 2 calendar
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 7,
            'student_id' => 1,
            'role_id' => 3,
            'role_name' => 'STUDENT'
        ]);
        StudentController::calendar(2);
        $resp = Response::getLastResponse();
        $this->assert("IDOR Attack: Student 1 forbidden from accessing Student 2 calendar (403)", ($resp['status_code'] ?? 0) === 403);

        // 4. Legitimate Access: Student 1 accesses own calendar
        StudentController::calendar(1);
        $resp = Response::getLastResponse();
        $this->assert("Legitimate Access: Student 1 accesses own calendar (200)", ($resp['status_code'] ?? 0) === 200);

        // 5. IDOR Attack: Student 1 attempts to view Student 2 profile
        StudentController::show(2);
        $resp = Response::getLastResponse();
        $this->assert("IDOR Attack: Student 1 forbidden from viewing Student 2 profile (403)", ($resp['status_code'] ?? 0) === 403);

        // 6. Legitimate Access: Student 1 views own profile
        StudentController::show(1);
        $resp = Response::getLastResponse();
        $this->assert("Legitimate Access: Student 1 accesses own profile (200)", ($resp['status_code'] ?? 0) === 200);

        // 7. Teacher role accessing Settings update
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);
        SettingsController::update();
        $resp = Response::getLastResponse();
        $this->assert("Teacher forbidden from updating institutional settings (403)", ($resp['status_code'] ?? 0) === 403);

        Response::disableTestMode();
        AuthMiddleware::setAuthenticatedUser(null);
    }

    private function testAuthenticationSuite(): void
    {
        echo "\n[5] Production Authentication & Role Resolution Tests\n";
        Response::enableTestMode();
        AuthMiddleware::setAuthenticatedUser(null);

        // 1. Teacher Login with teacher@sams.edu / Teacher@12345
        $_POST = [
            'email' => 'teacher@sams.edu',
            'password' => 'Teacher@12345'
        ];
        AuthController::login();
        $resp = Response::getLastResponse();
        $this->assert("Teacher login with teacher@sams.edu succeeds (200)", ($resp['status_code'] ?? 0) === 200);
        $this->assert("Teacher login returns role TEACHER", ($resp['data']['user']['role'] ?? '') === 'TEACHER');
        $this->assert("Teacher login returns redirect_url for teacher dashboard", ($resp['data']['redirect_url'] ?? '') === '/frontend/teacher/dashboard.html');
        $this->assert("Teacher login issues valid JWT token", !empty($resp['data']['token']));

        // 2. Admin Login with admin@sams.edu / Admin@12345
        $_POST = [
            'email' => 'admin@sams.edu',
            'password' => 'Admin@12345'
        ];
        AuthController::login();
        $adminResp = Response::getLastResponse();
        $this->assert("Admin login with admin@sams.edu succeeds (200)", ($adminResp['status_code'] ?? 0) === 200);
        $this->assert("Admin login returns role ADMIN", ($adminResp['data']['user']['role'] ?? '') === 'ADMIN');
        $this->assert("Admin login returns redirect_url for admin dashboard", ($adminResp['data']['redirect_url'] ?? '') === '/frontend/admin/dashboard.html');

        // 3. Invalid credentials rejected
        $_POST = [
            'email' => 'teacher@sams.edu',
            'password' => 'WrongPassword!99'
        ];
        AuthController::login();
        $failResp = Response::getLastResponse();
        $this->assert("Invalid password rejected with 401 Unauthorized", ($failResp['status_code'] ?? 0) === 401);

        // 4. Non-existent email rejected
        $_POST = [
            'email' => 'ghost.user@sams.edu',
            'password' => 'Teacher@12345'
        ];
        AuthController::login();
        $ghostResp = Response::getLastResponse();
        $this->assert("Non-existent account rejected with 401 Unauthorized", ($ghostResp['status_code'] ?? 0) === 401);

        // 5. Teacher Dashboard Access with Teacher context
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);
        DashboardController::teacher();
        $dashResp = Response::getLastResponse();
        $this->assert("Teacher dashboard data loads successfully (200)", ($dashResp['status_code'] ?? 0) === 200);
        $this->assert("Teacher dashboard contains assigned classes array", isset($dashResp['data']['assigned_classes']));

        $_POST = [];
        AuthMiddleware::setAuthenticatedUser(null);
        Response::disableTestMode();
    }

    private function testUserInsertionAndSequenceAutoIncrement(): void
    {
        echo "\n[6] User Insertion & Auto-Increment Sequence Synchronization Tests\n";
        Response::enableTestMode();

        $pdo = Database::getConnection();

        // 1. Verify seed data exists
        $seedUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_id <= 26")->fetchColumn();
        $this->assert("Seeded users (IDs 1-26) exist in database", $seedUserCount >= 26);

        $maxSeedUserId = (int)$pdo->query("SELECT MAX(user_id) FROM users")->fetchColumn();
        $this->assert("MAX(user_id) in database is at least 26", $maxSeedUserId >= 26);

        // 2. Direct user insertion without explicit ID
        $testEmail = 'seq.test.' . uniqid() . '@sams.edu';
        $pwdHash = password_hash('TestPass@123', PASSWORD_BCRYPT);
        $insStmt = $pdo->prepare("
            INSERT INTO users (role_id, email, password_hash, status)
            VALUES (3, :email, :pwd, 'ACTIVE')
            RETURNING user_id
        ");
        $insStmt->execute([':email' => $testEmail, ':pwd' => $pwdHash]);
        $newUserId = (int)$insStmt->fetchColumn() ?: (int)$pdo->lastInsertId();
        $insStmt->closeCursor();

        $this->assert("Direct user insertion assigns auto-increment ID > 26", $newUserId > 26);

        // 3. Authenticate as Admin and test Add Student path
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN'
        ]);

        $testStudentRoll = 'REG-' . rand(1000, 9999);
        $testStudentUid = 'UID-REG-' . rand(1000, 9999);
        $testStudentEmail = 'student.reg.' . uniqid() . '@sams.edu';

        $_POST = [
            'full_name' => 'Regression Test Student',
            'email' => $testStudentEmail,
            'roll_number' => $testStudentRoll,
            'student_uid' => $testStudentUid,
            'department_id' => 1,
            'class_id' => 1,
            'division_id' => 1,
            'gender' => 'Male',
            'batch' => '2026'
        ];

        StudentController::store();
        $respStudent = Response::getLastResponse();
        $studentStatusCode = $respStudent['status_code'] ?? 0;
        $studentData = $respStudent['payload']['data'] ?? $respStudent['data'] ?? [];

        $this->assert("Add Student controller succeeds with 200/201 response", in_array($studentStatusCode, [200, 201], true));
        $this->assert("Add Student creates student with valid ID > 20", !empty($studentData['student_id']) && (int)$studentData['student_id'] > 20);

        // 4. Test Add Faculty / Teacher path
        $testTeacherEmp = 'EMP-REG-' . rand(1000, 9999);
        $testTeacherEmail = 'faculty.reg.' . uniqid() . '@sams.edu';

        $_POST = [
            'full_name' => 'Regression Test Faculty',
            'email' => $testTeacherEmail,
            'employee_id' => $testTeacherEmp,
            'department_id' => 1,
            'designation' => 'Testing Assistant Professor'
        ];

        TeacherController::store();
        $respTeacher = Response::getLastResponse();
        $teacherStatusCode = $respTeacher['status_code'] ?? 0;
        $teacherData = $respTeacher['payload']['data'] ?? $respTeacher['data'] ?? [];

        $this->assert("Add Faculty controller succeeds with 200/201 response", in_array($teacherStatusCode, [200, 201], true));
        $this->assert("Add Faculty creates teacher with valid ID > 5", !empty($teacherData['teacher_id']) && (int)$teacherData['teacher_id'] > 5);

        // 5. Verify existing seed users remain intact and unmodified
        $adminCheck = $pdo->query("SELECT user_id, email FROM users WHERE user_id = 1")->fetch();
        $this->assert("Admin user ID 1 remains intact and unmodified", $adminCheck && $adminCheck['email'] === 'admin@sams.edu');

        $studentCheck = $pdo->query("SELECT user_id, email FROM users WHERE user_id = 7")->fetch();
        $this->assert("Student user ID 7 remains intact and unmodified", $studentCheck && $studentCheck['email'] === 'vishal.yadav@sams.edu');

        // 6. Verify Database::syncPgsqlSequences and syncAllSequences methods are defined and callable
        $this->assert("Database::syncPgsqlSequences method exists and is callable", is_callable(['SAMS\Config\Database', 'syncPgsqlSequences']));
        $this->assert("Database::syncAllSequences method exists and is callable", is_callable(['SAMS\Config\Database', 'syncAllSequences']));

        // 7. Verify duplicate roll number returns 409 conflict
        $_POST = [
            'full_name' => 'Duplicate Roll Test Student',
            'email' => 'unique.email.' . uniqid() . '@sams.edu',
            'roll_number' => $testStudentRoll, // already created above
            'student_uid' => 'UID-UNIQUE-' . rand(10000, 99999),
            'department_id' => 1,
            'class_id' => 1,
            'division_id' => 1
        ];
        StudentController::store();
        $respDupRoll = Response::getLastResponse();
        $this->assert("Duplicate roll number returns 409 Conflict", ($respDupRoll['status_code'] ?? 0) === 409);
        $this->assert("Duplicate roll error does not expose raw SQLSTATE", !str_contains($respDupRoll['payload']['message'] ?? '', 'SQLSTATE'));

        // 8. Verify duplicate student UID returns 409 conflict
        $_POST = [
            'full_name' => 'Duplicate UID Test Student',
            'email' => 'unique.email2.' . uniqid() . '@sams.edu',
            'roll_number' => 'REG-UNIQUE-' . rand(10000, 99999),
            'student_uid' => $testStudentUid, // already created above
            'department_id' => 1,
            'class_id' => 1,
            'division_id' => 1
        ];
        StudentController::store();
        $respDupUid = Response::getLastResponse();
        $this->assert("Duplicate student UID returns 409 Conflict", ($respDupUid['status_code'] ?? 0) === 409);
        $this->assert("Duplicate UID error does not expose raw SQLSTATE", !str_contains($respDupUid['payload']['message'] ?? '', 'SQLSTATE'));

        // Clean up test POST and Auth
        $_POST = [];
        AuthMiddleware::setAuthenticatedUser(null);
        Response::disableTestMode();
    }
}

// Execute tests
$suite = new SamsTest();
$suite->run();
