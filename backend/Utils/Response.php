<?php
/**
 * SAMS - Standardized JSON Response Utility
 */

namespace SAMS\Utils;

class Response
{
    private static bool $testMode = false;
    private static ?array $lastResponse = null;

    public static function enableTestMode(): void
    {
        self::$testMode = true;
    }

    public static function disableTestMode(): void
    {
        self::$testMode = false;
    }

    public static function getLastResponse(): ?array
    {
        return self::$lastResponse;
    }

    /**
     * Send standard JSON response and exit (or capture in test mode)
     */
    public static function json(
        bool $success,
        mixed $data = null,
        string $message = '',
        int $statusCode = 200,
        ?array $error = null
    ): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ];

        if (!$success && $error !== null) {
            $payload['error'] = $error;
        }

        self::$lastResponse = [
            'status_code' => $statusCode,
            'payload' => $payload,
            'data' => $data ?? ($error['details'] ?? null),
            'message' => $message,
            'error' => $error,
            'success' => $success
        ];

        if (self::$testMode) {
            return;
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Operation successful', int $code = 200): void
    {
        self::json(true, $data, $message, $code);
    }

    public static function error(string $message, string $code = 'INTERNAL_ERROR', int $statusCode = 400, mixed $details = null): void
    {
        $err = [
            'code' => $code,
            'message' => $message
        ];
        if ($details !== null) {
            $err['details'] = $details;
        }
        self::json(false, null, $message, $statusCode, $err);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    public static function forbidden(string $message = 'Forbidden: Access denied'): void
    {
        self::error($message, 'FORBIDDEN', 403);
    }

    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 'NOT_FOUND', 404);
    }

    public static function conflict(string $message = 'Conflict detected'): void
    {
        self::error($message, 'CONFLICT', 409);
    }

    public static function validationError(array $errors, string $message = 'Validation failed'): void
    {
        self::error($message, 'VALIDATION_ERROR', 422, $errors);
    }
}
