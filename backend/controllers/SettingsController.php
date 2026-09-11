<?php
/**
 * SAMS - Institutional Settings Controller
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Services\AuditService;
use SAMS\Utils\Response;
use PDO;

class SettingsController
{
    /**
     * Get All Public / System Settings
     * GET /api/settings
     */
    public static function index(): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query("SELECT setting_key, setting_value, description FROM system_settings")->fetchAll();

        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }

        Response::success($settings, 'System settings retrieved.');
    }

    /**
     * Update Settings (Admin Only)
     * POST /api/settings
     */
    public static function update(): void
    {
        $admin = RoleMiddleware::adminOnly();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET setting_value = :val, updated_at = CURRENT_TIMESTAMP
            WHERE setting_key = :key
        ");

        $allowedKeys = [
            'institution_name', 'institution_code', 'academic_year',
            'attendance_threshold', 'late_grace_minutes', 'late_weight',
            'face_verification_enabled', 'liveness_required', 'manual_override_allowed'
        ];

        $updatedCount = 0;
        foreach ($input as $key => $val) {
            if (in_array($key, $allowedKeys, true)) {
                $stmt->execute([':val' => (string)$val, ':key' => $key]);
                $updatedCount++;
            }
        }

        AuditService::log($admin['user_id'], 'SETTINGS_UPDATED', 'system_settings', null, $input);
        Response::success(['updated_keys' => $updatedCount], 'Institutional settings updated successfully.');
    }
}
