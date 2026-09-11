<?php
/**
 * SAMS - Cross-Site Request Forgery (CSRF) Protection Middleware
 */

namespace SAMS\Middleware;

use SAMS\Utils\Response;

class CsrfMiddleware
{
    /**
     * Generate or fetch current session CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token for state-changing HTTP methods (POST, PUT, DELETE)
     * Skipped when using Bearer JWT tokens in REST API calls
     */
    public static function verify(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Check if authorization header exists (Bearer token requests are CSRF immune)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with(trim($authHeader), 'Bearer ')) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf_token'] ?? null;

        if (!$sessionToken || !$submittedToken || !hash_equals($sessionToken, $submittedToken)) {
            Response::error('CSRF token validation failed or token expired.', 'CSRF_MISMATCH', 403);
        }
    }
}
