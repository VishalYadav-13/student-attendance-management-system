<?php
/**
 * SAMS - Attendance Controller
 * Handles session lifecycle, individual/batch marking, duplicate prevention,
 * and audited manual overrides.
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\AuthMiddleware;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\AuditService;
use SAMS\Utils\Response;
use SAMS\Utils\Validator;
use PDO;
use Exception;

class AttendanceController
{
    /**
     * Create / Open an Attendance Session
     * POST /api/attendance/session
     */
    public static function createSession(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('class_id', 'division_id', 'subject_id', 'session_date', 'start_time')
            ->numeric('class_id')
            ->numeric('division_id')
            ->numeric('subject_id');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $teacherId = $user['role_name'] === 'TEACHER' ? (int)$user['teacher_id'] : (int)($input['teacher_id'] ?? 1);
        $classId = (int)$input['class_id'];
        $divisionId = (int)$input['division_id'];
        $subjectId = (int)$input['subject_id'];
        $sessionDate = $input['session_date'];
        $startTime = $input['start_time'];
        $endTime = $input['end_time'] ?? null;
        $mode = $input['verification_mode'] ?? 'HYBRID';
        $lectureNum = (int)($input['lecture_number'] ?? 1);

        $pdo = Database::getConnection();

        // Check if an identical open session already exists
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
        $existing = $dupStmt->fetch();

        if ($existing) {
            Response::conflict("An open attendance session (#{$existing['session_id']}) already exists for this class, division, and subject today.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO attendance_sessions (class_id, division_id, subject_id, teacher_id, session_date, start_time, end_time, lecture_number, status, verification_mode)
            VALUES (:cid, :did, :sid, :tid, :sdate, :stime, :etime, :lec, 'OPEN', :mode)
        ");
        $stmt->execute([
            ':cid' => $classId,
            ':did' => $divisionId,
            ':sid' => $subjectId,
            ':tid' => $teacherId,
            ':sdate' => $sessionDate,
            ':stime' => $startTime,
            ':etime' => $endTime,
            ':lec' => $lectureNum,
            ':mode' => $mode
        ]);

        $newSessionId = (int)$pdo->lastInsertId();
        AuditService::log($user['user_id'], 'SESSION_OPENED', 'attendance_sessions', (string)$newSessionId);

        Response::success([
            'session_id' => $newSessionId,
            'status' => 'OPEN',
            'session_date' => $sessionDate,
            'start_time' => $startTime
        ], 'Attendance session opened successfully.', 201);
    }

    /**
     * Get Roster & Marked Statuses for a Session
     * GET /api/attendance/session/{id}/students
     */
    public static function getSessionStudents(int $sessionId): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $sessStmt = $pdo->prepare("
            SELECT s.session_id, s.class_id, s.division_id, s.subject_id, s.teacher_id, s.session_date, s.status,
                   c.class_name, d.division_name, sub.subject_name, sub.subject_code, t.full_name AS teacher_name
            FROM attendance_sessions s
            JOIN classes c ON s.class_id = c.class_id
            JOIN divisions d ON s.division_id = d.division_id
            JOIN subjects sub ON s.subject_id = sub.subject_id
            JOIN teachers t ON s.teacher_id = t.teacher_id
            WHERE s.session_id = :sid
        ");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }

        // Fetch students in this class with their record if marked
        $studentsStmt = $pdo->prepare("
            SELECT 
                stu.student_id,
                stu.roll_number,
                stu.full_name,
                stu.email,
                stu.face_verification_status,
                r.record_id,
                r.status AS attendance_status,
                r.marked_at,
                r.verification_method,
                r.confidence_score
            FROM students stu
            LEFT JOIN attendance_records r ON stu.student_id = r.student_id AND r.session_id = :sid
            WHERE stu.class_id = :cid AND stu.division_id = :did AND stu.status = 'ACTIVE'
            ORDER BY stu.roll_number ASC
        ");
        $studentsStmt->execute([
            ':sid' => $sessionId,
            ':cid' => $session['class_id'],
            ':did' => $session['division_id']
        ]);
        $roster = $studentsStmt->fetchAll();

        Response::success([
            'session' => $session,
            'students' => $roster,
            'total_students' => count($roster),
            'marked_count' => count(array_filter($roster, fn($s) => !empty($s['attendance_status'])))
        ], 'Session roster retrieved.');
    }

    /**
     * Mark Attendance for a Single Student (AI or Manual)
     * POST /api/attendance/mark
     */
    public static function markAttendance(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('session_id', 'student_id', 'status')
            ->numeric('session_id')
            ->numeric('student_id')
            ->inArray('status', ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $sessionId = (int)$input['session_id'];
        $studentId = (int)$input['student_id'];
        $status = strtoupper($input['status']);
        $method = $input['verification_method'] ?? 'MANUAL';
        $confidence = isset($input['confidence_score']) ? (float)$input['confidence_score'] : null;

        $pdo = Database::getConnection();

        // 1. Verify session exists and is OPEN
        $sessStmt = $pdo->prepare("SELECT status FROM attendance_sessions WHERE session_id = :sid");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }
        if ($session['status'] === 'CLOSED') {
            Response::forbidden("This attendance session has been closed. Modifications require administrator override.");
        }

        // 2. DUPLICATE ATTENDANCE CHECK (Rule 42)
        $dupStmt = $pdo->prepare("SELECT record_id, status FROM attendance_records WHERE session_id = :sess AND student_id = :stu");
        $dupStmt->execute([':sess' => $sessionId, ':stu' => $studentId]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            Response::conflict("Duplicate attendance prevented: Student #{$studentId} is already recorded as '{$existing['status']}' in session #{$sessionId}. Use the manual override feature to amend.");
        }

        // 3. Insert record
        $insStmt = $pdo->prepare("
            INSERT INTO attendance_records (session_id, student_id, status, marked_at, verification_method, confidence_score, ip_address)
            VALUES (:sess, :stu, :status, CURRENT_TIMESTAMP, :method, :conf, :ip)
        ");
        $insStmt->execute([
            ':sess' => $sessionId,
            ':stu' => $studentId,
            ':status' => $status,
            ':method' => $method,
            ':conf' => $confidence,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        $recordId = (int)$pdo->lastInsertId();

        AuditService::log($user['user_id'], 'ATTENDANCE_MARKED', 'attendance_records', (string)$recordId, [
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'status' => $status,
            'method' => $method
        ]);

        Response::success([
            'record_id' => $recordId,
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'status' => $status,
            'method' => $method,
            'marked_at' => date('c')
        ], "Student attendance recorded as {$status}.");
    }

    /**
     * Batch Save / Update Full Class Attendance
     * POST /api/attendance/batch-save
     */
    public static function saveBatchAttendance(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)->required('session_id', 'records');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $sessionId = (int)$input['session_id'];
        $records = (array)$input['records'];

        if (empty($records)) {
            Response::error("No student attendance records provided in batch payload.", 'EMPTY_BATCH', 422);
        }

        $pdo = Database::getConnection();

        // Check session
        $sessStmt = $pdo->prepare("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = :sid");
        $sessStmt->execute([':sid' => $sessionId]);
        $session = $sessStmt->fetch();

        if (!$session) {
            Response::notFound("Session #{$sessionId} not found.");
        }

        if ($user['role_name'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacher_id']) {
            Response::forbidden("Access denied: You can only save attendance for your own sessions.");
        }

        if ($session['status'] === 'CLOSED') {
            Response::forbidden("This attendance session has been closed. Modifications require administrator override.");
        }

        $pdo->beginTransaction();
        try {
            $upsertSql = Database::getActiveDriver() === 'pgsql'
                ? "INSERT INTO attendance_records (session_id, student_id, status, marked_at, verification_method, ip_address)
                   VALUES (:sess, :stu, :status, CURRENT_TIMESTAMP, :method, :ip)
                   ON CONFLICT (session_id, student_id) DO UPDATE SET
                       status = EXCLUDED.status,
                       verification_method = EXCLUDED.verification_method,
                       marked_at = CURRENT_TIMESTAMP"
                : "INSERT OR REPLACE INTO attendance_records (session_id, student_id, status, marked_at, verification_method, ip_address)
                   VALUES (:sess, :stu, :status, datetime('now'), :method, :ip)";

            $stmt = $pdo->prepare($upsertSql);
            $count = 0;

            foreach ($records as $item) {
                if (isset($item['student_id'], $item['status'])) {
                    $stmt->execute([
                        ':sess' => $sessionId,
                        ':stu' => (int)$item['student_id'],
                        ':status' => strtoupper($item['status']),
                        ':method' => $item['verification_method'] ?? 'MANUAL',
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);
                    $count++;
                }
            }

            // Optionally auto-close session if requested
            if (!empty($input['close_session'])) {
                $closeStmt = $pdo->prepare("UPDATE attendance_sessions SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP WHERE session_id = :sid");
                $closeStmt->execute([':sid' => $sessionId]);
            }

            $pdo->commit();
            AuditService::log($user['user_id'], 'ATTENDANCE_BATCH_SAVED', 'attendance_sessions', (string)$sessionId, ['records_saved' => $count]);

            Response::success([
                'session_id' => $sessionId,
                'saved_records' => $count
            ], "Saved {$count} attendance records successfully.");
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error("Failed to batch save attendance: " . $e->getMessage(), 'DATABASE_ERROR', 500);
        }
    }

    /**
     * Manual Override with Mandatory Audit Reason
     * POST /api/attendance/override
     */
    public static function override(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $validator = Validator::make($input)
            ->required('record_id', 'new_status', 'reason')
            ->numeric('record_id')
            ->minLength('reason', 5)
            ->inArray('new_status', ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $recordId = (int)$input['record_id'];
        $newStatus = strtoupper($input['new_status']);
        $reason = trim($input['reason']);

        $pdo = Database::getConnection();

        // Fetch existing record
        $stmt = $pdo->prepare("SELECT record_id, session_id, student_id, status FROM attendance_records WHERE record_id = :rid");
        $stmt->execute([':rid' => $recordId]);
        $rec = $stmt->fetch();

        if (!$rec) {
            Response::notFound("Attendance record #{$recordId} not found.");
        }

        $origStatus = $rec['status'];

        $pdo->beginTransaction();
        try {
            // Update record
            $upd = $pdo->prepare("
                UPDATE attendance_records
                SET status = :status, verification_method = 'MANUAL'
                WHERE record_id = :rid
            ");
            $upd->execute([':status' => $newStatus, ':rid' => $recordId]);

            // Log into attendance_overrides
            $ovr = $pdo->prepare("
                INSERT INTO attendance_overrides (record_id, session_id, student_id, original_status, new_status, changed_by, reason)
                VALUES (:rid, :sess, :stu, :orig, :new, :by, :reason)
            ");
            $ovr->execute([
                ':rid'    => $recordId,
                ':sess'   => $rec['session_id'],
                ':stu'    => $rec['student_id'],
                ':orig'   => $origStatus,
                ':new'    => $newStatus,
                ':by'     => $user['user_id'],
                ':reason' => $reason
            ]);

            $pdo->commit();

            AuditService::log($user['user_id'], 'ATTENDANCE_OVERRIDDEN', 'attendance_records', (string)$recordId, [
                'original_status' => $origStatus,
                'new_status'      => $newStatus,
                'reason'          => $reason
            ]);

            Response::success([
                'record_id'       => $recordId,
                'original_status' => $origStatus,
                'new_status'      => $newStatus,
                'reason'          => $reason
            ], "Attendance status updated from {$origStatus} to {$newStatus}.");
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error("Failed to apply override: " . $e->getMessage(), 'DATABASE_ERROR', 500);
        }
    }

    /**
     * List All Attendance Sessions (Admin Overview)
     * GET /api/attendance/sessions
     */
    public static function listSessions(): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $classId   = !empty($_GET['class_id'])   ? (int)$_GET['class_id']   : null;
        $subjectId = !empty($_GET['subject_id'])  ? (int)$_GET['subject_id'] : null;
        $status    = $_GET['status'] ?? null;
        $page      = max(1, (int)($_GET['page']  ?? 1));
        $limit     = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset    = ($page - 1) * $limit;

        $conditions = ['1=1'];
        $params     = [];

        if ($classId !== null) {
            $conditions[] = 's.class_id = :cid';
            $params[':cid'] = $classId;
        }
        if ($subjectId !== null) {
            $conditions[] = 's.subject_id = :subid';
            $params[':subid'] = $subjectId;
        }
        if ($status !== null && in_array(strtoupper($status), ['OPEN', 'CLOSED'], true)) {
            $conditions[] = 's.status = :stat';
            $params[':stat'] = strtoupper($status);
        }

        if ($user['role_name'] === 'TEACHER') {
            $conditions[] = 's.teacher_id = :tid';
            $params[':tid'] = (int)$user['teacher_id'];
        }

        $where = implode(' AND ', $conditions);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_sessions s WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                s.session_id,
                s.session_date,
                s.start_time,
                s.end_time,
                s.lecture_number,
                s.status,
                s.verification_mode,
                c.class_name,
                c.class_code,
                d.division_name,
                sub.subject_name,
                sub.subject_code,
                t.full_name  AS teacher_name,
                t.teacher_id,
                dept.department_code,
                (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.session_id) AS marked_count
            FROM attendance_sessions s
            JOIN classes c    ON s.class_id    = c.class_id
            JOIN divisions d  ON s.division_id = d.division_id
            JOIN subjects sub ON s.subject_id  = sub.subject_id
            JOIN teachers t   ON s.teacher_id  = t.teacher_id
            JOIN departments dept ON t.department_id = dept.department_id
            WHERE {$where}
            ORDER BY s.session_date DESC, s.start_time DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $sessions = $stmt->fetchAll();

        Response::success([
            'sessions'   => $sessions,
            'pagination' => [
                'current_page'  => $page,
                'per_page'      => $limit,
                'total_records' => $total,
                'total_pages'   => (int)ceil($total / $limit)
            ]
        ], 'Sessions retrieved.');
    }

    /**
     * Close an Open Attendance Session
     * POST /api/attendance/session/{id}/close
     */
    public static function closeSession(int $sessionId): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo  = Database::getConnection();

        $stmt = $pdo->prepare("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = :sid");
        $stmt->execute([':sid' => $sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            Response::notFound("Attendance session #{$sessionId} not found.");
        }

        if ($user['role_name'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacher_id']) {
            Response::forbidden("Access denied: You can only close your own attendance sessions.");
        }

        if ($session['status'] === 'CLOSED') {
            Response::conflict("Session #{$sessionId} is already closed.");
        }

        $pdo->prepare("UPDATE attendance_sessions SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP WHERE session_id = :sid")
            ->execute([':sid' => $sessionId]);

        AuditService::log($user['user_id'], 'SESSION_CLOSED', 'attendance_sessions', (string)$sessionId);
        Response::success(['session_id' => $sessionId, 'status' => 'CLOSED'], "Session #{$sessionId} closed successfully.");
    }
}
