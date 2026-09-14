<?php
/**
 * SAMS - Audit Logs Controller
 * Exposes the immutable audit trail to ADMIN users.
 */

namespace SAMS\Controllers;

use SAMS\Config\Database;
use SAMS\Middleware\RoleMiddleware;
use SAMS\Utils\Response;
use PDO;

class AuditController
{
    /**
     * Paginated Audit Log Listing
     * GET /api/audit-logs
     * Query params: page, per_page, action, entity, user_id, start_date, end_date, search
     */
    public static function index(): void
    {
        RoleMiddleware::authorize(['ADMIN']);
        $pdo = Database::getConnection();

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $conditions = ['1=1'];
        $params = [];

        if (!empty($_GET['action'])) {
            $conditions[] = 'al.action = :action';
            $params[':action'] = strtoupper(trim($_GET['action']));
        }
        if (!empty($_GET['entity'])) {
            $conditions[] = 'al.entity = :entity';
            $params[':entity'] = strtoupper(trim($_GET['entity']));
        }
        if (!empty($_GET['user_id'])) {
            $conditions[] = 'al.user_id = :uid';
            $params[':uid'] = (int)$_GET['user_id'];
        }
        if (!empty($_GET['start_date'])) {
            $conditions[] = 'DATE(al.created_at) >= :start_date';
            $params[':start_date'] = $_GET['start_date'];
        }
        if (!empty($_GET['end_date'])) {
            $conditions[] = 'DATE(al.created_at) <= :end_date';
            $params[':end_date'] = $_GET['end_date'];
        }
        if (!empty($_GET['search'])) {
            $conditions[] = '(al.action LIKE :search OR al.entity LIKE :search OR al.entity_id LIKE :search OR al.ip_address LIKE :search)';
            $params[':search'] = '%' . $_GET['search'] . '%';
        }

        $where = implode(' AND ', $conditions);

        // Total count for pagination
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Paginated records with user info join
        $sql = "
            SELECT
                al.log_id,
                al.user_id,
                COALESCE(adm.full_name, t.full_name, stu.full_name, 'System') AS actor_name,
                COALESCE(u.email, 'system') AS actor_email,
                u.role_id,
                r.role_name,
                al.action,
                al.entity,
                al.entity_id,
                al.ip_address,
                al.user_agent,
                al.metadata,
                al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN admins adm ON u.user_id = adm.user_id
            LEFT JOIN teachers t ON u.user_id = t.user_id
            LEFT JOIN students stu ON u.user_id = stu.user_id
            WHERE {$where}
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $logs = array_map(function ($row) {
            return [
                'log_id'       => (int)$row['log_id'],
                'user_id'      => $row['user_id'] ? (int)$row['user_id'] : null,
                'actor_name'   => $row['actor_name'],
                'actor_email'  => $row['actor_email'],
                'role'         => $row['role_name'] ?? 'SYSTEM',
                'action'       => $row['action'],
                'entity'       => $row['entity'],
                'entity_id'    => $row['entity_id'],
                'ip_address'   => $row['ip_address'],
                'user_agent'   => $row['user_agent'],
                'metadata'     => $row['metadata'] ? json_decode($row['metadata'], true) : null,
                'created_at'   => $row['created_at'],
            ];
        }, $rows);

        // Aggregated stats for the summary bar
        $statsStmt = $pdo->query("
            SELECT action, COUNT(*) AS cnt
            FROM audit_logs
            GROUP BY action
            ORDER BY cnt DESC
            LIMIT 10
        ");
        $actionCounts = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        Response::success([
            'logs'         => $logs,
            'pagination'   => [
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'total_pages'=> (int)ceil($total / $perPage),
            ],
            'action_counts' => $actionCounts,
        ], 'Audit logs retrieved.');
    }

    /**
     * Distinct action types for filter dropdown
     * GET /api/audit-logs/actions
     */
    public static function actions(): void
    {
        RoleMiddleware::authorize(['ADMIN']);
        $pdo = Database::getConnection();

        $stmt = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
        $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        Response::success($actions, 'Distinct audit actions.');
    }
}
