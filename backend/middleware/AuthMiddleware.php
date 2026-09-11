<?php
/**
 * SAMS - Authentication Middleware & JWT / Session Handler
 */

namespace SAMS\Middleware;

use SAMS\Config\Database;
use SAMS\Config\Env;
use SAMS\Utils\Response;
use PDO;
use Exception;

class AuthMiddleware
{
    private static ?array $authenticatedUser = null;

    /**
     * Generate secure JWT token
     */
    public static function generateToken(array $payload): string
    {
        Env::load();
        $secret = Env::get('JWT_SECRET', 'default_sams_secret_change_me_in_production_12345');
        $expiryHours = (int)Env::get('TOKEN_EXPIRY_HOURS', 24);

        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + ($expiryHours * 3600);
        $encodedPayload = base64_encode(json_encode($payload));

        $signature = hash_hmac('sha256', "{$header}.{$encodedPayload}", $secret, true);
        $encodedSignature = base64_encode($signature);

        return "{$header}.{$encodedPayload}.{$encodedSignature}";
    }

    /**
     * Validate JWT token string
     */
    public static function validateToken(string $token): ?array
    {
        Env::load();
        $secret = Env::get('JWT_SECRET', 'default_sams_secret_change_me_in_production_12345');

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $signature64] = $parts;
        $expectedSignature = base64_encode(hash_hmac('sha256', "{$header64}.{$payload64}", $secret, true));

        if (!hash_equals($expectedSignature, $signature64)) {
            return null;
        }

        $payload = json_decode(base64_decode($payload64), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expired or invalid payload
        }

        return $payload;
    }

    /**
     * Require authentication - rejects unauthenticated requests with 401
     */
    public static function authenticate(): array
    {
        if (self::$authenticatedUser !== null) {
            return self::$authenticatedUser;
        }

        $token = self::extractBearerToken();
        $userId = null;

        if ($token !== null) {
            $payload = self::validateToken($token);
            if ($payload !== null && isset($payload['user_id'])) {
                $userId = (int)$payload['user_id'];
            }
        }

        // Fallback to PHP Session if Bearer token not supplied
        if ($userId === null && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }

        if ($userId === null) {
            Response::unauthorized('Authentication required. Please provide a valid Bearer token or active session.');
        }

        // Lookup user in DB
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.status, u.role_id, r.role_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.user_id = :uid AND u.status = 'ACTIVE'
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::unauthorized('User account not found, deactivated, or session has expired.');
        }

        // Enrich with role profile data
        $roleName = $user['role_name'];
        if ($roleName === 'ADMIN') {
            $sub = $pdo->prepare("SELECT admin_id, full_name, designation FROM admins WHERE user_id = :uid");
            $sub->execute([':uid' => $userId]);
            $profile = $sub->fetch() ?: [];
            $user = array_merge($user, $profile);
        } elseif ($roleName === 'TEACHER') {
            $sub = $pdo->prepare("
                SELECT t.teacher_id, t.employee_id, t.full_name, t.designation, t.department_id, d.department_name
                FROM teachers t
                LEFT JOIN departments d ON t.department_id = d.department_id
                WHERE t.user_id = :uid
            ");
            $sub->execute([':uid' => $userId]);
            $profile = $sub->fetch() ?: [];
            $user = array_merge($user, $profile);
        } elseif ($roleName === 'STUDENT') {
            $sub = $pdo->prepare("
                SELECT s.student_id, s.roll_number, s.student_uid, s.full_name, s.department_id, s.class_id, s.division_id,
                       s.face_verification_status, c.class_name, d.division_name, dept.department_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN divisions d ON s.division_id = d.division_id
                LEFT JOIN departments dept ON s.department_id = dept.department_id
                WHERE s.user_id = :uid
            ");
            $sub->execute([':uid' => $userId]);
            $profile = $sub->fetch() ?: [];
            $user = array_merge($user, $profile);
        }

        self::$authenticatedUser = $user;
        return $user;
    }

    public static function user(): ?array
    {
        return self::$authenticatedUser;
    }

    private static function extractBearerToken(): ?string
    {
        $header = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        if ($header && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
