<?php
/**
 * SAMS - Role-Based Access Control (RBAC) Middleware
 */

namespace SAMS\Middleware;

use SAMS\Utils\Response;

class RoleMiddleware
{
    /**
     * Ensure current user belongs to one of the authorized roles
     */
    public static function authorize(array $allowedRoles): array
    {
        $user = AuthMiddleware::authenticate();

        $userRole = strtoupper($user['role_name'] ?? '');
        $allowed = array_map('strtoupper', $allowedRoles);

        if (!in_array($userRole, $allowed, true)) {
            Response::forbidden("Access denied. Required role: [" . implode(', ', $allowed) . "], but your account role is '{$userRole}'.");
        }

        return $user;
    }

    public static function adminOnly(): array
    {
        return self::authorize(['ADMIN']);
    }

    public static function teacherOnly(): array
    {
        return self::authorize(['TEACHER']);
    }

    public static function studentOnly(): array
    {
        return self::authorize(['STUDENT']);
    }

    public static function staffOnly(): array
    {
        return self::authorize(['ADMIN', 'TEACHER']);
    }
}
