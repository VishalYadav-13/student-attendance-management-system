<?php
/**
 * SAMS - Environment Configuration Loader
 * Safely loads key-value pairs from .env or system environment variables.
 */

namespace SAMS\Config;

class Env
{
    private static ?array $variables = null;

    /**
     * Load .env file if it exists
     */
    public static function load(?string $path = null): void
    {
        if (self::$variables !== null) {
            return;
        }

        self::$variables = [];

        if ($path === null) {
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        }

        if (file_exists($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $val = trim($parts[1]);

                    // Strip optional quotes
                    if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                        (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                        $val = substr($val, 1, -1);
                    }

                    self::$variables[$key] = $val;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $val;
                    }
                    if (!isset($_SERVER[$key])) {
                        $_SERVER[$key] = $val;
                    }
                }
            }
        }
    }

    /**
     * Retrieve environment variable with default fallback
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$variables === null) {
            self::load();
        }

        if (isset($_ENV[$key])) {
            return self::castValue($_ENV[$key]);
        }

        if (isset($_SERVER[$key])) {
            return self::castValue($_SERVER[$key]);
        }

        if (isset(self::$variables[$key])) {
            return self::castValue(self::$variables[$key]);
        }

        $envVal = getenv($key);
        if ($envVal !== false) {
            return self::castValue($envVal);
        }

        return $default;
    }

    private static function castValue(mixed $val): mixed
    {
        if (!is_string($val)) {
            return $val;
        }
        $lower = strtolower($val);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
        if (is_numeric($val) && !str_starts_with($val, '0')) {
            return str_contains($val, '.') ? (float)$val : (int)$val;
        }
        return $val;
    }
}
