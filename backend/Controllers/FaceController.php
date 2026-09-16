<?php
/**
 * SAMS - Face Verification & Biometric Enrollment Controller
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\FaceVerificationService;
use SAMS\Services\GeminiService;
use SAMS\Utils\Response;
use SAMS\Utils\Validator;

class FaceController
{
    /**
     * Test image quality via Gemini AI vision or local engine
     * POST /api/face/quality-check
     */
    public static function checkQuality(): void
    {
        AuthMiddleware::authenticate();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)->required('image');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $result = GeminiService::checkFaceQuality($input['image']);
        Response::success($result, 'Image quality assessment completed.');
    }

    /**
     * Face Verification for Live Attendance Marking
     * POST /api/face/verify
     */
    public static function verify(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('session_id', 'image')
            ->numeric('session_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $sessionId = (int)$input['session_id'];
        $image = (string)$input['image'];
        $targetStudentId = isset($input['student_id']) ? (int)$input['student_id'] : null;
        $livenessPassed = !isset($input['liveness_passed']) || (bool)$input['liveness_passed'];

        $result = FaceVerificationService::verify($sessionId, $image, $targetStudentId, $livenessPassed);

        if (!$result['verified']) {
            Response::error($result['message'], $result['result_code'] ?? 'VERIFICATION_FAILED', 422, $result);
        }

        // If identity verified, mark attendance in the session if auto_mark requested
        $autoMark = !isset($input['auto_mark']) || (bool)$input['auto_mark'];
        $attendanceRecord = null;

        if ($autoMark && isset($result['student']['student_id'])) {
            $stuId = (int)$result['student']['student_id'];
            $pdo = Database::getConnection();

            // Verify session is OPEN and belongs to teacher if TEACHER role
            $sessStmt = $pdo->prepare("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = :sid");
            $sessStmt->execute([':sid' => $sessionId]);
            $session = $sessStmt->fetch();

            if (!$session) {
                Response::notFound("Attendance session #{$sessionId} not found.");
            }
            if ($user['role_name'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacher_id']) {
                Response::forbidden("Access denied: You can only record attendance for your own sessions.");
            }
            if ($session['status'] === 'CLOSED') {
                Response::forbidden("This attendance session has been closed. Modifications require administrator override.");
            }

            // Check duplicate
            $dupStmt = $pdo->prepare("SELECT record_id, status FROM attendance_records WHERE session_id = :sess AND student_id = :sid");
            $dupStmt->execute([':sess' => $sessionId, ':sid' => $stuId]);
            $existing = $dupStmt->fetch();

            if ($existing) {
                $result['attendance_status'] = $existing['status'];
                $result['already_marked'] = true;
                $result['message'] .= " (Already recorded as {$existing['status']})";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO attendance_records (session_id, student_id, status, marked_at, verification_method, confidence_score, ip_address)
                    VALUES (:sess, :sid, 'PRESENT', CURRENT_TIMESTAMP, 'FACE_AI', :conf, :ip)
                ");
                $ins->execute([
                    ':sess' => $sessionId,
                    ':sid' => $stuId,
                    ':conf' => $result['confidence'],
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
                $result['attendance_status'] = 'PRESENT';
                $result['already_marked'] = false;
                $result['message'] .= " Attendance marked as Present.";
            }
        }

        Response::success($result, $result['message']);
    }

    /**
     * Enroll Student Biometrics with Privacy Consent
     * POST /api/face/enroll
     */
    public static function enroll(): void
    {
        $user = AuthMiddleware::authenticate();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('student_id', 'image', 'consent')
            ->numeric('student_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $studentId = (int)$input['student_id'];
        $consent = (bool)$input['consent'];
        $image = (string)$input['image'];

        // Student can only enroll themselves; Admin/Teacher can enroll any student
        if ($user['role_name'] === 'STUDENT' && (int)$user['student_id'] !== $studentId) {
            Response::forbidden("Students may only enroll their own face profile.");
        }

        $result = FaceVerificationService::enroll($studentId, $image, (int)$user['user_id'], $consent);
        if (!$result['success']) {
            Response::error($result['message'], 'ENROLLMENT_FAILED', 422, $result);
        }

        Response::success($result, $result['message']);
    }

    /**
     * Delete Biometric Profile (Right to Erasure)
     * DELETE /api/face/{studentId}
     */
    public static function deleteBiometrics(int $studentId): void
    {
        $user = AuthMiddleware::authenticate();

        if ($user['role_name'] === 'STUDENT' && (int)$user['student_id'] !== $studentId) {
            Response::forbidden("Unauthorized to delete another student's biometric data.");
        }

        FaceVerificationService::deleteBiometricData($studentId, (int)$user['user_id']);
        Response::success(null, "Biometric face profile deleted successfully in compliance with privacy retention policies.");
    }
}
