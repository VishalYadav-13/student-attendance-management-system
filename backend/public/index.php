<?php
/**
 * SAMS - Main Front Controller & REST API Router
 * Dispatches API requests, enforces CORS policies, and handles exceptions safely.
 */

// Error reporting configuration — never display PHP errors to client
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoload SAMS classes
spl_autoload_register(function ($class) {
    $prefix = 'SAMS\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use SAMS\Config\Env;
use SAMS\Config\Database;
use SAMS\Utils\Response;
use SAMS\Controllers\AuthController;
use SAMS\Controllers\DashboardController;
use SAMS\Controllers\StudentController;
use SAMS\Controllers\TeacherController;
use SAMS\Controllers\AttendanceController;
use SAMS\Controllers\FaceController;
use SAMS\Controllers\ReportController;
use SAMS\Controllers\SettingsController;
use SAMS\Controllers\AuditController;

// Load environment variables
Env::load();

// Global Exception Handler
set_exception_handler(function (Throwable $e) {
    error_log("[SAMS Fatal Exception] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $debug = (bool)Env::get('APP_DEBUG', false);
    Response::error(
        $debug ? $e->getMessage() : 'An internal system error occurred. Please try again later.',
        'SERVER_ERROR',
        500,
        $debug ? ['file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
});

// Configure CORS — strictly enforce origin whitelist with intelligent Vercel/localhost matching
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginsRaw = (string)Env::get(
    'CORS_ALLOWED_ORIGINS',
    'https://studentattendance-management-system.vercel.app,https://student-attendance-management-system.vercel.app,http://localhost:8000,http://localhost:3000,http://127.0.0.1:8000'
);
$allowedOrigins = array_map('trim', explode(',', $allowedOriginsRaw));

$isAllowed = false;
if ($origin) {
    if (in_array($origin, $allowedOrigins, true)) {
        $isAllowed = true;
    } elseif (preg_match('#^https://([a-z0-9-]+\.)?vercel\.app$#i', $origin)) {
        // Automatically allow any Vercel production or preview branch deployments of this app
        $isAllowed = true;
    } elseif (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)) {
        $isAllowed = true;
    }
}

if ($isAllowed) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");

// Security Headers for all API responses
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(self), microphone=(), geolocation=()");
// Content-Security-Policy for API — no rendering context needed
header("Content-Security-Policy: default-src 'none'");
// HSTS — only active in production over HTTPS
if (Env::get('APP_ENV') === 'production') {
    header("Strict-Transport-Security: max-age=63072000; includeSubDomains; preload");
}

// Preflight CORS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parse request URI and method
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Allow local PHP built-in server to serve static assets directly if accessed via /frontend/
if (php_sapi_name() === 'cli-server') {
    $publicFilePath = dirname(__DIR__, 2) . $requestUri;
    if (is_file($publicFilePath)) {
        return false; // Let built-in server serve static file
    }
}

// Normalize API Path
// If path doesn't start with /api, check if user requested root or front page
if (!str_starts_with($requestUri, '/api')) {
    // If accessed via web root, redirect to landing page
    if ($requestUri === '/' || $requestUri === '/index.php') {
        header('Location: /frontend/index.html');
        exit;
    }
    Response::notFound("Endpoint not found: {$requestUri}");
}

// ---------------------------------------------------------------------
// REST API ROUTE DEFINITIONS
// ---------------------------------------------------------------------

// 0. Health Check
if ($requestMethod === 'GET' && $requestUri === '/api/health') {
    $dbStatus = 'disconnected';
    $dbDriver = Database::getActiveDriver();
    $dbError = null;
    try {
        $pdo = Database::getConnection();
        $dbDriver = Database::getActiveDriver();
        $dbStatus = 'connected (' . $dbDriver . ')';
    } catch (\Throwable $e) {
        $dbStatus = 'error';
        $dbError = $e->getMessage();
    }

    Response::success([
        'status' => $dbStatus === 'error' ? 'degraded' : 'healthy',
        'database' => $dbStatus,
        'driver' => $dbDriver,
        'app_env' => Env::get('APP_ENV', 'development'),
        'timestamp' => date('c')
    ], $dbStatus === 'error' ? "Database note: {$dbError}" : 'SAMS API is operating normally.');
}

// 1. Authentication
if ($requestMethod === 'POST' && $requestUri === '/api/auth/login') {
    AuthController::login();
}
if ($requestMethod === 'POST' && $requestUri === '/api/auth/logout') {
    AuthController::logout();
}
if ($requestMethod === 'GET' && $requestUri === '/api/auth/me') {
    AuthController::me();
}
if ($requestMethod === 'POST' && $requestUri === '/api/auth/forgot-password') {
    AuthController::forgotPassword();
}

// 2. Dashboards
if ($requestMethod === 'GET' && $requestUri === '/api/dashboard/admin') {
    DashboardController::admin();
}
if ($requestMethod === 'GET' && $requestUri === '/api/dashboard/teacher') {
    DashboardController::teacher();
}
if ($requestMethod === 'GET' && $requestUri === '/api/dashboard/student') {
    DashboardController::student();
}

// 3. Students
if ($requestMethod === 'GET' && $requestUri === '/api/students') {
    StudentController::index();
}
if ($requestMethod === 'POST' && $requestUri === '/api/students') {
    StudentController::store();
}
if (preg_match('#^/api/students/(\d+)/calendar$#', $requestUri, $m)) {
    $studentId = (int)$m[1];
    if ($requestMethod === 'GET') {
        StudentController::calendar($studentId);
    }
}
if (preg_match('#^/api/students/(\d+)$#', $requestUri, $m)) {
    $studentId = (int)$m[1];
    if ($requestMethod === 'GET') {
        StudentController::show($studentId);
    } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
        StudentController::update($studentId);
    } elseif ($requestMethod === 'DELETE') {
        StudentController::destroy($studentId);
    }
}

// 4. Teachers
if ($requestMethod === 'GET' && $requestUri === '/api/teachers') {
    TeacherController::index();
}
if ($requestMethod === 'POST' && $requestUri === '/api/teachers') {
    TeacherController::store();
}

// 5. Attendance Operations
if ($requestMethod === 'GET' && $requestUri === '/api/attendance/sessions') {
    AttendanceController::listSessions();
}
if ($requestMethod === 'POST' && $requestUri === '/api/attendance/session') {
    AttendanceController::createSession();
}
if (preg_match('#^/api/attendance/session/(\d+)/students$#', $requestUri, $m)) {
    if ($requestMethod === 'GET') {
        AttendanceController::getSessionStudents((int)$m[1]);
    }
}
if (preg_match('#^/api/attendance/session/(\d+)/close$#', $requestUri, $m)) {
    if ($requestMethod === 'POST') {
        AttendanceController::closeSession((int)$m[1]);
    }
}
if ($requestMethod === 'POST' && $requestUri === '/api/attendance/mark') {
    AttendanceController::markAttendance();
}
if ($requestMethod === 'POST' && $requestUri === '/api/attendance/batch-save') {
    AttendanceController::saveBatchAttendance();
}
if ($requestMethod === 'POST' && $requestUri === '/api/attendance/override') {
    AttendanceController::override();
}

// 6. Face Verification & Biometrics
if ($requestMethod === 'POST' && $requestUri === '/api/face/quality-check') {
    FaceController::checkQuality();
}
if ($requestMethod === 'POST' && $requestUri === '/api/face/verify') {
    FaceController::verify();
}
if ($requestMethod === 'POST' && $requestUri === '/api/face/enroll') {
    FaceController::enroll();
}
if (preg_match('#^/api/face/(\d+)$#', $requestUri, $m)) {
    if ($requestMethod === 'DELETE') {
        FaceController::deleteBiometrics((int)$m[1]);
    }
}

// 7. Reports & Analytics
if ($requestMethod === 'GET' && $requestUri === '/api/reports/attendance') {
    ReportController::attendance();
}
if ($requestMethod === 'GET' && $requestUri === '/api/reports/export-csv') {
    ReportController::exportCsv();
}
if ($requestMethod === 'GET' && $requestUri === '/api/reports/ai-insights') {
    ReportController::aiInsights();
}

// 8. Institutional Settings
if ($requestMethod === 'GET' && $requestUri === '/api/settings') {
    SettingsController::index();
}
if ($requestMethod === 'POST' && $requestUri === '/api/settings') {
    SettingsController::update();
}

// 9. Audit Logs (Admin only)
if ($requestMethod === 'GET' && $requestUri === '/api/audit-logs') {
    AuditController::index();
}
if ($requestMethod === 'GET' && $requestUri === '/api/audit-logs/actions') {
    AuditController::actions();
}

// Default 404 Route
Response::notFound("Endpoint {$requestMethod} {$requestUri} does not exist.");
