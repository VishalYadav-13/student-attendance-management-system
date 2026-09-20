<?php
/**
 * SAMS - Database Connection Manager
 * Manages secure PDO connection with PostgreSQL as primary production engine
 * and automated dev-fallback for zero-friction local testing.
 */

namespace SAMS\Config;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?PDO $connection = null;
    private static string $activeDriver = 'pgsql';

    /**
     * Get singleton PDO database connection
     */
    public static function getConnection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        Env::load();

        $dbUrl = Env::get('DATABASE_URL');
        $driver = strtolower(Env::get('DB_CONNECTION', 'pgsql'));
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', 5432);
        $dbName = Env::get('DB_NAME', 'sams_db');
        $user = Env::get('DB_USER', 'postgres');
        $pass = (string)Env::get('DB_PASSWORD', '');

        $sslMode = '';

        // If DATABASE_URL is provided (e.g. Render, Supabase, Neon, Railway)
        if (!empty($dbUrl)) {
            $parsed = parse_url($dbUrl);
            if ($parsed && isset($parsed['host'])) {
                $scheme = strtolower($parsed['scheme'] ?? '');
                $driver = in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : $scheme;
                $host = $parsed['host'];
                $port = $parsed['port'] ?? 5432;
                $user = urldecode($parsed['user'] ?? '');
                $pass = urldecode($parsed['pass'] ?? '');
                $dbName = ltrim($parsed['path'] ?? '', '/');

                if (isset($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                    if (!empty($queryParams['sslmode'])) {
                        $sslMode = ";sslmode=" . $queryParams['sslmode'];
                    }
                }
            }
        }

        if (empty($sslMode) && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $sslMode = ";sslmode=prefer";
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Attempt PostgreSQL connection if pgsql driver extension is available
        if ($driver === 'pgsql' && extension_loaded('pdo_pgsql')) {
            try {
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}{$sslMode};options='--client_encoding=UTF8'";
                self::$connection = new PDO($dsn, $user, $pass, $options);
                self::$activeDriver = 'pgsql';
                self::initPgsqlDatabase(self::$connection);
                return self::$connection;
            } catch (PDOException $e) {
                error_log("[SAMS Database Notice] PostgreSQL connection failed: " . $e->getMessage());
                // In production, if SQLite extension is not loaded, throw exception
                if (Env::get('APP_ENV') === 'production' && !extension_loaded('pdo_sqlite')) {
                    throw new Exception("PostgreSQL Database Connection Failed: " . $e->getMessage());
                }
                error_log("[SAMS Notice] Falling back to local SQLite database.");
            }
        }

        // Development SQLite Fallback
        $sqlitePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'sams_dev.sqlite';
        try {
            $dsn = "sqlite:" . $sqlitePath;
            self::$connection = new PDO($dsn, null, null, $options);
            self::$activeDriver = 'sqlite';
            self::initSqliteDatabase(self::$connection);
            return self::$connection;
        } catch (PDOException $e) {
            throw new Exception("Database Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * Initialize PostgreSQL tables and seed data if needed, and ensure sequences are synchronized
     */
    private static function initPgsqlDatabase(PDO $pdo): void
    {
        try {
            // Check if users table already exists in public schema
            $checkStmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users'");
            $alreadyInitialized = ($checkStmt && $checkStmt->fetch());

            if (!$alreadyInitialized) {
                $schemaFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
                $seedFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seed.sql';

                if (file_exists($schemaFile)) {
                    $schemaSql = file_get_contents($schemaFile);
                    // Strip CREATE EXTENSION if non-superuser permissions exist
                    $schemaSql = preg_replace('/CREATE EXTENSION IF NOT EXISTS[^;]+;/i', '', $schemaSql);
                    $pdo->exec($schemaSql);
                }

                if (file_exists($seedFile)) {
                    $seedSql = file_get_contents($seedFile);
                    $pdo->exec($seedSql);
                }
            } else {
                // For existing initialized databases, execute all pending migrations in order
                $migrationsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
                if (is_dir($migrationsDir)) {
                    $files = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
                    sort($files);
                    foreach ($files as $file) {
                        try {
                            $migrationSql = file_get_contents($file);
                            $pdo->exec($migrationSql);
                        } catch (\Throwable $mEx) {
                            error_log("[SAMS Migration Error] File " . basename($file) . ": " . $mEx->getMessage());
                        }
                    }
                }
            }

            // Ensure single active teacher (Prof. Kalpesh Sir) and admin (Madhura Mam)
            try {
                self::executeSingleTeacherMigration($pdo);
            } catch (\Throwable $tErr) {
                error_log("[SAMS Single Teacher Migration Error] " . $tErr->getMessage());
            }

            self::ensureAcademicStructure($pdo);

            // Always synchronize all PostgreSQL sequences to MAX(column_value)
            // Safe to run repeatedly; only synchronizes when a sequence is behind MAX(id)
            self::syncPgsqlSequences($pdo);

        } catch (\Throwable $e) {
            error_log("[SAMS PgSQL Auto-Init Error] " . $e->getMessage());
        }
    }

    /**
     * Synchronize all PostgreSQL sequences with MAX(primary_key)
     * Safe to run repeatedly; only updates sequences that are out of sync.
     * Uses pg_get_serial_sequence and setval with proper regclass casting.
     */
    public static function syncPgsqlSequences(PDO $pdo): void
    {
        try {
            // Primary dynamic synchronization via PostgreSQL system catalogs
            $sql = "
                DO \$\$
                DECLARE
                    rec RECORD;
                    seq_name TEXT;
                    max_val BIGINT;
                    curr_seq BIGINT;
                    is_called BOOL;
                BEGIN
                    FOR rec IN
                        SELECT 
                            c.table_schema,
                            c.table_name,
                            c.column_name
                        FROM information_schema.columns c
                        JOIN information_schema.tables t 
                            ON c.table_name = t.table_name AND c.table_schema = t.table_schema
                        WHERE c.table_schema = 'public'
                          AND t.table_type = 'BASE TABLE'
                          AND (
                              c.column_default LIKE 'nextval(%'
                              OR c.is_identity = 'YES'
                          )
                    LOOP
                        seq_name := pg_get_serial_sequence(quote_ident(rec.table_schema) || '.' || quote_ident(rec.table_name), rec.column_name);
                        IF seq_name IS NOT NULL THEN
                            EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I.%I', rec.column_name, rec.table_schema, rec.table_name) INTO max_val;
                            IF max_val > 0 THEN
                                BEGIN
                                    EXECUTE format('SELECT last_value, is_called FROM %s', seq_name) INTO curr_seq, is_called;
                                    IF curr_seq < max_val OR (curr_seq = max_val AND NOT is_called) THEN
                                        EXECUTE format('SELECT setval(%L::regclass, %s, true)', seq_name, max_val);
                                    END IF;
                                EXCEPTION WHEN OTHERS THEN
                                    EXECUTE format('SELECT setval(%L::regclass, %s, true)', seq_name, max_val);
                                END;
                            ELSE
                                BEGIN
                                    EXECUTE format('SELECT setval(%L::regclass, 1, false)', seq_name);
                                EXCEPTION WHEN OTHERS THEN
                                    NULL;
                                END;
                            END IF;
                        END IF;
                    END LOOP;
                END \$\$;
            ";
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log("[SAMS PgSQL Sequence Sync Notice] Dynamic PL/pgSQL sync notice: " . $e->getMessage() . " - invoking fallback sync.");
        }

        // Always run fallback synchronizer across core tables as a guaranteed safety net
        self::fallbackSyncSequences($pdo);
    }

    /**
     * Fallback sequence synchronizer querying pg_get_serial_sequence and setval per core table
     */
    public static function fallbackSyncSequences(PDO $pdo): void
    {
        $coreTables = [
            'roles' => 'role_id',
            'users' => 'user_id',
            'admins' => 'admin_id',
            'departments' => 'department_id',
            'courses' => 'course_id',
            'academic_years' => 'academic_year_id',
            'semesters' => 'semester_id',
            'classes' => 'class_id',
            'divisions' => 'division_id',
            'teachers' => 'teacher_id',
            'students' => 'student_id',
            'subjects' => 'subject_id',
            'teacher_subjects' => 'id',
            'timetables' => 'timetable_id',
            'attendance_sessions' => 'session_id',
            'attendance_records' => 'record_id',
            'attendance_overrides' => 'override_id',
            'face_profiles' => 'profile_id',
            'student_face_templates' => 'id',
            'face_verification_logs' => 'log_id',
            'notifications' => 'notification_id',
            'audit_logs' => 'log_id',
            'password_resets' => 'reset_id'
        ];

        foreach ($coreTables as $table => $column) {
            try {
                // Execute atomic sequence alignment per table using native pg_get_serial_sequence and setval
                $sql = "
                    SELECT CASE 
                        WHEN pg_get_serial_sequence('\"{$table}\"', '{$column}') IS NOT NULL 
                        THEN setval(
                            pg_get_serial_sequence('\"{$table}\"', '{$column}'), 
                            GREATEST(COALESCE(MAX(\"{$column}\"), 0), 1), 
                            (COALESCE(MAX(\"{$column}\"), 0) > 0)
                        ) 
                    END 
                    FROM \"{$table}\"
                ";
                $pdo->exec($sql);
            } catch (\Throwable $t) {
                // Table might not exist or non-serial
                continue;
            }
        }
    }

    /**
     * Explicit public helper to synchronize all database sequences on active connection
     */
    public static function syncAllSequences(?PDO $pdo = null): void
    {
        $conn = $pdo ?? self::getConnection();
        if (self::getActiveDriver() === 'pgsql') {
            self::syncPgsqlSequences($conn);
        }
    }

    /**
     * Get active database driver name ('pgsql' or 'sqlite')
     */
    public static function getActiveDriver(): string
    {
        return self::$activeDriver;
    }

    /**
     * Initialize SQLite tables and seed data if needed for dev environment
     */
    private static function initSqliteDatabase(PDO $pdo): void
    {
        // Check if users table already exists
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if ($stmt->fetch()) {
            // Already initialized; ensure student_face_templates table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS student_face_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_id INTEGER UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
                    embedding TEXT NOT NULL,
                    model_version TEXT DEFAULT 'face-api-v1-128d',
                    quality_score REAL DEFAULT 1.0,
                    enrolled_by INTEGER REFERENCES users(user_id),
                    status TEXT DEFAULT 'ACTIVE',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_face_templates_student ON student_face_templates(student_id);
                CREATE INDEX IF NOT EXISTS idx_face_templates_status ON student_face_templates(status);
                UPDATE users SET email = 'teacher@sams.edu', status = 'ACTIVE' WHERE user_id = 2 OR email = 'teacher.sharma@sams.edu';
                UPDATE admins SET full_name = 'Madhura Mam' WHERE admin_id = 1 OR user_id = 1;
                UPDATE teachers SET full_name = 'Prof. Kalpesh Sir', designation = 'Senior Faculty - Computer Engineering', status = 'ACTIVE' WHERE teacher_id = 1;
                UPDATE teachers SET status = 'INACTIVE' WHERE teacher_id != 1;
                UPDATE users SET status = 'INACTIVE' WHERE role_id = 2 AND user_id != 2;
                UPDATE attendance_sessions SET teacher_id = 1;
                UPDATE teacher_subjects SET teacher_id = 1;
                UPDATE timetables SET teacher_id = 1;
            ");
            self::ensureAcademicStructure($pdo);
            return;
        }

        // Disable foreign keys during initial table setup and seed import
        $pdo->exec("PRAGMA foreign_keys = OFF;");

        $schemaSql = <<<SQL
        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_name TEXT UNIQUE NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_id INTEGER NOT NULL REFERENCES roles(role_id),
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            status TEXT DEFAULT 'ACTIVE',
            last_login_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE admins (
            admin_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            full_name TEXT NOT NULL,
            phone TEXT,
            designation TEXT DEFAULT 'System Administrator',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE departments (
            department_id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_code TEXT UNIQUE NOT NULL,
            department_name TEXT NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE courses (
            course_id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL REFERENCES departments(department_id),
            course_code TEXT UNIQUE NOT NULL,
            course_name TEXT NOT NULL,
            duration_years INTEGER DEFAULT 3,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE academic_years (
            academic_year_id INTEGER PRIMARY KEY AUTOINCREMENT,
            year_code TEXT UNIQUE NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_current INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE semesters (
            semester_id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL REFERENCES courses(course_id),
            semester_number INTEGER NOT NULL,
            academic_year_id INTEGER NOT NULL REFERENCES academic_years(academic_year_id),
            is_active INTEGER DEFAULT 1,
            UNIQUE(course_id, semester_number, academic_year_id)
        );

        CREATE TABLE classes (
            class_id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL REFERENCES departments(department_id),
            course_id INTEGER NOT NULL REFERENCES courses(course_id),
            semester_id INTEGER NOT NULL REFERENCES semesters(semester_id),
            class_name TEXT NOT NULL,
            class_code TEXT NOT NULL,
            academic_year_id INTEGER NOT NULL REFERENCES academic_years(academic_year_id),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE divisions (
            division_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(class_id),
            division_name TEXT NOT NULL,
            max_capacity INTEGER DEFAULT 60,
            UNIQUE(class_id, division_name)
        );

        CREATE TABLE teachers (
            teacher_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            employee_id TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            phone TEXT,
            department_id INTEGER NOT NULL REFERENCES departments(department_id),
            designation TEXT DEFAULT 'Lecturer / Assistant Professor',
            status TEXT DEFAULT 'ACTIVE',
            profile_photo TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE students (
            student_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            roll_number TEXT UNIQUE NOT NULL,
            student_uid TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            phone TEXT,
            date_of_birth DATE,
            gender TEXT,
            department_id INTEGER NOT NULL REFERENCES departments(department_id),
            course_id INTEGER NOT NULL REFERENCES courses(course_id),
            class_id INTEGER NOT NULL REFERENCES classes(class_id),
            division_id INTEGER NOT NULL REFERENCES divisions(division_id),
            batch TEXT DEFAULT 'General',
            admission_year INTEGER NOT NULL,
            status TEXT DEFAULT 'ACTIVE',
            profile_photo TEXT,
            face_verification_status TEXT DEFAULT 'NOT_ENROLLED',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE subjects (
            subject_id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_code TEXT UNIQUE NOT NULL,
            subject_name TEXT NOT NULL,
            department_id INTEGER NOT NULL REFERENCES departments(department_id),
            semester_number INTEGER NOT NULL,
            credits INTEGER DEFAULT 4,
            total_lectures INTEGER DEFAULT 45,
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE teacher_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL REFERENCES teachers(teacher_id),
            subject_id INTEGER NOT NULL REFERENCES subjects(subject_id),
            class_id INTEGER NOT NULL REFERENCES classes(class_id),
            division_id INTEGER NOT NULL REFERENCES divisions(division_id),
            academic_year_id INTEGER NOT NULL REFERENCES academic_years(academic_year_id),
            UNIQUE(teacher_id, subject_id, class_id, division_id, academic_year_id)
        );

        CREATE TABLE timetables (
            timetable_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(class_id),
            division_id INTEGER NOT NULL REFERENCES divisions(division_id),
            subject_id INTEGER NOT NULL REFERENCES subjects(subject_id),
            teacher_id INTEGER NOT NULL REFERENCES teachers(teacher_id),
            day_of_week TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            room_number TEXT DEFAULT 'Room 204',
            academic_year_id INTEGER NOT NULL REFERENCES academic_years(academic_year_id)
        );

        CREATE TABLE attendance_sessions (
            session_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(class_id),
            division_id INTEGER REFERENCES divisions(division_id),
            subject_id INTEGER NOT NULL REFERENCES subjects(subject_id),
            teacher_id INTEGER NOT NULL REFERENCES teachers(teacher_id),
            session_date DATE NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT,
            lecture_number INTEGER DEFAULT 1,
            status TEXT DEFAULT 'OPEN',
            verification_mode TEXT DEFAULT 'HYBRID',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME
        );

        CREATE TABLE attendance_records (
            record_id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
            student_id INTEGER NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
            status TEXT NOT NULL,
            marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            verification_method TEXT DEFAULT 'MANUAL',
            confidence_score REAL,
            ip_address TEXT,
            UNIQUE(session_id, student_id)
        );

        CREATE TABLE attendance_overrides (
            override_id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL REFERENCES attendance_records(record_id) ON DELETE CASCADE,
            session_id INTEGER NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
            student_id INTEGER NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
            original_status TEXT NOT NULL,
            new_status TEXT NOT NULL,
            changed_by INTEGER NOT NULL REFERENCES users(user_id),
            reason TEXT NOT NULL,
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE face_profiles (
            profile_id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
            status TEXT DEFAULT 'ENROLLED',
            biometric_hash TEXT NOT NULL,
            feature_vector TEXT,
            samples_count INTEGER DEFAULT 3,
            consent_given INTEGER DEFAULT 1,
            consent_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            enrolled_by INTEGER REFERENCES users(user_id),
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE student_face_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER UNIQUE NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
            embedding TEXT NOT NULL,
            model_version TEXT DEFAULT 'face-api-v1-128d',
            quality_score REAL DEFAULT 1.0,
            enrolled_by INTEGER REFERENCES users(user_id),
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE face_verification_logs (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER REFERENCES students(student_id),
            session_id INTEGER REFERENCES attendance_sessions(session_id),
            verification_result TEXT NOT NULL,
            confidence_score REAL,
            quality_score REAL,
            method TEXT DEFAULT 'GEMINI_ASSISTED_VISION',
            notes TEXT,
            ip_address TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT DEFAULT 'ALERT',
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE audit_logs (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(user_id),
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id TEXT,
            ip_address TEXT,
            user_agent TEXT,
            metadata TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            description TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        SQL;

        $pdo->exec($schemaSql);

        // Execute seed data for development
        $seedFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seed.sql';
        if (file_exists($seedFile)) {
            $rawSeed = file_get_contents($seedFile);
            // Normalize statements
            $rawStatements = explode(';', $rawSeed);
            foreach ($rawStatements as $stmt) {
                // Strip comment lines
                $stmt = preg_replace('/^--.*$/m', '', $stmt);
                $stmt = trim($stmt);
                if (empty($stmt)) {
                    continue;
                }

                // Convert PostgreSQL ON CONFLICT to SQLite syntax
                if (preg_match('/ON CONFLICT.*DO UPDATE/is', $stmt)) {
                    $stmt = preg_replace('/^INSERT INTO/i', 'INSERT OR REPLACE INTO', $stmt);
                    $stmt = preg_replace('/ON CONFLICT.*$/is', '', $stmt);
                } else {
                    $stmt = preg_replace('/^INSERT INTO/i', 'INSERT OR IGNORE INTO', $stmt);
                    $stmt = preg_replace('/ON CONFLICT.*DO NOTHING/i', '', $stmt);
                }

                $stmt = str_replace('CURRENT_DATE', "date('now')", $stmt);
                $stmt = str_replace('CURRENT_TIMESTAMP', "datetime('now')", $stmt);
                $stmt = preg_replace('/\btrue\b/i', '1', $stmt);
                $stmt = preg_replace('/\bfalse\b/i', '0', $stmt);

                try {
                    $pdo->exec($stmt);
                } catch (\Throwable $e) {
                    error_log("[SAMS Seed Error] " . $e->getMessage() . " in SQL: " . substr($stmt, 0, 100));
                }
            }

            // Re-enable foreign key constraints
            $pdo->exec("PRAGMA foreign_keys = ON;");

            // Ensure single teacher & admin structure in SQLite
            try {
                self::executeSingleTeacherMigration($pdo);
            } catch (\Throwable $tErr) {
                error_log("[SAMS SQLite Single Teacher Migration Error] " . $tErr->getMessage());
            }
        }
    }

    /**
     * Idempotent Data Migration: Simplify staff structure to 1 active teacher and 1 active admin
     * - Prof. Kalpesh Sir (TEACHER, ACTIVE)
     * - Madhura Mam (ADMIN, ACTIVE)
     * - All legitimate attendance sessions (including Session #25) migrated to Prof. Kalpesh Sir
     * - All other teachers safely deactivated (status = INACTIVE)
     */
    public static function executeSingleTeacherMigration(PDO $pdo): array
    {
        // 1. Identify canonical teacher account
        $stmt = $pdo->prepare("
            SELECT t.teacher_id, u.user_id, t.full_name
            FROM users u
            JOIN teachers t ON u.user_id = t.user_id
            WHERE LOWER(u.email) = 'teacher@sams.edu'
            LIMIT 1
        ");
        $stmt->execute();
        $target = $stmt->fetch();

        if (!$target) {
            $tQuery = $pdo->query("SELECT teacher_id, user_id, full_name FROM teachers ORDER BY teacher_id ASC LIMIT 1");
            $target = $tQuery ? $tQuery->fetch() : null;
        }

        if (!$target) {
            return ['success' => false, 'message' => 'No teacher records found in database.'];
        }

        $targetTeacherId = (int)$target['teacher_id'];
        $targetUserId = (int)$target['user_id'];

        $pdo->beginTransaction();
        try {
            // 2. Rename canonical teacher account to Prof. Kalpesh Sir
            $updT = $pdo->prepare("
                UPDATE teachers
                SET full_name = 'Prof. Kalpesh Sir',
                    designation = 'Senior Faculty - Computer Engineering',
                    status = 'ACTIVE'
                WHERE teacher_id = :tid
            ");
            $updT->execute([':tid' => $targetTeacherId]);

            // Ensure canonical teacher user is ACTIVE with role 2 (TEACHER)
            $updU = $pdo->prepare("
                UPDATE users
                SET status = 'ACTIVE', role_id = 2
                WHERE user_id = :uid
            ");
            $updU->execute([':uid' => $targetUserId]);

            // 3. Migrate ALL attendance sessions to Prof. Kalpesh Sir (including Session #25)
            $updS = $pdo->prepare("
                UPDATE attendance_sessions
                SET teacher_id = :tid
            ");
            $updS->execute([':tid' => $targetTeacherId]);
            $migratedSessionsCount = $updS->rowCount();

            // 4. Migrate subject allocations & timetables
            try {
                $updTS = $pdo->prepare("
                    UPDATE teacher_subjects
                    SET teacher_id = :tid
                    WHERE teacher_id != :tid
                ");
                $updTS->execute([':tid' => $targetTeacherId]);
            } catch (\Throwable $tsEx) {
                // Ignore unique constraint conflicts on duplicate allocations
            }

            try {
                $updTT = $pdo->prepare("UPDATE timetables SET teacher_id = :tid");
                $updTT->execute([':tid' => $targetTeacherId]);
            } catch (\Throwable $ttEx) {}

            // 5. Safely deactivate all other teachers (preserving FK relationships and audit logs)
            $deactT = $pdo->prepare("
                UPDATE teachers
                SET status = 'INACTIVE'
                WHERE teacher_id != :tid
            ");
            $deactT->execute([':tid' => $targetTeacherId]);
            $deactivatedTeachersCount = $deactT->rowCount();

            // Safely deactivate other teacher user accounts
            $deactU = $pdo->prepare("
                UPDATE users
                SET status = 'INACTIVE'
                WHERE role_id = 2 AND user_id != :uid
            ");
            $deactU->execute([':uid' => $targetUserId]);

            // 6. Rename admin account to Madhura Mam
            $updAdm = $pdo->prepare("
                UPDATE admins
                SET full_name = 'Madhura Mam'
                WHERE admin_id = 1 OR user_id = 1
            ");
            $updAdm->execute();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Post-migration verification queries
        $activeT = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'ACTIVE'")->fetchColumn();
        $inactiveT = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'INACTIVE'")->fetchColumn();

        $tCheck = $pdo->prepare("SELECT teacher_id, full_name, status FROM teachers WHERE teacher_id = :tid");
        $tCheck->execute([':tid' => $targetTeacherId]);
        $activeTeacher = $tCheck->fetch();

        $admCheck = $pdo->query("SELECT admin_id, full_name FROM admins WHERE admin_id = 1 OR user_id = 1 LIMIT 1")->fetch();

        $s25Check = $pdo->query("
            SELECT s.session_id, s.teacher_id, t.full_name AS teacher_name
            FROM attendance_sessions s
            LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
            WHERE s.session_id = 25
        ")->fetch();

        $sessionsOwnedByCanonical = (int)$pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id = {$targetTeacherId}")->fetchColumn();
        $sessionsOwnedByOthers = (int)$pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id != {$targetTeacherId}")->fetchColumn();

        return [
            'success' => true,
            'canonical_teacher_id' => $targetTeacherId,
            'canonical_teacher_user_id' => $targetUserId,
            'canonical_teacher_name' => $activeTeacher['full_name'] ?? 'Prof. Kalpesh Sir',
            'admin_name' => $admCheck['full_name'] ?? 'Madhura Mam',
            'active_teachers_count' => $activeT,
            'inactive_teachers_count' => $inactiveT,
            'session_25' => $s25Check,
            'sessions_migrated' => $migratedSessionsCount,
            'sessions_owned_by_canonical' => $sessionsOwnedByCanonical,
            'sessions_owned_by_others' => $sessionsOwnedByOthers
        ];
    }

    /**
     * Ensure FY and TY academic classes, subjects, teacher allocations, and division-free session support
     */
    public static function ensureAcademicStructure(PDO $pdo): void
    {
        $driver = self::getActiveDriver();
        try {
            if ($driver === 'pgsql') {
                try {
                    $pdo->exec("ALTER TABLE attendance_sessions ALTER COLUMN division_id DROP NOT NULL");
                } catch (\Throwable $t) {}
            } else {
                // Check SQLite attendance_sessions column notnull
                $pragmaStmt = $pdo->query("PRAGMA table_info(attendance_sessions)");
                $cols = $pragmaStmt ? $pragmaStmt->fetchAll() : [];
                if ($pragmaStmt) {
                    $pragmaStmt->closeCursor();
                    unset($pragmaStmt);
                }
                $divCol = null;
                foreach ($cols as $c) {
                    if ($c['name'] === 'division_id') {
                        $divCol = $c;
                        break;
                    }
                }
                if ($divCol && !empty($divCol['notnull'])) {
                    $pdo->exec("PRAGMA foreign_keys = OFF;");
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS attendance_sessions_temp (
                            session_id INTEGER PRIMARY KEY AUTOINCREMENT,
                            class_id INTEGER NOT NULL REFERENCES classes(class_id),
                            division_id INTEGER REFERENCES divisions(division_id),
                            subject_id INTEGER NOT NULL REFERENCES subjects(subject_id),
                            teacher_id INTEGER NOT NULL REFERENCES teachers(teacher_id),
                            session_date DATE NOT NULL,
                            start_time TEXT NOT NULL,
                            end_time TEXT,
                            lecture_number INTEGER DEFAULT 1,
                            status TEXT DEFAULT 'OPEN',
                            verification_mode TEXT DEFAULT 'HYBRID',
                            notes TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            closed_at DATETIME
                        );
                        INSERT OR IGNORE INTO attendance_sessions_temp SELECT session_id, class_id, division_id, subject_id, teacher_id, session_date, start_time, end_time, lecture_number, status, verification_mode, notes, created_at, closed_at FROM attendance_sessions;
                        DROP TABLE attendance_sessions;
                        ALTER TABLE attendance_sessions_temp RENAME TO attendance_sessions;
                        PRAGMA foreign_keys = ON;
                    ");
                }
            }

            // Insert Semesters
            $semSql = ($driver === 'pgsql') ? "
                INSERT INTO semesters (semester_id, course_id, semester_number, academic_year_id, is_active) VALUES
                (5, 1, 1, 1, true),
                (6, 1, 5, 1, true),
                (7, 2, 1, 1, true),
                (8, 2, 5, 1, true),
                (9, 3, 1, 1, true),
                (10, 3, 5, 1, true)
                ON CONFLICT (semester_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO semesters (semester_id, course_id, semester_number, academic_year_id, is_active) VALUES
                (5, 1, 1, 1, 1),
                (6, 1, 5, 1, 1),
                (7, 2, 1, 1, 1),
                (8, 2, 5, 1, 1),
                (9, 3, 1, 1, 1),
                (10, 3, 5, 1, 1)
            ";
            $pdo->exec($semSql);

            // Insert Classes
            $clsSql = ($driver === 'pgsql') ? "
                INSERT INTO classes (class_id, department_id, course_id, semester_id, class_name, class_code, academic_year_id) VALUES
                (4, 1, 1, 5, 'First Year Computer Engineering', 'FYCO', 1),
                (5, 1, 1, 6, 'Third Year Computer Engineering', 'TYCO', 1),
                (6, 2, 2, 7, 'First Year Information Technology', 'FYIT', 1),
                (7, 2, 2, 8, 'Third Year Information Technology', 'TYIT', 1),
                (8, 3, 3, 9, 'First Year Electronics', 'FYEJ', 1),
                (9, 3, 3, 10, 'Third Year Electronics', 'TYEJ', 1)
                ON CONFLICT (class_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO classes (class_id, department_id, course_id, semester_id, class_name, class_code, academic_year_id) VALUES
                (4, 1, 1, 5, 'First Year Computer Engineering', 'FYCO', 1),
                (5, 1, 1, 6, 'Third Year Computer Engineering', 'TYCO', 1),
                (6, 2, 2, 7, 'First Year Information Technology', 'FYIT', 1),
                (7, 2, 2, 8, 'Third Year Information Technology', 'TYIT', 1),
                (8, 3, 3, 9, 'First Year Electronics', 'FYEJ', 1),
                (9, 3, 3, 10, 'Third Year Electronics', 'TYEJ', 1)
            ";
            $pdo->exec($clsSql);

            // Insert Divisions
            $divSql = ($driver === 'pgsql') ? "
                INSERT INTO divisions (division_id, class_id, division_name, max_capacity) VALUES
                (5, 4, 'A', 60),
                (6, 5, 'A', 60),
                (7, 6, 'A', 60),
                (8, 7, 'A', 60),
                (9, 8, 'A', 60),
                (10, 9, 'A', 60)
                ON CONFLICT (division_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO divisions (division_id, class_id, division_name, max_capacity) VALUES
                (5, 4, 'A', 60),
                (6, 5, 'A', 60),
                (7, 6, 'A', 60),
                (8, 7, 'A', 60),
                (9, 8, 'A', 60),
                (10, 9, 'A', 60)
            ";
            $pdo->exec($divSql);

            // Insert Subjects
            $subSql = ($driver === 'pgsql') ? "
                INSERT INTO subjects (subject_id, subject_code, subject_name, department_id, semester_number, credits, total_lectures) VALUES
                (7, '22103', 'Basic Mathematics', 1, 1, 4, 45),
                (8, '22226', 'Programming in C', 1, 1, 4, 45),
                (9, '22517', 'Advanced Java Programming', 1, 5, 4, 45),
                (10, '22413', 'Software Engineering', 1, 5, 3, 36),
                (11, '22516', 'Operating Systems', 1, 5, 4, 45),
                (12, '22520', 'Advanced Computer Networks', 1, 5, 4, 45)
                ON CONFLICT (subject_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO subjects (subject_id, subject_code, subject_name, department_id, semester_number, credits, total_lectures) VALUES
                (7, '22103', 'Basic Mathematics', 1, 1, 4, 45),
                (8, '22226', 'Programming in C', 1, 1, 4, 45),
                (9, '22517', 'Advanced Java Programming', 1, 5, 4, 45),
                (10, '22413', 'Software Engineering', 1, 5, 3, 36),
                (11, '22516', 'Operating Systems', 1, 5, 4, 45),
                (12, '22520', 'Advanced Computer Networks', 1, 5, 4, 45)
            ";
            $pdo->exec($subSql);

            // Insert Teacher Subjects
            $tsSql = ($driver === 'pgsql') ? "
                INSERT INTO teacher_subjects (id, teacher_id, subject_id, class_id, division_id, academic_year_id) VALUES
                (6, 1, 8, 4, 5, 1),
                (7, 1, 9, 5, 6, 1),
                (101, 1, 10, 4, 5, 1),
                (102, 1, 10, 1, 1, 1),
                (103, 1, 10, 5, 6, 1),
                (104, 1, 11, 4, 5, 1),
                (105, 1, 11, 1, 1, 1),
                (106, 1, 11, 5, 6, 1),
                (107, 1, 12, 4, 5, 1),
                (108, 1, 12, 1, 1, 1),
                (109, 1, 12, 5, 6, 1)
                ON CONFLICT (id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO teacher_subjects (id, teacher_id, subject_id, class_id, division_id, academic_year_id) VALUES
                (6, 1, 8, 4, 5, 1),
                (7, 1, 9, 5, 6, 1),
                (101, 1, 10, 4, 5, 1),
                (102, 1, 10, 1, 1, 1),
                (103, 1, 10, 5, 6, 1),
                (104, 1, 11, 4, 5, 1),
                (105, 1, 11, 1, 1, 1),
                (106, 1, 11, 5, 6, 1),
                (107, 1, 12, 4, 5, 1),
                (108, 1, 12, 1, 1, 1),
                (109, 1, 12, 5, 6, 1)
            ";
            $pdo->exec($tsSql);

            // Insert Users & Students for FY CO and TY CO
            $uSql = ($driver === 'pgsql') ? "
                INSERT INTO users (user_id, role_id, email, password_hash, status) VALUES
                (27, 3, 'aryan.patil@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (28, 3, 'ishaan.deshmukh@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (29, 3, 'riya.sharma@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (30, 3, 'vedant.joshi@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (31, 3, 'tanmay.kulkarni@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (32, 3, 'ananya.more@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (33, 3, 'saurabh.chavan@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (34, 3, 'shreya.jadhav@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (35, 3, 'rohit.sawant@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (36, 3, 'prachi.nair@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (37, 3, 'chinmay.bapat@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (38, 3, 'gaurav.kale@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (39, 3, 'mansi.rane@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (40, 3, 'nikhil.shinde@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (41, 3, 'pallavi.vaidya@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (42, 3, 'ruturaj.thorat@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (43, 3, 'sayali.ghatke@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (44, 3, 'swapnil.mane@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (45, 3, 'tejas.wagh@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (46, 3, 'vaishnavi.shete@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE')
                ON CONFLICT (user_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO users (user_id, role_id, email, password_hash, status) VALUES
                (201, 3, 'aryan.patil@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (202, 3, 'ishaan.deshmukh@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (203, 3, 'riya.sharma@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (204, 3, 'vedant.joshi@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (205, 3, 'tanmay.kulkarni@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (206, 3, 'ananya.more@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (207, 3, 'saurabh.chavan@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (208, 3, 'shreya.jadhav@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (209, 3, 'rohit.sawant@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (210, 3, 'prachi.nair@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (211, 3, 'chinmay.bapat@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (212, 3, 'gaurav.kale@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (213, 3, 'mansi.rane@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (214, 3, 'nikhil.shinde@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (215, 3, 'pallavi.vaidya@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (216, 3, 'ruturaj.thorat@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (217, 3, 'sayali.ghatke@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (218, 3, 'swapnil.mane@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (219, 3, 'tejas.wagh@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE'),
                (220, 3, 'vaishnavi.shete@sams.edu', '\$2y\$10\$xcicWEwRIwDYVH8EzbFt/uDv1MEbm.l7xsXCmCBeX28VVsEy0DHna', 'ACTIVE')
            ";
            $pdo->exec($uSql);

            // Students for FY CO (Class 4) and TY CO (Class 5)
            $stuSql = ($driver === 'pgsql') ? "
                INSERT INTO students (student_id, user_id, roll_number, student_uid, full_name, email, phone, date_of_birth, gender, department_id, course_id, class_id, division_id, batch, admission_year, face_verification_status) VALUES
                (201, 201, 'CO-1101', 'UID2026101', 'Aryan Patil', 'aryan.patil@sams.edu', '+91 98111 10001', '2006-04-12', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (202, 202, 'CO-1102', 'UID2026102', 'Ishaan Deshmukh', 'ishaan.deshmukh@sams.edu', '+91 98111 10002', '2006-06-18', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (203, 203, 'CO-1103', 'UID2026103', 'Riya Sharma', 'riya.sharma@sams.edu', '+91 98111 10003', '2006-08-22', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (204, 204, 'CO-1104', 'UID2026104', 'Vedant Joshi', 'vedant.joshi@sams.edu', '+91 98111 10004', '2006-02-10', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (205, 205, 'CO-1105', 'UID2026105', 'Tanmay Kulkarni', 'tanmay.kulkarni@sams.edu', '+91 98111 10005', '2006-11-05', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (206, 206, 'CO-1106', 'UID2026106', 'Ananya More', 'ananya.more@sams.edu', '+91 98111 10006', '2006-09-14', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (207, 207, 'CO-1107', 'UID2026107', 'Saurabh Chavan', 'saurabh.chavan@sams.edu', '+91 98111 10007', '2006-07-29', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (208, 208, 'CO-1108', 'UID2026108', 'Shreya Jadhav', 'shreya.jadhav@sams.edu', '+91 98111 10008', '2006-01-19', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (209, 209, 'CO-1109', 'UID2026109', 'Rohit Sawant', 'rohit.sawant@sams.edu', '+91 98111 10009', '2006-12-01', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (210, 210, 'CO-1110', 'UID2026110', 'Prachi Nair', 'prachi.nair@sams.edu', '+91 98111 10010', '2006-03-25', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (211, 211, 'CO-3101', 'UID2026301', 'Chinmay Bapat', 'chinmay.bapat@sams.edu', '+91 98111 30001', '2004-04-12', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (212, 212, 'CO-3102', 'UID2026302', 'Gaurav Kale', 'gaurav.kale@sams.edu', '+91 98111 30002', '2004-06-18', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (213, 213, 'CO-3103', 'UID2026303', 'Mansi Rane', 'mansi.rane@sams.edu', '+91 98111 30003', '2004-08-22', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (214, 214, 'CO-3104', 'UID2026304', 'Nikhil Shinde', 'nikhil.shinde@sams.edu', '+91 98111 30004', '2004-02-10', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (215, 215, 'CO-3105', 'UID2026305', 'Pallavi Vaidya', 'pallavi.vaidya@sams.edu', '+91 98111 30005', '2004-11-05', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (216, 216, 'CO-3106', 'UID2026306', 'Ruturaj Thorat', 'ruturaj.thorat@sams.edu', '+91 98111 30006', '2004-09-14', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (217, 217, 'CO-3107', 'UID2026307', 'Sayali Ghatke', 'sayali.ghatke@sams.edu', '+91 98111 30007', '2004-07-29', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (218, 218, 'CO-3108', 'UID2026308', 'Swapnil Mane', 'swapnil.mane@sams.edu', '+91 98111 30008', '2004-01-19', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (219, 219, 'CO-3109', 'UID2026309', 'Tejas Wagh', 'tejas.wagh@sams.edu', '+91 98111 30009', '2004-12-01', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (220, 220, 'CO-3110', 'UID2026310', 'Vaishnavi Shete', 'vaishnavi.shete@sams.edu', '+91 98111 30010', '2004-03-25', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED')
                ON CONFLICT (student_id) DO NOTHING
            " : "
                INSERT OR IGNORE INTO students (student_id, user_id, roll_number, student_uid, full_name, email, phone, date_of_birth, gender, department_id, course_id, class_id, division_id, batch, admission_year, face_verification_status) VALUES
                (201, 201, 'CO-1101', 'UID2026101', 'Aryan Patil', 'aryan.patil@sams.edu', '+91 98111 10001', '2006-04-12', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (202, 202, 'CO-1102', 'UID2026102', 'Ishaan Deshmukh', 'ishaan.deshmukh@sams.edu', '+91 98111 10002', '2006-06-18', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (203, 203, 'CO-1103', 'UID2026103', 'Riya Sharma', 'riya.sharma@sams.edu', '+91 98111 10003', '2006-08-22', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (204, 204, 'CO-1104', 'UID2026104', 'Vedant Joshi', 'vedant.joshi@sams.edu', '+91 98111 10004', '2006-02-10', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (205, 205, 'CO-1105', 'UID2026105', 'Tanmay Kulkarni', 'tanmay.kulkarni@sams.edu', '+91 98111 10005', '2006-11-05', 'Male', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (206, 206, 'CO-1106', 'UID2026106', 'Ananya More', 'ananya.more@sams.edu', '+91 98111 10006', '2006-09-14', 'Female', 1, 1, 4, 5, 'B1', 2026, 'ENROLLED'),
                (207, 207, 'CO-1107', 'UID2026107', 'Saurabh Chavan', 'saurabh.chavan@sams.edu', '+91 98111 10007', '2006-07-29', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (208, 208, 'CO-1108', 'UID2026108', 'Shreya Jadhav', 'shreya.jadhav@sams.edu', '+91 98111 10008', '2006-01-19', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (209, 209, 'CO-1109', 'UID2026109', 'Rohit Sawant', 'rohit.sawant@sams.edu', '+91 98111 10009', '2006-12-01', 'Male', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (210, 210, 'CO-1110', 'UID2026110', 'Prachi Nair', 'prachi.nair@sams.edu', '+91 98111 10010', '2006-03-25', 'Female', 1, 1, 4, 5, 'B2', 2026, 'ENROLLED'),
                (211, 211, 'CO-3101', 'UID2026301', 'Chinmay Bapat', 'chinmay.bapat@sams.edu', '+91 98111 30001', '2004-04-12', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (212, 212, 'CO-3102', 'UID2026302', 'Gaurav Kale', 'gaurav.kale@sams.edu', '+91 98111 30002', '2004-06-18', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (213, 213, 'CO-3103', 'UID2026303', 'Mansi Rane', 'mansi.rane@sams.edu', '+91 98111 30003', '2004-08-22', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (214, 214, 'CO-3104', 'UID2026304', 'Nikhil Shinde', 'nikhil.shinde@sams.edu', '+91 98111 30004', '2004-02-10', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (215, 215, 'CO-3105', 'UID2026305', 'Pallavi Vaidya', 'pallavi.vaidya@sams.edu', '+91 98111 30005', '2004-11-05', 'Female', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (216, 216, 'CO-3106', 'UID2026306', 'Ruturaj Thorat', 'ruturaj.thorat@sams.edu', '+91 98111 30006', '2004-09-14', 'Male', 1, 1, 5, 6, 'B1', 2024, 'ENROLLED'),
                (217, 217, 'CO-3107', 'UID2026307', 'Sayali Ghatke', 'sayali.ghatke@sams.edu', '+91 98111 30007', '2004-07-29', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (218, 218, 'CO-3108', 'UID2026308', 'Swapnil Mane', 'swapnil.mane@sams.edu', '+91 98111 30008', '2004-01-19', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (219, 219, 'CO-3109', 'UID2026309', 'Tejas Wagh', 'tejas.wagh@sams.edu', '+91 98111 30009', '2004-12-01', 'Male', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED'),
                (220, 220, 'CO-3110', 'UID2026310', 'Vaishnavi Shete', 'vaishnavi.shete@sams.edu', '+91 98111 30010', '2004-03-25', 'Female', 1, 1, 5, 6, 'B2', 2024, 'ENROLLED')
            ";
            $pdo->exec($stuSql);

        } catch (\Throwable $ex) {
            error_log("[SAMS Academic Structure Notice] " . $ex->getMessage());
        }
    }
}

