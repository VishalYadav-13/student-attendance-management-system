<?php
/**
 * SAMS - Attendance Business Logic & Analytics Calculation Service
 */

namespace SAMS\Services;

use SAMS\Config\Database;
use SAMS\Config\Env;
use PDO;

class AttendanceService
{
    /**
     * Calculate percentage based on institutional rule
     */
    public static function calculatePercentage(int $present, int $late, int $total, float $lateWeight = 1.0): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $effectivePresent = $present + ($late * $lateWeight);
        $pct = ($effectivePresent / $total) * 100;
        return round(min(100.0, max(0.0, $pct)), 1);
    }

    /**
     * Get institutional attendance threshold (default 75%)
     */
    public static function getThreshold(): float
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                return (float)$val;
            }
        } catch (\Exception $e) {
            // fallback
        }
        return (float)Env::get('ATTENDANCE_THRESHOLD_PERCENT', 75.0);
    }

    /**
     * Get student overall attendance statistics
     */
    public static function getStudentStats(int $studentId): array
    {
        $pdo = Database::getConnection();
        $threshold = self::getThreshold();

        // Fetch records breakdown
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(record_id) AS total_records,
                SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN status = 'LATE' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN status = 'ABSENT' THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN status = 'EXCUSED' THEN 1 ELSE 0 END) AS excused_count
            FROM attendance_records
            WHERE student_id = :sid
        ");
        $stmt->execute([':sid' => $studentId]);
        $counts = $stmt->fetch() ?: [];

        $total = (int)($counts['total_records'] ?? 0);
        $present = (int)($counts['present_count'] ?? 0);
        $late = (int)($counts['late_count'] ?? 0);
        $absent = (int)($counts['absent_count'] ?? 0);
        $excused = (int)($counts['excused_count'] ?? 0);

        $percentage = self::calculatePercentage($present, $late, $total);
        $isLowAttendance = ($total > 0 && $percentage < $threshold);

        return [
            'total_classes' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excused' => $excused,
            'percentage' => $percentage,
            'threshold' => $threshold,
            'is_low_attendance' => $isLowAttendance,
            'status_label' => $isLowAttendance ? 'Attendance Needs Attention' : 'Good Attendance'
        ];
    }

    /**
     * Get student subject-wise attendance breakdown
     */
    public static function getStudentSubjectBreakdown(int $studentId): array
    {
        $pdo = Database::getConnection();
        $threshold = self::getThreshold();

        $stmt = $pdo->prepare("
            SELECT 
                s.subject_id,
                s.subject_code,
                s.subject_name,
                COUNT(r.record_id) AS total_classes,
                SUM(CASE WHEN r.status = 'PRESENT' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN r.status = 'LATE' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN r.status = 'ABSENT' THEN 1 ELSE 0 END) AS absent_count
            FROM subjects s
            JOIN attendance_sessions ses ON s.subject_id = ses.subject_id
            JOIN attendance_records r ON ses.session_id = r.session_id
            WHERE r.student_id = :sid
            GROUP BY s.subject_id, s.subject_code, s.subject_name
            ORDER BY s.subject_name ASC
        ");
        $stmt->execute([':sid' => $studentId]);
        $rows = $stmt->fetchAll();

        $breakdown = [];
        foreach ($rows as $r) {
            $tot = (int)$r['total_classes'];
            $prs = (int)$r['present_count'];
            $lte = (int)$r['late_count'];
            $abs = (int)$r['absent_count'];
            $pct = self::calculatePercentage($prs, $lte, $tot);

            $breakdown[] = [
                'subject_id' => (int)$r['subject_id'],
                'subject_code' => $r['subject_code'],
                'subject_name' => $r['subject_name'],
                'total_classes' => $tot,
                'present' => $prs,
                'late' => $lte,
                'absent' => $abs,
                'percentage' => $pct,
                'below_threshold' => ($pct < $threshold)
            ];
        }

        return $breakdown;
    }

    /**
     * Get list of students below the attendance threshold for Admin dashboard
     */
    public static function getLowAttendanceStudents(int $limit = 10): array
    {
        $pdo = Database::getConnection();
        $threshold = self::getThreshold();

        $sql = "
            SELECT 
                s.student_id,
                s.full_name,
                s.roll_number,
                c.class_name,
                d.division_name,
                dept.department_code,
                COUNT(r.record_id) AS total_classes,
                SUM(CASE WHEN r.status = 'PRESENT' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN r.status = 'LATE' THEN 1 ELSE 0 END) AS late_count
            FROM students s
            JOIN classes c ON s.class_id = c.class_id
            JOIN divisions d ON s.division_id = d.division_id
            JOIN departments dept ON s.department_id = dept.department_id
            JOIN attendance_records r ON s.student_id = r.student_id
            GROUP BY s.student_id, s.full_name, s.roll_number, c.class_name, d.division_name, dept.department_code
            HAVING COUNT(r.record_id) >= 5
        ";

        $stmt = $pdo->query($sql);
        $students = $stmt->fetchAll();

        $lowStudents = [];
        foreach ($students as $stu) {
            $tot = (int)$stu['total_classes'];
            $prs = (int)$stu['present_count'];
            $lte = (int)$stu['late_count'];
            $pct = self::calculatePercentage($prs, $lte, $tot);

            if ($pct < $threshold) {
                $lowStudents[] = [
                    'student_id' => (int)$stu['student_id'],
                    'full_name' => $stu['full_name'],
                    'roll_number' => $stu['roll_number'],
                    'class_name' => $stu['class_name'] . ' - Div ' . $stu['division_name'],
                    'department' => $stu['department_code'],
                    'total_classes' => $tot,
                    'percentage' => $pct,
                    'threshold' => $threshold,
                    'risk_level' => $pct < 60.0 ? 'High Risk' : 'Moderate Risk'
                ];
            }
        }

        usort($lowStudents, fn($a, $b) => $a['percentage'] <=> $b['percentage']);
        return array_slice($lowStudents, 0, $limit);
    }
}
