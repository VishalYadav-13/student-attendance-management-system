<?php
/**
 * SAMS - Exadel CompreFace Service Wrapper
 * Securely communicates server-side with CompreFace REST API.
 * Never exposes the CompreFace API key, URL, or internal biometric models to the browser.
 */

namespace SAMS\Services;

use SAMS\Config\Env;
use SAMS\Config\Database;
use Exception;

class CompreFaceService
{
    public const DEFAULT_URL = 'http://localhost:8000';
    public const DEFAULT_SIMILARITY_THRESHOLD = 0.80;
    public const SUBJECT_PREFIX = 'SAMS_STUDENT_';

    /**
     * Optional mock handler for isolated automated unit tests
     * @var callable|null
     */
    private static $mockHandler = null;

    /**
     * Get configured CompreFace Service Base URL
     */
    public static function getBaseUrl(): string
    {
        $url = (string)Env::get('COMPREFACE_URL', self::DEFAULT_URL);
        return rtrim($url ?: self::DEFAULT_URL, '/');
    }

    /**
     * Get configured CompreFace API Key
     */
    public static function getApiKey(): string
    {
        return (string)Env::get('COMPREFACE_API_KEY', '');
    }

    /**
     * Check if CompreFace server credentials are configured
     */
    public static function isConfigured(): bool
    {
        return !empty(self::getApiKey());
    }

    /**
     * Check if a mock handler is active for testing
     */
    public static function hasMockHandler(): bool
    {
        return self::$mockHandler !== null;
    }

    /**
     * Check if CompreFace is available (either configured with real credentials or mocked)
     */
    public static function isAvailable(): bool
    {
        return self::isConfigured() || self::hasMockHandler();
    }

    /**
     * Get configured similarity threshold (from system_settings or Env)
     */
    public static function getSimilarityThreshold(): float
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'face_similarity_threshold'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                return (float)$val;
            }
        } catch (\Throwable $t) {}

        $envVal = Env::get('FACE_SIMILARITY_THRESHOLD');
        if ($envVal !== null && is_numeric($envVal)) {
            return (float)$envVal;
        }

        return self::DEFAULT_SIMILARITY_THRESHOLD;
    }

    /**
     * Format standardized student subject identity: SAMS_STUDENT_<student_id>
     */
    public static function formatSubject(int $studentId): string
    {
        return self::SUBJECT_PREFIX . $studentId;
    }

    /**
     * Parse student_id from CompreFace subject string
     */
    public static function parseSubject(?string $subject): ?int
    {
        if (empty($subject)) {
            return null;
        }

        if (preg_match('/^' . preg_quote(self::SUBJECT_PREFIX, '/') . '(\d+)$/', trim($subject), $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Set a custom mock handler (used exclusively for automated unit test suites)
     */
    public static function setMockHandler(?callable $handler): void
    {
        self::$mockHandler = $handler;
    }

    /**
     * Check CompreFace server connectivity
     */
    public static function checkHealth(): bool
    {
        if (self::$mockHandler !== null) {
            return true;
        }

        if (!self::isConfigured()) {
            return false;
        }

        try {
            $res = self::request('GET', '/api/v1/recognition/subjects', null, 2);
            return $res['http_code'] >= 200 && $res['http_code'] < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Add face sample example to a CompreFace subject
     * POST /api/v1/recognition/faces?subject={subject}
     *
     * @param string $subject Standardized subject, e.g. SAMS_STUDENT_1042
     * @param string $base64Image Raw base64 or Data URL image
     */
    public static function addSubjectExample(string $subject, string $base64Image): array
    {
        $cleanBase64 = self::cleanBase64($base64Image);
        if (empty($cleanBase64)) {
            return [
                'success' => false,
                'error_code' => 'INVALID_IMAGE',
                'message' => 'Invalid or empty camera image frame provided.'
            ];
        }

        if (self::$mockHandler !== null) {
            $mockResponse = (self::$mockHandler)('addSubjectExample', [
                'subject' => $subject,
                'image' => $cleanBase64
            ]);
            if ($mockResponse !== null) {
                return $mockResponse;
            }
        }

        $endpoint = '/api/v1/recognition/faces?subject=' . urlencode($subject);
        $res = self::request('POST', $endpoint, ['file' => $cleanBase64]);

        if ($res['http_code'] === 201 || $res['http_code'] === 200) {
            return [
                'success' => true,
                'image_id' => $res['body']['image_id'] ?? null,
                'subject' => $res['body']['subject'] ?? $subject
            ];
        }

        $msg = $res['body']['message'] ?? 'Failed to upload face example to CompreFace.';
        $errorCode = 'ENROLLMENT_FAILED';

        if (stripos($msg, 'No face is found') !== false || stripos($msg, 'no face') !== false) {
            $errorCode = 'NO_FACE';
            $msg = 'No face detected in the captured image. Please ensure your face is clearly visible.';
        } elseif (stripos($msg, 'More than one face') !== false || stripos($msg, 'multiple face') !== false) {
            $errorCode = 'MULTIPLE_FACES';
            $msg = 'Multiple faces detected. Please ensure only one student is in front of the camera.';
        }

        return [
            'success' => false,
            'error_code' => $errorCode,
            'message' => $msg,
            'http_code' => $res['http_code']
        ];
    }

    /**
     * Recognize faces in an image against enrolled subjects
     * POST /api/v1/recognition/recognize?limit=1&prediction_count=1
     *
     * @param string $base64Image Captured video frame
     * @param float|null $threshold Custom similarity threshold (optional)
     */
    public static function recognize(string $base64Image, ?float $threshold = null): array
    {
        $cleanBase64 = self::cleanBase64($base64Image);
        if (empty($cleanBase64)) {
            return [
                'success' => false,
                'error_code' => 'INVALID_IMAGE',
                'message' => 'Invalid or empty camera frame provided.'
            ];
        }

        $requiredThreshold = $threshold ?? self::getSimilarityThreshold();

        if (self::$mockHandler !== null) {
            $mockResponse = (self::$mockHandler)('recognize', [
                'image' => $cleanBase64,
                'threshold' => $requiredThreshold
            ]);
            if ($mockResponse !== null) {
                return $mockResponse;
            }
        }

        $endpoint = '/api/v1/recognition/recognize?limit=1&prediction_count=1';
        $res = self::request('POST', $endpoint, ['file' => $cleanBase64]);

        if ($res['http_code'] !== 200) {
            if ($res['http_code'] === 0 || $res['http_code'] >= 500) {
                return [
                    'success' => false,
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'Face recognition service unavailable. Please contact the administrator or use manual attendance.'
                ];
            }

            $errMsg = $res['body']['message'] ?? 'Face recognition service could not process image.';
            return [
                'success' => false,
                'error_code' => 'RECOGNITION_ERROR',
                'message' => $errMsg,
                'http_code' => $res['http_code']
            ];
        }

        $results = $res['body']['result'] ?? [];

        // No face detected
        if (empty($results)) {
            return [
                'success' => false,
                'error_code' => 'NO_FACE',
                'message' => 'No face detected in camera capture.'
            ];
        }

        // Multiple faces check
        if (count($results) > 1) {
            return [
                'success' => false,
                'error_code' => 'MULTIPLE_FACES',
                'message' => 'Please keep only one student in front of the camera.'
            ];
        }

        $faceResult = $results[0];
        $subjects = $faceResult['subjects'] ?? [];

        if (empty($subjects)) {
            return [
                'success' => false,
                'error_code' => 'FACE_NOT_RECOGNIZED',
                'message' => 'Face not recognized in institution registry.'
            ];
        }

        $topMatch = $subjects[0];
        $matchedSubject = (string)($topMatch['subject'] ?? '');
        $similarity = (float)($topMatch['similarity'] ?? 0.0);

        if ($similarity < $requiredThreshold) {
            return [
                'success' => false,
                'error_code' => 'LOW_CONFIDENCE',
                'similarity' => $similarity,
                'threshold' => $requiredThreshold,
                'message' => 'Face match confidence too low (' . round($similarity * 100, 1) . '%).'
            ];
        }

        $studentId = self::parseSubject($matchedSubject);
        if ($studentId === null) {
            return [
                'success' => false,
                'error_code' => 'INVALID_SUBJECT',
                'message' => 'Recognized face has non-conforming institutional identity.'
            ];
        }

        return [
            'success' => true,
            'student_id' => $studentId,
            'subject' => $matchedSubject,
            'similarity' => $similarity,
            'threshold' => $requiredThreshold,
            'box' => $faceResult['box'] ?? null
        ];
    }

    /**
     * Delete subject and all face examples from CompreFace
     * DELETE /api/v1/recognition/subjects/{subject}
     */
    public static function deleteSubject(string $subject): array
    {
        if (self::$mockHandler !== null) {
            $mockResponse = (self::$mockHandler)('deleteSubject', ['subject' => $subject]);
            if ($mockResponse !== null) {
                return $mockResponse;
            }
        }

        // First attempt deleting all face examples
        self::request('DELETE', '/api/v1/recognition/faces?subject=' . urlencode($subject));

        // Then delete the subject
        $res = self::request('DELETE', '/api/v1/recognition/subjects/' . urlencode($subject));

        return [
            'success' => $res['http_code'] >= 200 && $res['http_code'] < 300,
            'http_code' => $res['http_code'],
            'subject' => $subject
        ];
    }

    /**
     * List saved face examples for a subject in CompreFace
     * GET /api/v1/recognition/faces?subject={subject}
     */
    public static function listSubjectExamples(string $subject): array
    {
        if (self::$mockHandler !== null) {
            $mockResponse = (self::$mockHandler)('listSubjectExamples', ['subject' => $subject]);
            if ($mockResponse !== null) {
                return $mockResponse;
            }
        }

        $res = self::request('GET', '/api/v1/recognition/faces?subject=' . urlencode($subject));
        if ($res['http_code'] === 200 && isset($res['body']['faces'])) {
            return [
                'success' => true,
                'faces' => $res['body']['faces'],
                'count' => count($res['body']['faces'])
            ];
        }

        return [
            'success' => false,
            'faces' => [],
            'count' => 0
        ];
    }

    /**
     * Strip data:image/...;base64, header to obtain clean base64 string
     */
    public static function cleanBase64(string $base64): string
    {
        $base64 = trim($base64);
        if (preg_match('#^data:image/[a-zA-Z0-9.+_-]+;base64,(.+)$#', $base64, $matches)) {
            return $matches[1];
        }
        return $base64;
    }

    /**
     * Internal cURL HTTP request handler to CompreFace
     */
    private static function request(string $method, string $path, ?array $body = null, int $timeout = 10): array
    {
        if (!self::isConfigured() && self::$mockHandler === null) {
            return [
                'http_code' => 0,
                'error' => 'CompreFace service is not configured (missing COMPREFACE_API_KEY).',
                'body' => null
            ];
        }

        $baseUrl = self::getBaseUrl();
        $apiKey = self::getApiKey();

        $url = $baseUrl . $path;
        $ch = curl_init();

        $headers = [
            'x-api-key: ' . $apiKey,
            'Accept: application/json'
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];

        if ($body !== null) {
            $jsonPayload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $jsonPayload;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $responseRaw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseRaw === false || !empty($curlError)) {
            error_log("[SAMS CompreFace cURL Error] {$curlError} on {$url}");
            return [
                'http_code' => 0,
                'error' => $curlError,
                'body' => null
            ];
        }

        $decoded = json_decode($responseRaw, true);

        return [
            'http_code' => $httpCode,
            'raw' => $responseRaw,
            'body' => $decoded ?: ['raw' => $responseRaw]
        ];
    }
}
