<?php
/**
 * SAMS - Audit Logging Service
 */

namespace SAMS\Services;

use SAMS\Config\Database;
use Exception;

class AuditService
{
    /**
     * Log an action in the audit trail
     */
    public static function log(?int $userId, string $action, string $entity, ?string $entityId = null, ?array $metadata = null): void
    {
        try {
            $pdo = Database::getConnection();
            $driver = Database::getActiveDriver();
            $metaExpr = ($driver === 'pgsql') ? 'CAST(:metadata AS jsonb)' : ':metadata';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity, entity_id, ip_address, user_agent, metadata)
                VALUES (:user_id, :action, :entity, :entity_id, :ip_address, :user_agent, {$metaExpr})
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
            $metaJson = $metadata ? json_encode($metadata) : null;

            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':entity' => $entity,
                ':entity_id' => $entityId,
                ':ip_address' => $ip,
                ':user_agent' => $ua,
                ':metadata' => $metaJson
            ]);
        } catch (Exception $e) {
            // Never break user workflow on logging failure; log locally
            error_log("[SAMS Audit Failure] " . $e->getMessage());
        }
    }
}
