<?php
/**
 * SAMS - Face Verification & Biometric Enrollment Controller
 * Securely manages session lifecycle, biometric template enrollment,
 * real-time verification, and institutional settings.
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\FaceVerificationService;
use SAMS\Services\AuditService;
use SAMS\Services\GeminiService;
use SAMS\Utils\Response;
use SAMS\Utils\Validator;
use PDO;

class FaceController
{
    /**
     * Start a Face Verification Attendance Session
     * POST /api/face/session/start
     */
    public static function startSession(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('class_id', 'subject_id', 'session_date', 'start_time')
            ->numeric('class_id')
            ->numeric('subject_id');

        if (!empty($input['division_id'])) {
            $validator->numeric('division_id');
        }

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $pdo = Database::getConnection();

        if ($user['role_name'] === 'TEACHER') {
            $teacherId = (int)$user['teacher_id'];
        } else {
            $teacherId = !empty($input['teacher_id']) ? (int)$input['teacher_id'] : null;
            if (!$teacherId) {
                $tQuery = $pdo->query("SELECT teacher_id FROM teachers WHERE status = 'ACTIVE' ORDER BY teacher_id ASC LIMIT 1");
                $teacherId = (int)($tQuery ? $tQuery->fetchColumn() : 1);
            }
        }
        $classId = (int)$input['class_id'];
        $divisionId = !empty($input['division_id']) ? (int)$input['division_id'] : null;

        // Ensure division exists for this class, otherwise resolve the class's Division A
        $validDiv = false;
        if ($divisionId !== null && $divisionId > 0) {
            $chkStmt = $pdo->prepare("SELECT division_id FROM divisions WHERE division_id = :did AND class_id = :cid");
            $chkStmt->execute([':did' => $divisionId, ':cid' => $classId]);
            $validDiv = (bool)$chkStmt->fetchColumn();
        }
        if (!$validDiv) {
            $divStmt = $pdo->prepare("SELECT division_id FROM divisions WHERE class_id = :cid AND division_name = 'A' LIMIT 1");
            $divStmt->execute([':cid' => $classId]);
            $resolvedDiv = (int)$divStmt->fetchColumn();
            $divisionId = $resolvedDiv > 0 ? $resolvedDiv : 1;
        }
        $subjectId = (int)$input['subject_id'];
        $sessionDate = (string)$input['session_date'];
        $startTime = (string)$input['start_time'];
        $lectureNum = (int)($input['lecture_number'] ?? 1);

        // Check for existing open session for this class/div/subject today
        if ($divisionId !== null) {
            $dupStmt = $pdo->prepare("
                SELECT session_id FROM attendance_sessions
                WHERE class_id = :cid AND division_id = :did AND subject_id = :sid 
                  AND session_date = :sdate AND status = 'OPEN'
            ");
            $dupStmt->execute([
                ':cid' => $classId,
                ':did' => $divisionId,
                ':sid' => $subjectId,
                ':sdate' => $sessionDate
            ]);
        } else {
            $dupStmt = $pdo->prepare("
                SELECT session_id FROM attendance_sessions
                WHERE class_id = :cid AND (division_id IS NULL OR division_id = 0) AND subject_id = :sid 
                  AND session_date = :sdate AND status = 'OPEN'
            ");
            $dupStmt->execute([
                ':cid' => $classId,
                ':sid' => $subjectId,
                ':sdate' => $sessionDate
            ]);
        }
        $existing = $dupStmt->fetch();

        if ($existing) {
            $sessId = (int)$existing['session_id'];
            // Return existing session instead of error to allow smooth reconnection
            self::returnSessionDetails($sessId, 'Reconnected to active open attendance session.');
            return;
        }

        // Create new session with duplicate constraint protection
        try {
            $stmt = $pdo->prepare("
                INSERT INTO attendance_sessions (class_id, division_id, subject_id, teacher_id, session_date, start_time, lecture_number, status, verification_mode)
                VALUES (:cid, :did, :sid, :tid, :sdate, :stime, :lec, 'OPEN', 'FACE_AI')
                RETURNING session_id
            ");
            $stmt->execute([
                ':cid' => $classId,
                ':did' => $divisionId,
                ':sid' => $subjectId,
                ':tid' => $teacherId,
                ':sdate' => $sessionDate,
                ':stime' => $startTime,
                ':lec' => $lectureNum
            ]);

            $newSessionId = (int)$stmt->fetchColumn() ?: (int)$pdo->lastInsertId();
            $stmt->closeCursor();

            AuditService::log($user['user_id'], 'FACE_SESSION_STARTED', 'attendance_sessions', (string)$newSessionId, [
                'class_id' => $classId,
                'division_id' => $divisionId,
                'subject_id' => $subjectId,
                'session_date' => $sessionDate
            ]);

            self::returnSessionDetails($newSessionId, 'Face verification attendance session started successfully.', 201);
        } catch (\PDOException $pEx) {
            // Check for unique violation (PostgreSQL 23505 or SQLite 19/2067)
            if ($pEx->getCode() === '23505' || str_contains($pEx->getMessage(), 'UNIQUE constraint failed') || str_contains($pEx->getMessage(), 'unique constraint')) {
                // Find existing session for this exact lecture period
                $chkQuery = $pdo->prepare("
                    SELECT session_id, status FROM attendance_sessions
                    WHERE class_id = :cid AND subject_id = :sid AND session_date = :sdate AND lecture_number = :lec
                    ORDER BY session_id DESC LIMIT 1
                ");
                $chkQuery->execute([
                    ':cid' => $classId,
                    ':sid' => $subjectId,
                    ':sdate' => $sessionDate,
                    ':lec' => $lectureNum
                ]);
                $matched = $chkQuery->fetch();

                if ($matched && $matched['status'] === 'OPEN') {
                    self::returnSessionDetails((int)$matched['session_id'], 'Reconnected to active open attendance session.');
                    return;
                }

                $matchedId = $matched ? (int)$matched['session_id'] : 0;
                Response::conflict("An attendance session (#{$matchedId}) has already been recorded for Lecture Period #{$lectureNum} on {$sessionDate}. Please select another lecture period.", [
                    'session_id' => $matchedId
                ]);
                return;
            }
            throw $pEx;
        }
    }

    /**
     * End / Close a Face Attendance Session
     * POST /api/face/session/end
     */
    public static function endSession(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)->required('session_id')->numeric('session_id');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $sessionId = (int)$input['session_id'];
        $pdo = Database::getConnection();

        $sessStmt = $pdo->prepare("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = :sid");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }

        if ($user['role_name'] === 'TEACHER') {
            $userTeacherId = (int)($user['teacher_id'] ?? 0);
            $sessionTeacherId = (int)$session['teacher_id'];
            if ($sessionTeacherId !== $userTeacherId) {
                Response::forbidden("Access denied: You can only close your own attendance sessions.");
                return;
            }
        }

        $upd = $pdo->prepare("
            UPDATE attendance_sessions 
            SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP, end_time = CURRENT_TIME 
            WHERE session_id = :sid
        ");
        $upd->execute([':sid' => $sessionId]);

        // Get count of verified students
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = :sid AND status = 'PRESENT'");
        $countStmt->execute([':sid' => $sessionId]);
        $presentCount = (int)$countStmt->fetchColumn();

        AuditService::log($user['user_id'], 'FACE_SESSION_ENDED', 'attendance_sessions', (string)$sessionId, [
            'present_count' => $presentCount
        ]);

        Response::success([
            'session_id' => $sessionId,
            'status' => 'CLOSED',
            'verified_count' => $presentCount
        ], 'Face attendance session closed successfully.');
    }

    /**
     * Get Session Details & Live Stats
     * GET /api/face/session/{id}
     */
    public static function getSession(int $sessionId): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        self::returnSessionDetails($sessionId, 'Session details retrieved.');
    }

    /**
     * Get Verified Student Results for a Session
     * GET /api/face/session/{id}/results
     */
    public static function getSessionResults(int $sessionId): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $sessStmt = $pdo->prepare("
            SELECT s.session_id, s.session_date, s.start_time, s.status,
                   c.class_name, c.class_code, d.division_name, sub.subject_name
            FROM attendance_sessions s
            JOIN classes c ON s.class_id = c.class_id
            LEFT JOIN divisions d ON s.division_id = d.division_id
            JOIN subjects sub ON s.subject_id = sub.subject_id
            WHERE s.session_id = :sid
        ");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }

        // Fetch verified records (NEVER exposing biometric vectors)
        $recStmt = $pdo->prepare("
            SELECT 
                r.record_id,
                r.student_id,
                stu.roll_number,
                stu.student_uid,
                stu.full_name,
                r.status,
                r.marked_at,
                r.confidence_score,
                r.verification_method
            FROM attendance_records r
            JOIN students stu ON r.student_id = stu.student_id
            WHERE r.session_id = :sid
            ORDER BY r.marked_at DESC
        ");
        $recStmt->execute([':sid' => $sessionId]);
        $records = $recStmt->fetchAll();

        Response::success([
            'session' => $session,
            'results' => $records,
            'verified_count' => count($records)
        ], 'Face attendance session results retrieved.');
    }

    /**
     * Real-time Face Verification Endpoint
     * POST /api/face/verify
     */
    public static function verify(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        if (empty($user)) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('session_id', 'embedding')
            ->numeric('session_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
            return;
        }

        $sessionId = (int)$input['session_id'];
        $embedding = is_array($input['embedding']) ? $input['embedding'] : json_decode($input['embedding'], true);
        $livenessPassed = !isset($input['liveness_passed']) || (bool)$input['liveness_passed'];
        $livenessAction = (string)($input['liveness_action'] ?? 'none');
        $autoMark = !isset($input['auto_mark']) || (bool)$input['auto_mark'];

        if (!is_array($embedding) || count($embedding) !== 128) {
            Response::error('Invalid biometric embedding: Must be an array of 128 numeric descriptors.', 'INVALID_EMBEDDING', 422);
            return;
        }

        $pdo = Database::getConnection();

        // Verify session authorization for Teacher
        $sessStmt = $pdo->prepare("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = :sid");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
            return;
        }
        if ($user['role_name'] === 'TEACHER') {
            $userTeacherId = (int)($user['teacher_id'] ?? 0);
            $sessionTeacherId = (int)$session['teacher_id'];
            if ($sessionTeacherId !== $userTeacherId) {
                Response::forbidden("Access denied: You can only record attendance for your own sessions.");
                return;
            }
        }
        if ($session['status'] === 'CLOSED') {
            Response::forbidden("This attendance session has been closed. Modifications require administrator override.");
            return;
        }

        $result = FaceVerificationService::verify(
            $sessionId,
            $embedding,
            $livenessPassed,
            $livenessAction,
            (int)$user['user_id'],
            $autoMark
        );

        if (!$result['verified']) {
            Response::error($result['message'], $result['result_code'] ?? 'FACE_NOT_RECOGNIZED', 422, $result);
            return;
        }

        Response::success($result, $result['message']);
    }

    /**
     * Enroll Student Face Biometrics
     * POST /api/face/enroll
     */
    public static function enroll(): void
    {
        $user = AuthMiddleware::authenticate();
        if (empty($user)) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $studentId = isset($input['student_id']) ? (int)$input['student_id'] : 0;
        if ($studentId <= 0) {
            Response::validationError(['student_id' => 'Valid student ID is required.'], 'Student ID is missing or invalid.');
            return;
        }

        if (empty($input['consent'])) {
            Response::validationError(['consent' => 'Student biometric consent confirmation is required.'], 'Student biometric consent is required prior to enrollment.');
            return;
        }

        $rawEmbedding = $input['embedding'] ?? null;
        $rawImage = $input['image'] ?? null;

        if (empty($rawEmbedding) && empty($rawImage)) {
            Response::validationError(['embedding' => 'Biometric face descriptor or camera capture is required.'], 'Enrollment request is missing required face template data.');
            return;
        }

        // RBAC: Admin is the ONLY role allowed to enroll student faces.
        // Teachers and students are strictly forbidden from face enrollment (HTTP 403).
        if ($user['role_name'] !== 'ADMIN') {
            Response::forbidden("Access denied: Only Administrators are authorized to enroll student facial biometrics.");
            return;
        }

        $qualityScore = isset($input['quality_score']) ? (float)$input['quality_score'] : 1.0;
        $modelVersion = (string)($input['model_version'] ?? FaceVerificationService::DEFAULT_MODEL_VERSION);

        if (!empty($rawEmbedding)) {
            $embedding = is_array($rawEmbedding) ? $rawEmbedding : json_decode((string)$rawEmbedding, true);
            if (!is_array($embedding) || count($embedding) !== 128) {
                Response::error('Face embedding is invalid: Must provide a 128-dimensional facial landmark descriptor.', 'INVALID_EMBEDDING', 422);
                return;
            }

            foreach ($embedding as $v) {
                if (!is_numeric($v) || is_nan((float)$v) || is_infinite((float)$v)) {
                    Response::error('Face model failed to generate a valid embedding. Please recapture your face.', 'CORRUPTED_EMBEDDING', 422);
                    return;
                }
            }

            $result = FaceVerificationService::enroll(
                $studentId,
                $embedding,
                (int)$user['user_id'],
                true,
                $qualityScore,
                $modelVersion
            );
        } else {
            // Fallback for camera frame image
            $result = FaceVerificationService::enrollWithImage(
                $studentId,
                (string)$rawImage,
                (int)$user['user_id'],
                true,
                $modelVersion
            );
        }

        if (!$result['success']) {
            Response::error($result['message'], $result['result_code'] ?? 'ENROLLMENT_FAILED', 422, $result);
            return;
        }

        Response::success($result, $result['message']);
    }

    /**
     * Delete Biometric Profile (Right to Erasure)
     * DELETE /api/face/enrollment/{student_id} or DELETE /api/face/{student_id}
     */
    public static function deleteBiometrics(int $studentId): void
    {
        $user = AuthMiddleware::authenticate();
        if (empty($user)) {
            return;
        }

        // RBAC: Admin can delete any; Student can only delete own profile; Teacher cannot delete
        if ($user['role_name'] === 'STUDENT' && (int)($user['student_id'] ?? 0) !== $studentId) {
            Response::forbidden("Unauthorized to delete another student's biometric data.");
            return;
        }
        if ($user['role_name'] === 'TEACHER') {
            Response::forbidden("Access denied: Biometric template removal requires Administrator authority or student consent.");
            return;
        }

        FaceVerificationService::deleteBiometricData($studentId, (int)$user['user_id']);
        Response::success(null, "Biometric face profile deleted successfully in compliance with privacy retention policies.");
    }

    /**
     * Check Enrollment Status for a Student
     * GET /api/face/status/{student_id}
     */
    public static function status(int $studentId): void
    {
        $user = AuthMiddleware::authenticate();
        if (empty($user)) {
            return;
        }

        if ($user['role_name'] === 'STUDENT' && (int)($user['student_id'] ?? 0) !== $studentId) {
            Response::forbidden("Access denied: You cannot view biometric status for another student.");
            return;
        }

        $info = FaceVerificationService::getEnrollmentStatus($studentId);
        Response::success($info, 'Biometric status retrieved.');
    }

    /**
     * Get Face Verification System Settings & Live Statistics (Admin)
     * GET /api/face/settings
     */
    public static function getSettings(): void
    {
        $user = RoleMiddleware::adminOnly();
        if (empty($user)) {
            return;
        }
        $settings = FaceVerificationService::getSettings();
        Response::success($settings, 'Face verification settings retrieved.');
    }

    /**
     * Update Face Verification System Settings (Admin)
     * POST /api/face/settings
     */
    public static function updateSettings(): void
    {
        $admin = RoleMiddleware::adminOnly();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        if (empty($input)) {
            Response::error('No settings provided in request body.', 'EMPTY_INPUT', 422);
        }

        $updated = FaceVerificationService::updateSettings($input, (int)$admin['user_id']);
        Response::success($updated, 'Face verification settings updated successfully.');
    }

    /**
     * Optional image quality check helper (Gemini or heuristic)
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
     * Private helper to fetch and respond with standardized session details
     */
    private static function returnSessionDetails(int $sessionId, string $message, int $statusCode = 200): void
    {
        $pdo = Database::getConnection();
        $sessStmt = $pdo->prepare("
            SELECT s.session_id, s.class_id, s.division_id, s.subject_id, s.teacher_id, s.session_date, s.start_time, s.status,
                   c.class_name, c.class_code, d.division_name, sub.subject_name, sub.subject_code, t.full_name AS teacher_name
            FROM attendance_sessions s
            JOIN classes c ON s.class_id = c.class_id
            LEFT JOIN divisions d ON s.division_id = d.division_id
            JOIN subjects sub ON s.subject_id = sub.subject_id
            JOIN teachers t ON s.teacher_id = t.teacher_id
            WHERE s.session_id = :sid
        ");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }

        // Count total active students in class/division without parameter reuse
        if (!empty($session['division_id'])) {
            $totStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :cid AND division_id = :did AND status = 'ACTIVE'");
            $totStmt->execute([':cid' => (int)$session['class_id'], ':did' => (int)$session['division_id']]);
        } else {
            $totStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :cid AND status = 'ACTIVE'");
            $totStmt->execute([':cid' => (int)$session['class_id']]);
        }
        $totalStudents = (int)$totStmt->fetchColumn();

        // Fallback if 0 found for specific division
        if ($totalStudents === 0 && !empty($session['division_id'])) {
            $fbStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :cid AND status = 'ACTIVE'");
            $fbStmt->execute([':cid' => (int)$session['class_id']]);
            $totalStudents = (int)$fbStmt->fetchColumn();
        }

        // Count marked present in session
        $prsStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = :sid AND status = 'PRESENT'");
        $prsStmt->execute([':sid' => $sessionId]);
        $verifiedCount = (int)$prsStmt->fetchColumn();

        // Count enrolled with face templates without parameter reuse
        if (!empty($session['division_id'])) {
            $enrStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM students s 
                JOIN student_face_templates sft ON s.student_id = sft.student_id 
                WHERE s.class_id = :cid AND s.division_id = :did AND s.status = 'ACTIVE' AND sft.status = 'ACTIVE'
            ");
            $enrStmt->execute([':cid' => (int)$session['class_id'], ':did' => (int)$session['division_id']]);
        } else {
            $enrStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM students s 
                JOIN student_face_templates sft ON s.student_id = sft.student_id 
                WHERE s.class_id = :cid AND s.status = 'ACTIVE' AND sft.status = 'ACTIVE'
            ");
            $enrStmt->execute([':cid' => (int)$session['class_id']]);
        }
        $biometricEnrolledCount = (int)$enrStmt->fetchColumn();

        // Fallback to class-level enrolled count if division has 0 enrolled templates
        if ($biometricEnrolledCount === 0 && !empty($session['division_id'])) {
            $enrFbStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM students s 
                JOIN student_face_templates sft ON s.student_id = sft.student_id 
                WHERE s.class_id = :cid AND s.status = 'ACTIVE' AND sft.status = 'ACTIVE'
            ");
            $enrFbStmt->execute([':cid' => (int)$session['class_id']]);
            $biometricEnrolledCount = (int)$enrFbStmt->fetchColumn();
        }

        Response::success([
            'session' => $session,
            'stats' => [
                'total_students' => $totalStudents,
                'verified_count' => $verifiedCount,
                'remaining_count' => max(0, $totalStudents - $verifiedCount),
                'biometric_enrolled_count' => $biometricEnrolledCount
            ]
        ], $message, $statusCode);
    }
}
