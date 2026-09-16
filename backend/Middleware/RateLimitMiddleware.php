<?php
/**
 * SAMS - Rate Limiting & Brute-Force Protection Middleware
 */

namespace SAMS\Middleware;

use SAMS\Utils\Response;

class RateLimitMiddleware
{
    private static string $storageDir = '';

    private static function initStorage(): void
    {
        if (self::$storageDir === '') {
            self::$storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ratelimit';
            if (!is_dir(self::$storageDir)) {
                @mkdir(self::$storageDir, 0777, true);
            }
        }
    }

    /**
     * Rate limit a specific key (e.g. IP + endpoint)
     * @param string $key Identifier for rate limiting
     * @param int $maxAttempts Maximum allowed requests in window
     * @param int $decaySeconds Window length in seconds
     */
    public static function check(string $key, int $maxAttempts = 10, int $decaySeconds = 60): void
    {
        self::initStorage();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $safeHash = md5($ip . '_' . $key);
        $file = self::$storageDir . DIRECTORY_SEPARATOR . $safeHash . '.json';

        $now = time();
        $record = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];

        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if ($data && isset($data['reset_at']) && $data['reset_at'] > $now) {
                $record = $data;
            }
        }

        if ($record['attempts'] >= $maxAttempts) {
            $wait = $record['reset_at'] - $now;
            Response::error("Too many requests. Please wait {$wait} seconds before trying again.", 'RATE_LIMIT_EXCEEDED', 429, [
                'retry_after' => $wait
            ]);
        }

        $record['attempts']++;
        @file_put_contents($file, json_encode($record), LOCK_EX);
    }
}
