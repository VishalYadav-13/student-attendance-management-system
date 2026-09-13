<?php
/**
 * SAMS - Reports & CSV Export Controller
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\AttendanceService;
use SAMS\Services\GeminiService;
use SAMS\Utils\Response;
use PDO;

class ReportController
{
    /**
     * Filterable Attendance Report
     * GET /api/reports/attendance
     */
    public static function attendance(): void
    {
        $user = RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $classId = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $subjectId = !empty($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
        $deptId = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
        $startDate = $_GET['start_date'] ?? date('Y-m-01'); // 1st of month
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $conditions = ["s.session_date BETWEEN :start AND :end"];
        $params = [':start' => $startDate, ':end' => $endDate];

        if ($classId !== null) {
            $conditions[] = "s.class_id = :cid";
            $params[':cid'] = $classId;
        }
        if ($subjectId !== null) {
            $conditions[] = "s.subject_id = :subid";
            $params[':subid'] = $subjectId;
        }
        if ($deptId !== null) {
            $conditions[] = "stu.department_id = :dept";
            $params[':dept'] = $deptId;
        }

        $where = implode(' AND ', $conditions);

        $sql = "
            SELECT 
                stu.student_id,
                stu.roll_number,
                stu.full_name,
                c.class_name,
                d.division_name,
                dept.department_code,
                COUNT(r.record_id) AS total_sessions,
                SUM(CASE WHEN r.status = 'PRESENT' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN r.status = 'LATE' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN r.status = 'ABSENT' THEN 1 ELSE 0 END) AS absent_count
            FROM students stu
            JOIN classes c ON stu.class_id = c.class_id
            JOIN divisions d ON stu.division_id = d.division_id
            JOIN departments dept ON stu.department_id = dept.department_id
            LEFT JOIN attendance_records r ON stu.student_id = r.student_id
            LEFT JOIN attendance_sessions s ON r.session_id = s.session_id AND ({$where})
            WHERE stu.status = 'ACTIVE'
            GROUP BY stu.student_id, stu.roll_number, stu.full_name, c.class_name, d.division_name, dept.department_code
            ORDER BY stu.roll_number ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        $threshold = AttendanceService::getThreshold();
        $reportData = [];

        foreach ($records as $row) {
            $tot = (int)$row['total_sessions'];
            $prs = (int)$row['present_count'];
            $lte = (int)$row['late_count'];
            $pct = AttendanceService::calculatePercentage($prs, $lte, $tot);

            $reportData[] = [
                'student_id' => (int)$row['student_id'],
                'roll_number' => $row['roll_number'],
                'full_name' => $row['full_name'],
                'class' => $row['class_name'] . ' (' . $row['division_name'] . ')',
                'department' => $row['department_code'],
                'total_conducted' => $tot,
                'present' => $prs,
                'late' => $lte,
                'absent' => (int)$row['absent_count'],
                'percentage' => $pct,
                'is_low' => ($tot > 0 && $pct < $threshold)
            ];
        }

        Response::success([
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'threshold' => $threshold
            ],
            'summary' => [
                'total_students' => count($reportData),
                'low_attendance_students' => count(array_filter($reportData, fn($x) => $x['is_low']))
            ],
            'records' => $reportData
        ], 'Attendance report calculated.');
    }

    /**
     * Download Attendance Report as CSV
     * GET /api/reports/export-csv
     */
    public static function exportCsv(): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT 
                stu.roll_number,
                stu.full_name,
                c.class_name,
                d.division_name,
                dept.department_name,
                COUNT(r.record_id) AS total,
                SUM(CASE WHEN r.status = 'PRESENT' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN r.status = 'LATE' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN r.status = 'ABSENT' THEN 1 ELSE 0 END) AS absent
            FROM students stu
            JOIN classes c ON stu.class_id = c.class_id
            JOIN divisions d ON stu.division_id = d.division_id
            JOIN departments dept ON stu.department_id = dept.department_id
            LEFT JOIN attendance_records r ON stu.student_id = r.student_id
            LEFT JOIN attendance_sessions s ON r.session_id = s.session_id AND (s.session_date BETWEEN :start AND :end)
            WHERE stu.status = 'ACTIVE'
            GROUP BY stu.student_id, stu.roll_number, stu.full_name, c.class_name, d.division_name, dept.department_name
            ORDER BY stu.roll_number ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=SAMS_Attendance_Report_' . date('Ymd_His') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Institution', 'Demo Polytechnic Institute']);
        fputcsv($output, ['Date Range', "{$startDate} to {$endDate}"]);
        fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
        fputcsv($output, []); // Empty row

        // Table Header
        fputcsv($output, ['Roll Number', 'Full Name', 'Class', 'Division', 'Department', 'Total Lectures', 'Present', 'Late', 'Absent', 'Attendance %', 'Status']);

        $threshold = AttendanceService::getThreshold();
        foreach ($rows as $r) {
            $tot = (int)$r['total'];
            $prs = (int)$r['present'];
            $lte = (int)$r['late'];
            $abs = (int)$r['absent'];
            $pct = AttendanceService::calculatePercentage($prs, $lte, $tot);
            $status = ($tot > 0 && $pct < $threshold) ? 'DEFICIT (<75%)' : 'REGULAR';

            fputcsv($output, [
                $r['roll_number'],
                $r['full_name'],
                $r['class_name'],
                $r['division_name'],
                $r['department_name'],
                $tot,
                $prs,
                $lte,
                $abs,
                $pct . '%',
                $status
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Generate On-Demand Natural Language AI Insights via Gemini
     * GET /api/reports/ai-insights
     */
    public static function aiInsights(): void
    {
        RoleMiddleware::authorize(['ADMIN', 'TEACHER']);
        $pdo = Database::getConnection();

        $avgStmt = $pdo->query("
            SELECT 
                COUNT(record_id) AS total,
                SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN status = 'LATE' THEN 1 ELSE 0 END) AS late
            FROM attendance_records
        ");
        $avgData = $avgStmt->fetch();
        $avgPct = AttendanceService::calculatePercentage((int)$avgData['present'], (int)$avgData['late'], (int)$avgData['total']);
        $lowList = AttendanceService::getLowAttendanceStudents(10);

        $insights = GeminiService::generateAttendanceInsights([
            'average_attendance' => $avgPct,
            'low_attendance_count' => count($lowList),
            'total_students' => (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='ACTIVE'")->fetchColumn(),
            'period' => date('F Y')
        ]);

        Response::success($insights, 'AI Attendance Insights generated.');
    }
}
