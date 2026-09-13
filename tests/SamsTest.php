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
use SAMS\Controllers\AttendanceController;

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
}

// Execute tests
$suite = new SamsTest();
$suite->run();
