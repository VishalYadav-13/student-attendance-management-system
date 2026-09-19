<?php
/**
 * SAMS - Face Verification & Biometric System Test Suite
 * Automated unit and integration tests for Face Enrollment, Real-time Verification,
 * Anti-spoofing Liveness, Duplicate Prevention, Class Restrictions, Security, and Database Constraints.
 * Run directly via CLI: php tests/FaceVerificationTest.php
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

use SAMS\Config\Database;
use SAMS\Controllers\FaceController;
use SAMS\Controllers\AttendanceController;
use SAMS\Services\FaceVerificationService;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Utils\Response;

class FaceVerificationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private PDO $pdo;

    public function run(bool $exitOnFinish = true): array
    {
        echo "\n======================================================\n";
        echo "  SAMS - Face Verification & Biometrics Test Suite\n";
        echo "======================================================\n\n";

        $this->pdo = Database::getConnection();
        Response::enableTestMode();

        // 1. Enrollment Tests
        $this->testEnrollmentSuite();

        // 2. Euclidean Distance Calculation Engine
        $this->testEuclideanDistanceEngine();

        // 3. Active Verification & Attendance Tests
        $this->testVerificationSuite();

        // 4. Class Restriction & Duplicate Attendance Protection
        $this->testClassRestrictionsAndDuplicates();

        // 5. Anti-Spoofing & Liveness Tests
        $this->testLivenessDetection();

        // 6. Security, RBAC & Privacy Tests
        $this->testSecurityAndPrivacy();

        // 7. Database Integrity & Constraints
        $this->testDatabaseConstraints();

        Response::disableTestMode();
        AuthMiddleware::setAuthenticatedUser(null);

        // Summary
        echo "\n------------------------------------------------------\n";
        echo sprintf("Total Tests: %d | Passed: %d | Failed: %d\n", $this->passed + $this->failed, $this->passed, $this->failed);
        echo "------------------------------------------------------\n";

        if ($exitOnFinish) {
            if ($this->failed > 0) {
                echo "\nFailures:\n";
                foreach ($this->failures as $f) {
                    echo "  ❌ $f\n";
                }
                exit(1);
            } else {
                echo "✅ All Face Verification tests passed successfully!\n\n";
                exit(0);
            }
        }

        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'failures' => $this->failures
        ];
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

    /**
     * Generate synthetic normalized 128-dimensional embedding vector
     */
    private function generateSyntheticVector(float $seed = 0.5): array
    {
        $vec = [];
        for ($i = 0; $i < 128; $i++) {
            $vec[] = sin($seed + ($i * 0.1)) * 0.1;
        }
        // L2 normalize
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $vec)));
        return array_map(fn($x) => $x / $norm, $vec);
    }

    private function testEnrollmentSuite(): void
    {
        echo "\n[1] Face Biometric Enrollment Tests\n";

        // Admin user setup
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN'
        ]);

        $testVector = $this->generateSyntheticVector(1.23);

        // 1. Valid enrollment for Student 1
        $_POST = [
            'student_id' => 1,
            'embedding' => $testVector,
            'consent' => true,
            'quality_score' => 0.96
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();

        $this->assert("Valid face enrollment succeeds (200)", ($resp['status_code'] ?? 0) === 200);
        $this->assert("Enrollment response confirms ENROLLED status", ($resp['data']['status'] ?? '') === 'ENROLLED');

        // Verify database state
        $stmt = $this->pdo->prepare("SELECT status FROM student_face_templates WHERE student_id = 1");
        $stmt->execute();
        $this->assert("Template record created in student_face_templates table", $stmt->fetchColumn() === 'ACTIVE');

        $stuStmt = $this->pdo->prepare("SELECT face_verification_status FROM students WHERE student_id = 1");
        $stuStmt->execute();
        $this->assert("Student status updated to ENROLLED", $stuStmt->fetchColumn() === 'ENROLLED');

        // 2. Missing student returns error
        $_POST = [
            'student_id' => 99999,
            'embedding' => $testVector,
            'consent' => true
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Enrollment for non-existent student rejected (422)", ($resp['status_code'] ?? 0) === 422);

        // 3. Unauthorized enrollment (Student 1 attempts to enroll for Student 2)
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 7,
            'student_id' => 1,
            'role_id' => 3,
            'role_name' => 'STUDENT'
        ]);
        $_POST = [
            'student_id' => 2,
            'embedding' => $testVector,
            'consent' => true
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Student forbidden from enrolling another student's face (403)", ($resp['status_code'] ?? 0) === 403);

        // 4. Duplicate / Re-enrollment idempotency (Admin updates student 1 template)
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN'
        ]);
        $newVector = $this->generateSyntheticVector(2.45);
        $_POST = [
            'student_id' => 1,
            'embedding' => $newVector,
            'consent' => true,
            'quality_score' => 0.98
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Re-enrollment / update succeeds idempotently (200)", ($resp['status_code'] ?? 0) === 200);

        // 5. Biometric template removal (Right to Erasure)
        FaceController::deleteBiometrics(1);
        $resp = Response::getLastResponse();
        $this->assert("Biometric removal succeeds (200)", ($resp['status_code'] ?? 0) === 200);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM student_face_templates WHERE student_id = 1");
        $stmt->execute();
        $this->assert("Template removed from student_face_templates", (int)$stmt->fetchColumn() === 0);

        $stuStmt = $this->pdo->prepare("SELECT face_verification_status FROM students WHERE student_id = 1");
        $stuStmt->execute();
        $this->assert("Student status reset to NOT_ENROLLED", $stuStmt->fetchColumn() === 'NOT_ENROLLED');

        // 6. Regression Test: 3 face samples averaged with camera frame payload
        $s1 = $this->generateSyntheticVector(1.1);
        $s2 = $this->generateSyntheticVector(1.12);
        $s3 = $this->generateSyntheticVector(1.08);
        $averaged = [];
        for ($i = 0; $i < 128; $i++) {
            $averaged[] = ($s1[$i] + $s2[$i] + $s3[$i]) / 3.0;
        }
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $averaged)));
        $averagedNorm = array_map(fn($x) => $x / $norm, $averaged);

        $_POST = [
            'student_id' => 1,
            'embedding' => $averagedNorm,
            'image' => 'data:image/jpeg;base64,' . base64_encode(str_repeat('A', 4000)),
            'consent' => true,
            'quality_score' => 0.95,
            'model_version' => 'face-api-v1-128d'
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("3-sample averaged embedding with dual frame payload succeeds (200)", ($resp['status_code'] ?? 0) === 200);

        // 7. Regression Test: Missing consent returns clear error message
        $_POST = [
            'student_id' => 1,
            'embedding' => $averagedNorm,
            'consent' => false
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Missing consent rejected with 422", ($resp['status_code'] ?? 0) === 422);
        $this->assert("Missing consent has clear descriptive message", str_contains($resp['message'] ?? '', 'consent'));

        // 8. Regression Test: Missing embedding & image returns clear message
        $_POST = [
            'student_id' => 1,
            'consent' => true
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Missing face template rejected with 422", ($resp['status_code'] ?? 0) === 422);
        $this->assert("Missing face template message is informative", str_contains($resp['message'] ?? '', 'missing required face template'));

        // 9. Regression Test: Corrupted embedding (wrong dimensions or NaN) rejected
        $_POST = [
            'student_id' => 1,
            'embedding' => [0.1, 0.2, 0.3], // only 3 dimensions instead of 128
            'consent' => true
        ];
        FaceController::enroll();
        $resp = Response::getLastResponse();
        $this->assert("Invalid dimension embedding rejected with 422", ($resp['status_code'] ?? 0) === 422);
        $this->assert("Invalid dimension error message identifies 128 dimensions", str_contains($resp['message'] ?? '', '128-dimensional'));
    }

    private function testEuclideanDistanceEngine(): void
    {
        echo "\n[2] Biometric Euclidean Distance Matcher Engine Tests\n";

        $v1 = $this->generateSyntheticVector(1.0);
        $v2 = $this->generateSyntheticVector(1.0); // Identical
        $distSame = FaceVerificationService::euclideanDistance($v1, $v2);
        $this->assert("Identical vectors have Euclidean distance 0.0", abs($distSame) < 0.0001);

        $v3 = $this->generateSyntheticVector(1.02); // Very close match
        $distClose = FaceVerificationService::euclideanDistance($v1, $v3);
        $this->assert("Near-identical vector has low distance (< 0.20)", $distClose < 0.20, "Distance: {$distClose}");

        $v4 = $this->generateSyntheticVector(4.50); // Completely different face
        $distDiff = FaceVerificationService::euclideanDistance($v1, $v4);
        $this->assert("Different face has significant distance (> 0.60)", $distDiff > 0.60, "Distance: {$distDiff}");
    }

    private function testVerificationSuite(): void
    {
        echo "\n[3] Face Verification & Real-time Attendance Marking Tests\n";

        // Setup: Authenticate Teacher 1
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);

        // Enroll Student 1 in SY CO Div A
        $student1Vector = $this->generateSyntheticVector(3.1415);
        FaceVerificationService::enroll(1, $student1Vector, 1, true, 0.95);

        // Open an attendance session for SY CO (class 1, div 1, subject 1)
        $_POST = [
            'class_id' => 1,
            'division_id' => 1,
            'subject_id' => 1,
            'session_date' => date('Y-m-d'),
            'start_time' => '10:00',
            'lecture_number' => 1
        ];
        FaceController::startSession();
        $sessResp = Response::getLastResponse();
        $this->assert("Face attendance session starts successfully", in_array(($sessResp['status_code'] ?? 0), [200, 201], true));
        $sessionId = (int)($sessResp['data']['session']['session_id'] ?? 1);

        // Ensure student 1 is not yet marked in this session
        $del = $this->pdo->prepare("DELETE FROM attendance_records WHERE session_id = :sid AND student_id = 1");
        $del->execute([':sid' => $sessionId]);

        // 1. Verify matching face probe (distance ~ 0.05)
        $probeVector = $this->generateSyntheticVector(3.143);
        $_POST = [
            'session_id' => $sessionId,
            'embedding' => $probeVector,
            'liveness_passed' => true,
            'liveness_action' => 'head_turn'
        ];
        FaceController::verify();
        $resp = Response::getLastResponse();

        $this->assert("Valid face verification succeeds (200)", ($resp['status_code'] ?? 0) === 200);
        $this->assert("Response identifies verified = true", ($resp['data']['verified'] ?? false) === true);
        $this->assert("Identifies correct student_id #1", (int)($resp['data']['student']['student_id'] ?? 0) === 1);
        $this->assert("Attendance marked as PRESENT", ($resp['data']['attendance_status'] ?? '') === 'PRESENT');

        // Check database record
        $chk = $this->pdo->prepare("SELECT status, verification_method FROM attendance_records WHERE session_id = :sid AND student_id = 1");
        $chk->execute([':sid' => $sessionId]);
        $rec = $chk->fetch();
        $this->assert("Attendance record saved in database with FACE_AI method", ($rec['status'] ?? '') === 'PRESENT' && ($rec['verification_method'] ?? '') === 'FACE_AI');

        // 2. Failed verification with unknown face (distance > 0.60)
        $unknownVector = $this->generateSyntheticVector(9.99);
        $_POST = [
            'session_id' => $sessionId,
            'embedding' => $unknownVector,
            'liveness_passed' => true
        ];
        FaceController::verify();
        $failResp = Response::getLastResponse();
        $this->assert("Unknown face verification rejected (422)", ($failResp['status_code'] ?? 0) === 422);
        $this->assert("Returns clean 'Face could not be verified' message without revealing student info", ($failResp['data']['message'] ?? '') === 'Face could not be verified.');
    }

    private function testClassRestrictionsAndDuplicates(): void
    {
        echo "\n[4] Class Restriction & Duplicate Attendance Protection Tests\n";

        // Teacher 1 opens session for Class 1, Division 2 (Div B)
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);

        $_POST = [
            'class_id' => 1,
            'division_id' => 2,
            'subject_id' => 2,
            'session_date' => date('Y-m-d'),
            'start_time' => '11:00',
            'lecture_number' => 2
        ];
        FaceController::startSession();
        $sessResp = Response::getLastResponse();
        $sessionId = (int)($sessResp['data']['session']['session_id'] ?? 2);

        // Enroll Student 1 who belongs to Division 1 (Div A)
        $student1Vector = $this->generateSyntheticVector(3.1415);
        FaceVerificationService::enroll(1, $student1Vector, 1, true);

        // Verify Student 1 probe in Division B session
        $_POST = [
            'session_id' => $sessionId,
            'embedding' => $student1Vector,
            'liveness_passed' => true
        ];
        FaceController::verify();
        $resp = Response::getLastResponse();

        // Student 1 does not belong to Div B session; query filters by Div B so no match or wrong class
        $this->assert("Student from Div A blocked from marking attendance in Div B session", ($resp['status_code'] ?? 0) === 422);

        // Now test Duplicate Attendance Protection in Session 1 (where Student 1 is already PRESENT)
        // Find session for Div A
        $stmt = $this->pdo->query("SELECT session_id FROM attendance_sessions WHERE class_id = 1 AND division_id = 1 AND status = 'OPEN' ORDER BY session_id DESC LIMIT 1");
        $divASessionId = (int)$stmt->fetchColumn();

        if ($divASessionId) {
            $_POST = [
                'session_id' => $divASessionId,
                'embedding' => $student1Vector,
                'liveness_passed' => true
            ];
            FaceController::verify();
            $dupResp = Response::getLastResponse();

            $this->assert("Duplicate attendance cycle recognized cleanly (200)", ($dupResp['status_code'] ?? 0) === 200);
            $this->assert("Response flags already_marked = true", ($dupResp['data']['already_marked'] ?? false) === true);
            $this->assert("Returns clean 'Attendance already recorded' message", str_contains($dupResp['data']['message'] ?? '', 'Attendance already recorded'));

            // Verify count of records in DB remains strictly 1
            $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = :sid AND student_id = 1");
            $cntStmt->execute([':sid' => $divASessionId]);
            $this->assert("Strictly one attendance record exists (no duplicate inserted)", (int)$cntStmt->fetchColumn() === 1);
        }
    }

    private function testLivenessDetection(): void
    {
        echo "\n[5] Anti-Spoofing & Liveness Verification Tests\n";

        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);

        $stmt = $this->pdo->query("SELECT session_id FROM attendance_sessions WHERE status = 'OPEN' LIMIT 1");
        $sessionId = (int)$stmt->fetchColumn();

        $vector = $this->generateSyntheticVector(3.1415);

        // Liveness failed / unconfirmed
        $_POST = [
            'session_id' => $sessionId,
            'embedding' => $vector,
            'liveness_passed' => false,
            'liveness_action' => 'none'
        ];
        FaceController::verify();
        $resp = Response::getLastResponse();

        $this->assert("Liveness failure rejected with 422", ($resp['status_code'] ?? 0) === 422);
        $this->assert("Returns LIVENESS_FAILED code", ($resp['data']['result_code'] ?? '') === 'LIVENESS_FAILED');
        $this->assert("Does not mark attendance on liveness failure", ($resp['data']['verified'] ?? true) === false);
    }

    private function testSecurityAndPrivacy(): void
    {
        echo "\n[6] Security, RBAC & Privacy Compliance Tests\n";

        // 1. Unauthenticated request rejected
        AuthMiddleware::setAuthenticatedUser(null);
        $_POST = ['session_id' => 1, 'embedding' => $this->generateSyntheticVector(1.0)];
        FaceController::verify();
        $resp = Response::getLastResponse();
        $this->assert("Unauthenticated verification rejected (401)", ($resp['status_code'] ?? 0) === 401);

        // 2. Student cannot access other student's biometric status
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 7,
            'student_id' => 1,
            'role_id' => 3,
            'role_name' => 'STUDENT'
        ]);
        FaceController::status(2);
        $resp = Response::getLastResponse();
        $this->assert("Student forbidden from checking another student's biometric status (403)", ($resp['status_code'] ?? 0) === 403);

        // 3. Teacher cannot arbitrarily delete student biometrics
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 2,
            'teacher_id' => 1,
            'role_id' => 2,
            'role_name' => 'TEACHER'
        ]);
        FaceController::deleteBiometrics(1);
        $resp = Response::getLastResponse();
        $this->assert("Teacher forbidden from deleting biometric profiles (403)", ($resp['status_code'] ?? 0) === 403);

        // 4. Raw embeddings are NEVER returned in API responses
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN'
        ]);

        FaceController::status(1);
        $statusResp = Response::getLastResponse();
        $hasRawEmbedding = isset($statusResp['data']['embedding']) || isset($statusResp['data']['descriptor']);
        $this->assert("Biometric status API does not expose raw embedding", !$hasRawEmbedding);

        FaceController::getSessionResults(1);
        $resResp = Response::getLastResponse();
        $records = $resResp['data']['results'] ?? [];
        $leak = false;
        foreach ($records as $r) {
            if (isset($r['embedding']) || isset($r['feature_vector'])) {
                $leak = true;
            }
        }
        $this->assert("Session results API does not expose student biometric vectors", !$leak);
    }

    private function testDatabaseConstraints(): void
    {
        echo "\n[7] Database Integrity & Constraint Verification\n";

        // 1. Verify student_face_templates table exists
        $driver = Database::getActiveDriver();
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'student_face_templates'");
        } else {
            $stmt = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='student_face_templates'");
        }
        $this->assert("student_face_templates table exists in database", (bool)$stmt->fetch());

        // 2. Verify UNIQUE constraint on student_id
        $vec1 = json_encode($this->generateSyntheticVector(1.1));
        $vec2 = json_encode($this->generateSyntheticVector(2.2));

        try {
            $ins1 = $this->pdo->prepare("INSERT INTO student_face_templates (student_id, embedding, status) VALUES (5, :v, 'ACTIVE')");
            $ins1->execute([':v' => $vec1]);

            // Attempt duplicate raw insert without ON CONFLICT to test DB constraint
            $ins2 = $this->pdo->prepare("INSERT INTO student_face_templates (student_id, embedding, status) VALUES (5, :v, 'ACTIVE')");
            $ins2->execute([':v' => $vec2]);
            $uniqueViolated = false;
        } catch (\Throwable $e) {
            $uniqueViolated = true;
        }

        $this->assert("Database enforces UNIQUE constraint on student_id in student_face_templates", $uniqueViolated);

        // Clean up test row
        $del = $this->pdo->prepare("DELETE FROM student_face_templates WHERE student_id = 5");
        $del->execute();
    }
}

// Run test suite if invoked directly via CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $test = new FaceVerificationTest();
    $test->run();
}
