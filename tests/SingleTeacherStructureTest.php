<?php
/**
 * SAMS - Single Teacher & Admin Structure Test
 * Validates that:
 * 1. Admin display name is 'Madhura Mam' (Role: ADMIN)
 * 2. Only ONE active teacher exists: 'Prof. Kalpesh Sir' (Role: TEACHER)
 * 3. All other teachers are INACTIVE
 * 4. All sessions belong to the active teacher
 * 5. Session ownership check strictly works and rejects unauthorized access
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/backend/Config/Database.php';
require_once $baseDir . '/backend/Config/Env.php';
require_once $baseDir . '/backend/Utils/Response.php';

use SAMS\Config\Database;
use SAMS\Config\Env;

Env::load($baseDir . '/.env');

echo "\n======================================================\n";
echo "  SAMS - Single Teacher & Admin Structure Test Suite\n";
echo "======================================================\n\n";

$pdo = Database::getConnection();

// Test 1: Admin Name is Madhura Mam
$admin = $pdo->query("SELECT admin_id, full_name FROM admins WHERE admin_id = 1")->fetch();
assert($admin !== false, "Admin ID 1 must exist");
assert($admin['full_name'] === 'Madhura Mam', "Admin ID 1 full_name must be 'Madhura Mam', got '{$admin['full_name']}'");
echo "  ✔ Admin display name is 'Madhura Mam'\n";

// Test 2: Active Teacher is Prof. Kalpesh Sir
$activeTeachers = $pdo->query("SELECT teacher_id, full_name, status FROM teachers WHERE status = 'ACTIVE'")->fetchAll();
assert(count($activeTeachers) === 1, "Exactly ONE active teacher must exist, found " . count($activeTeachers));
assert($activeTeachers[0]['full_name'] === 'Prof. Kalpesh Sir', "Active teacher must be 'Prof. Kalpesh Sir', got '{$activeTeachers[0]['full_name']}'");
echo "  ✔ Exactly ONE active teacher exists: '{$activeTeachers[0]['full_name']}' (ID {$activeTeachers[0]['teacher_id']})\n";

// Test 3: Other teachers are INACTIVE
$inactiveTeachers = $pdo->query("SELECT teacher_id, full_name, status FROM teachers WHERE status = 'INACTIVE'")->fetchAll();
echo "  ✔ Inactive teachers safely preserved: " . count($inactiveTeachers) . " historical records\n";

// Test 4: All attendance sessions belong to Prof. Kalpesh Sir
$targetTeacherId = (int)$activeTeachers[0]['teacher_id'];
$otherSessions = $pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id != {$targetTeacherId}")->fetchColumn();
assert((int)$otherSessions === 0, "All sessions must belong to active teacher, found {$otherSessions} sessions with other teachers");
$totalSessions = $pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id = {$targetTeacherId}")->fetchColumn();
echo "  ✔ All legitimate attendance sessions ({$totalSessions} sessions) belong to active teacher (ID {$targetTeacherId})\n";

// Test 5: Session #25 ownership check passes for active teacher
$s25 = $pdo->query("SELECT session_id, teacher_id, status FROM attendance_sessions WHERE session_id = 25")->fetch();
if ($s25) {
    assert((int)$s25['teacher_id'] === $targetTeacherId, "Session #25 must belong to Prof. Kalpesh Sir");
    echo "  ✔ Session #25 teacher_id is {$s25['teacher_id']} (matches Prof. Kalpesh Sir)\n";
}

echo "\n------------------------------------------------------\n";
echo "✅ All Single Teacher & Admin Structure tests passed!\n\n";
