<?php
/**
 * SAMS - CompreFace Integration & Attendance Flow Automated Test Suite
 * Tests CompreFace subject mapping, multi-frame recognition, duplicate protection,
 * class/division validation, RBAC, and graceful fallback.
 */

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
use SAMS\Middleware\AuthMiddleware;
use SAMS\Utils\Response;
use SAMS\Services\CompreFaceService;
use SAMS\Services\FaceVerificationService;
use SAMS\Controllers\FaceController;

class CompreFaceIntegrationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private ?\PDO $pdo = null;
    private array $failures = [];

    public function run(bool $exitOnFinish = true): array
    {
        echo "\n======================================================\n";
        echo "  SAMS - CompreFace Facial Recognition Integration Tests\n";
        echo "======================================================\n\n";

        $this->pdo = Database::getConnection();
        FaceVerificationService::ensureEnrollmentsTableExists($this->pdo);

        $this->testSubjectNamingAndParsing();
        $this->testCompreFaceEnrollmentFlow();
        $this->testCompreFaceRecognitionAndAttendanceFlow();
        $this->testDuplicateAttendancePrevention();
        $this->testEligibilityAndClassDivisionValidation();
        $this->testThresholdAndMultiFaceValidation();
        $this->testServiceUnavailableGracefulHandling();
        $this->testBiometricErasureFlow();

        echo "\n------------------------------------------------------\n";
        echo "CompreFace Tests: " . ($this->passed + $this->failed) . " | Passed: {$this->passed} | Failed: {$this->failed}\n";
        echo "------------------------------------------------------\n";

        if ($this->failed === 0) {
            echo "✅ All CompreFace Integration tests passed successfully!\n\n";
        } else {
            echo "❌ Some CompreFace Integration tests failed!\n\n";
            if ($exitOnFinish) {
                exit(1);
            }
        }

        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'failures' => $this->failures
        ];
    }

    private function assert(string $desc, bool $condition): void
    {
        if ($condition) {
            echo "  ✔ {$desc}\n";
            $this->passed++;
        } else {
            echo "  ✖ FAIL: {$desc}\n";
            $this->failed++;
        }
    }

    private function testSubjectNamingAndParsing(): void
    {
        echo "[1] CompreFace Subject Identity Conventions\n";

        $subject = CompreFaceService::formatSubject(1042);
        $this->assert("Subject format matches SAMS_STUDENT_<id>", $subject === 'SAMS_STUDENT_1042');

        $parsed = CompreFaceService::parseSubject('SAMS_STUDENT_1042');
        $this->assert("Subject parser extracts integer student_id = 1042", $parsed === 1042);

        $invalidParsed = CompreFaceService::parseSubject('Rahul_Sharma');
        $this->assert("Student name as subject is rejected by parser", $invalidParsed === null);

        $invalidParsed2 = CompreFaceService::parseSubject('UNKNOWN');
        $this->assert("Non-student subject returns null", $invalidParsed2 === null);
    }

    private function testCompreFaceEnrollmentFlow(): void
    {
        echo "\n[2] Student Face Enrollment with Multi-Sample Frames\n";

        // Authenticate as Admin
        Response::enableTestMode();
        AuthMiddleware::setAuthenticatedUser([
            'user_id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN',
            'email' => 'admin@sams.edu'
        ]);

        // Mock CompreFace responses
        $sampleCounter = 0;
        CompreFaceService::setMockHandler(function (string $action, array $params) use (&$sampleCounter) {
            if ($action === 'addSubjectExample') {
                $sampleCounter++;
                return [
                    'success' => true,
                    'image_id' => 'sample-img-uuid-' . $sampleCounter,
                    'subject' => $params['subject'] ?? 'SAMS_STUDENT_1'
                ];
            }
            return null;
        });

        // Test 1: Enroll Student 1 with 3 samples (front, left, right)
        $dummyImage1 = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FACE_SAMPLE_FRONT', 200));
        $dummyImage2 = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FACE_SAMPLE_LEFT', 200));
        $dummyImage3 = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FACE_SAMPLE_RIGHT', 200));

        $res = FaceVerificationService::enrollCompreFace(1, [$dummyImage1, $dummyImage2, $dummyImage3], 1, 'enroll', true);

        $this->assert("Enrollment succeeds with 3 multi-angle samples", !empty($res['success']) && $res['success'] === true);
        $this->assert("Subject identity is formatted as SAMS_STUDENT_1", ($res['compreface_subject'] ?? '') === 'SAMS_STUDENT_1');
        $this->assert("Sample count is recorded as 3", ($res['sample_count'] ?? 0) === 3);

        // Verify database enrollment record
        $stmt = $this->pdo->prepare("SELECT * FROM student_face_enrollments WHERE student_id = 1");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("student_face_enrollments table contains record for student 1", !empty($row));
        $this->assert("CompreFace subject matches in database", ($row['compreface_subject'] ?? '') === 'SAMS_STUDENT_1');
        $this->assert("Enrollment status is ENROLLED", ($row['enrollment_status'] ?? '') === 'ENROLLED');

        // Cleanup mock handler
        CompreFaceService::setMockHandler(null);
    }

    private function testCompreFaceRecognitionAndAttendanceFlow(): void
    {
        echo "\n[3] Face Recognition & Automatic Attendance Marking Flow\n";

        // Create an open session for teacher 1
        $stmt = $this->pdo->prepare("
            SELECT session_id, teacher_id FROM attendance_sessions 
            WHERE status = 'OPEN' 
            LIMIT 1
        ");
        $stmt->execute();
        $sess = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sess) {
            $ins = $this->pdo->prepare("
                INSERT INTO attendance_sessions (class_id, division_id, subject_id, teacher_id, session_date, start_time, lecture_number, status, verification_mode)
                VALUES (1, 1, 1, 1, date('now'), '09:00', 1, 'OPEN', 'FACE_AI')
            ");
            $ins->execute();
            $sessionId = (int)$this->pdo->lastInsertId();
            $teacherId = 1;
        } else {
            $sessionId = (int)$sess['session_id'];
            $teacherId = (int)$sess['teacher_id'];
        }

        // Student 1 belongs to class 1, division 1
        // Clear any existing attendance record for Student 1 in this session
        $this->pdo->exec("DELETE FROM attendance_records WHERE session_id = {$sessionId} AND student_id = 1");

        // Mock CompreFace returning SAMS_STUDENT_1 with high similarity (0.94)
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => true,
                    'student_id' => 1,
                    'subject' => 'SAMS_STUDENT_1',
                    'similarity' => 0.94,
                    'threshold' => $params['threshold'] ?? 0.80,
                    'box' => ['probability' => 0.99, 'x_min' => 10, 'y_min' => 10, 'x_max' => 100, 'y_max' => 100]
                ];
            }
            return null;
        });

        $dummyFrame = 'data:image/jpeg;base64,' . base64_encode(str_repeat('CAMERA_FRAME_RAHUL', 200));

        $res = FaceVerificationService::recognizeCompreFace(
            $sessionId,
            $dummyFrame,
            true,
            'blink',
            1,
            true,
            $teacherId
        );

        $this->assert("Recognition succeeds (verified = true)", !empty($res['verified']) && $res['verified'] === true);
        $this->assert("Resolved student ID matches database student 1", ($res['student']['student_id'] ?? 0) === 1);
        $this->assert("CompreFace similarity score returned (>= 0.80)", ($res['similarity'] ?? 0) >= 0.80);
        $this->assert("Attendance status marked as PRESENT", ($res['attendance_status'] ?? '') === 'PRESENT');

        // Check attendance_records in database
        $attStmt = $this->pdo->prepare("SELECT * FROM attendance_records WHERE session_id = :sid AND student_id = 1");
        $attStmt->execute([':sid' => $sessionId]);
        $att = $attStmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("Attendance record saved to attendance_records table", !empty($att));
        $this->assert("Attendance status in DB is PRESENT", ($att['status'] ?? '') === 'PRESENT');
        $this->assert("Verification method is FACE_AI", ($att['verification_method'] ?? '') === 'FACE_AI');

        CompreFaceService::setMockHandler(null);
    }

    private function testDuplicateAttendancePrevention(): void
    {
        echo "\n[4] Duplicate Attendance Recognition Cycle\n";

        $stmt = $this->pdo->query("SELECT session_id, teacher_id FROM attendance_sessions WHERE status = 'OPEN' LIMIT 1");
        $sess = $stmt->fetch(\PDO::FETCH_ASSOC);
        $sessionId = (int)$sess['session_id'];
        $teacherId = (int)$sess['teacher_id'];

        // Mock CompreFace returning the same student again
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => true,
                    'student_id' => 1,
                    'subject' => 'SAMS_STUDENT_1',
                    'similarity' => 0.96,
                    'threshold' => $params['threshold'] ?? 0.80,
                    'box' => ['probability' => 0.99, 'x_min' => 10, 'y_min' => 10, 'x_max' => 100, 'y_max' => 100]
                ];
            }
            return null;
        });

        $dummyFrame = 'data:image/jpeg;base64,' . base64_encode(str_repeat('CAMERA_FRAME_RAHUL_AGAIN', 200));

        $res = FaceVerificationService::recognizeCompreFace(
            $sessionId,
            $dummyFrame,
            true,
            'blink',
            1,
            true,
            $teacherId
        );

        $this->assert("Duplicate recognition returns verified = true", !empty($res['verified']));
        $this->assert("Duplicate recognition returns status = already_present", ($res['status'] ?? '') === 'already_present');
        $this->assert("Duplicate flag already_marked = true", !empty($res['already_marked']));
        $this->assert("Descriptive message indicates attendance already recorded", stripos($res['message'], 'Already') !== false);

        // Ensure strictly 1 record in database
        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = :sid AND student_id = 1");
        $cntStmt->execute([':sid' => $sessionId]);
        $count = (int)$cntStmt->fetchColumn();
        $this->assert("No duplicate record inserted into attendance_records (count = 1)", $count === 1);

        CompreFaceService::setMockHandler(null);
    }

    private function testEligibilityAndClassDivisionValidation(): void
    {
        echo "\n[5] Class and Division Server-Side Validation\n";

        // Find or create a session for Class 2, Division 2
        $sessStmt = $this->pdo->prepare("
            SELECT session_id, teacher_id FROM attendance_sessions 
            WHERE status = 'OPEN' AND (class_id != 1 OR division_id != 1)
            LIMIT 1
        ");
        $sessStmt->execute();
        $sess = $sessStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sess) {
            $ins = $this->pdo->prepare("
                INSERT INTO attendance_sessions (class_id, division_id, subject_id, teacher_id, session_date, start_time, lecture_number, status, verification_mode)
                VALUES (2, 2, 1, 1, date('now'), '11:00', 2, 'OPEN', 'FACE_AI')
            ");
            $ins->execute();
            $sessionId = (int)$this->pdo->lastInsertId();
            $teacherId = 1;
        } else {
            $sessionId = (int)$sess['session_id'];
            $teacherId = (int)$sess['teacher_id'];
        }

        // Student 1 belongs to Class 1, Division 1. They are NOT eligible for this session!
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => true,
                    'student_id' => 1,
                    'subject' => 'SAMS_STUDENT_1',
                    'similarity' => 0.95,
                    'threshold' => $params['threshold'] ?? 0.80,
                    'box' => ['probability' => 0.99, 'x_min' => 10, 'y_min' => 10, 'x_max' => 100, 'y_max' => 100]
                ];
            }
            return null;
        });

        $dummyFrame = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FRAME_WRONG_CLASS', 200));

        $res = FaceVerificationService::recognizeCompreFace(
            $sessionId,
            $dummyFrame,
            true,
            'none',
            1,
            true,
            $teacherId
        );

        $this->assert("Student from wrong class is rejected (verified = false)", empty($res['verified']));
        $this->assert("Result code indicates WRONG_CLASS or ELIGIBILITY_FAILED", in_array($res['result_code'] ?? '', ['WRONG_CLASS', 'CLASS_RESTRICTION']));
        $this->assert("Attendance is NOT marked for wrong class student", empty($res['attendance_marked']));

        CompreFaceService::setMockHandler(null);
    }

    private function testThresholdAndMultiFaceValidation(): void
    {
        echo "\n[6] Similarity Threshold and Multi-Face Safeguards\n";

        $stmt = $this->pdo->query("SELECT session_id, teacher_id FROM attendance_sessions WHERE status = 'OPEN' AND class_id = 1 LIMIT 1");
        $sess = $stmt->fetch(\PDO::FETCH_ASSOC);
        $sessionId = (int)$sess['session_id'];
        $teacherId = (int)$sess['teacher_id'];

        // Case A: Similarity 0.65 (below default threshold 0.80)
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => false,
                    'error_code' => 'LOW_CONFIDENCE',
                    'similarity' => 0.65,
                    'threshold' => 0.80,
                    'message' => 'Face match confidence too low (65.0%).'
                ];
            }
            return null;
        });

        $dummyFrame = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FRAME_LOW_CONFIDENCE', 200));
        $res = FaceVerificationService::recognizeCompreFace($sessionId, $dummyFrame, true, 'none', 1, true, $teacherId);

        $this->assert("Low similarity (< 0.80) rejected (verified = false)", empty($res['verified']));
        $this->assert("Result code is LOW_CONFIDENCE or SIMILARITY_BELOW_THRESHOLD", in_array($res['result_code'] ?? '', ['LOW_CONFIDENCE', 'SIMILARITY_BELOW_THRESHOLD']));
        $this->assert("Message informs face match confidence is too low", strpos($res['message'], 'low') !== false || strpos($res['message'], 'threshold') !== false);

        // Case B: Multiple faces in frame
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => false,
                    'error_code' => 'MULTIPLE_FACES',
                    'message' => 'Please keep only one student in front of the camera.'
                ];
            }
            return null;
        });

        $resMulti = FaceVerificationService::recognizeCompreFace($sessionId, $dummyFrame, true, 'none', 1, true, $teacherId);
        $this->assert("Multiple faces in frame rejected (verified = false)", empty($resMulti['verified']));
        $this->assert("Result code is MULTIPLE_FACES", ($resMulti['result_code'] ?? '') === 'MULTIPLE_FACES');
        $this->assert("Message advises keeping only one student in front of camera", strpos($resMulti['message'], 'one') !== false);

        // Case C: No face detected
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => false,
                    'error_code' => 'NO_FACE',
                    'message' => 'No face detected in camera capture.'
                ];
            }
            return null;
        });

        $resNoFace = FaceVerificationService::recognizeCompreFace($sessionId, $dummyFrame, true, 'none', 1, true, $teacherId);
        $this->assert("No face detected rejected (verified = false)", empty($resNoFace['verified']));
        $this->assert("Result code is NO_FACE or NO_FACE_DETECTED", in_array($resNoFace['result_code'] ?? '', ['NO_FACE', 'NO_FACE_DETECTED']));

        CompreFaceService::setMockHandler(null);
    }

    private function testServiceUnavailableGracefulHandling(): void
    {
        echo "\n[7] Service Unavailable & Network Failure Graceful Handling\n";

        $stmt = $this->pdo->query("SELECT session_id, teacher_id FROM attendance_sessions WHERE status = 'OPEN' AND class_id = 1 LIMIT 1");
        $sess = $stmt->fetch(\PDO::FETCH_ASSOC);
        $sessionId = (int)$sess['session_id'];
        $teacherId = (int)$sess['teacher_id'];

        // Mock CompreFace network crash / 503
        CompreFaceService::setMockHandler(function (string $action, array $params) {
            if ($action === 'recognize') {
                return [
                    'success' => false,
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'Face recognition service unavailable. Please contact the administrator or use manual attendance.'
                ];
            }
            return null;
        });

        $dummyFrame = 'data:image/jpeg;base64,' . base64_encode(str_repeat('FRAME_CRASH_TEST', 200));
        $res = FaceVerificationService::recognizeCompreFace($sessionId, $dummyFrame, true, 'none', 1, true, $teacherId);

        $this->assert("Network failure handled gracefully (no PHP fatal error)", is_array($res));
        $this->assert("Verified is false on service failure", empty($res['verified']));
        $this->assert("Result code is SERVICE_UNAVAILABLE", ($res['result_code'] ?? '') === 'SERVICE_UNAVAILABLE');
        $this->assert("Clean message displayed: Face recognition service unavailable", strpos($res['message'], 'unavailable') !== false);

        CompreFaceService::setMockHandler(null);
    }

    private function testBiometricErasureFlow(): void
    {
        echo "\n[8] Right to Erasure & Subject Deletion Flow\n";

        $deletedSubject = null;
        CompreFaceService::setMockHandler(function (string $action, array $params) use (&$deletedSubject) {
            if ($action === 'deleteSubject') {
                $deletedSubject = $params['subject'] ?? '';
                return ['success' => true, 'subject' => $deletedSubject];
            }
            return null;
        });

        // Delete biometrics for Student 1
        $res = FaceVerificationService::deleteBiometricData(1, 1);

        $this->assert("Biometric deletion succeeds (boolean true)", $res === true);
        $this->assert("CompreFace API subject delete was requested", $deletedSubject === 'SAMS_STUDENT_1');

        // Verify record in student_face_enrollments was deleted
        $stmt = $this->pdo->prepare("SELECT * FROM student_face_enrollments WHERE student_id = 1");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("Enrollment record removed from database", empty($row));

        // Verify student status was reset to NOT_ENROLLED
        $stuStmt = $this->pdo->prepare("SELECT face_verification_status FROM students WHERE student_id = 1");
        $stuStmt->execute();
        $status = $stuStmt->fetchColumn();
        $this->assert("Student face status reset to NOT_ENROLLED", $status === 'NOT_ENROLLED');

        CompreFaceService::setMockHandler(null);
    }
}

$test = new CompreFaceIntegrationTest();
$test->run();
