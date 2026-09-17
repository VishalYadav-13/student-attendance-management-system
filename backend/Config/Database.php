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
            }

            // Always synchronize all PostgreSQL sequences to MAX(column_value)
            // Safe to run repeatedly; only synchronizes when a sequence is behind MAX(id)
            self::syncPgsqlSequences($pdo);

        } catch (\Throwable $e) {
            error_log("[SAMS PgSQL Auto-Init Error] " . $e->getMessage());
        }
    }

    /**
     * Synchronize all PostgreSQL sequences with MAX(primary_key)
     * Safe to run more than once; only updates sequences that are out of sync.
     * Uses pg_get_serial_sequence and setval dynamically for all auto-increment columns.
     */
    public static function syncPgsqlSequences(PDO $pdo): void
    {
        try {
            // Primary dynamic synchronization via PostgreSQL system catalogs
            $sql = "
                DO $$
                DECLARE
                    rec RECORD;
                    seq_name TEXT;
                    max_val BIGINT;
                    curr_seq BIGINT;
                    is_called BOOL;
                BEGIN
                    FOR rec IN
                        SELECT 
                            c.table_name,
                            c.column_name
                        FROM information_schema.columns c
                        JOIN information_schema.tables t 
                            ON c.table_name = t.table_name AND c.table_schema = t.table_schema
                        WHERE c.table_schema = 'public'
                          AND t.table_type = 'BASE TABLE'
                          AND c.column_default LIKE 'nextval(%'
                    LOOP
                        seq_name := pg_get_serial_sequence(quote_ident(rec.table_name), rec.column_name);
                        IF seq_name IS NOT NULL THEN
                            EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I', rec.column_name, rec.table_name) INTO max_val;
                            IF max_val > 0 THEN
                                BEGIN
                                    EXECUTE format('SELECT last_value, is_called FROM %s', seq_name) INTO curr_seq, is_called;
                                    IF curr_seq < max_val OR (curr_seq = max_val AND NOT is_called) THEN
                                        PERFORM setval(seq_name, max_val, true);
                                    END IF;
                                EXCEPTION WHEN OTHERS THEN
                                    PERFORM setval(seq_name, max_val, true);
                                END;
                            END IF;
                        END IF;
                    END LOOP;
                END $$;
            ";
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log("[SAMS PgSQL Sequence Sync Notice] Dynamic PL/pgSQL sync notice: " . $e->getMessage() . " - invoking fallback sync.");
            self::fallbackSyncSequences($pdo);
        }
    }

    /**
     * Fallback sequence synchronizer querying pg_get_serial_sequence and setval per core table
     */
    private static function fallbackSyncSequences(PDO $pdo): void
    {
        $coreTables = [
            'users' => 'user_id',
            'students' => 'student_id',
            'teachers' => 'teacher_id',
            'admins' => 'admin_id',
            'attendance_sessions' => 'session_id',
            'attendance_records' => 'record_id',
            'attendance_overrides' => 'override_id',
            'audit_logs' => 'log_id',
            'notifications' => 'notification_id',
            'face_profiles' => 'profile_id',
            'face_verification_logs' => 'log_id',
            'subjects' => 'subject_id',
            'classes' => 'class_id',
            'divisions' => 'division_id',
            'departments' => 'department_id',
            'courses' => 'course_id',
            'semesters' => 'semester_id',
            'academic_years' => 'academic_year_id',
            'timetables' => 'timetable_id',
            'roles' => 'role_id'
        ];

        foreach ($coreTables as $table => $column) {
            try {
                $check = $pdo->prepare("
                    SELECT pg_get_serial_sequence(:tbl, :col) AS seq_name,
                           COALESCE(MAX(\"{$column}\"), 0) AS max_val
                    FROM \"{$table}\"
                ");
                $check->execute([':tbl' => $table, ':col' => $column]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['seq_name']) && (int)$row['max_val'] > 0) {
                    $seq = $row['seq_name'];
                    $maxVal = (int)$row['max_val'];

                    // Check if sync is necessary
                    $currSeq = null;
                    $isCalled = false;
                    try {
                        $stateStmt = $pdo->query("SELECT last_value, is_called FROM {$seq}");
                        if ($stateStmt && ($state = $stateStmt->fetch(PDO::FETCH_ASSOC))) {
                            $currSeq = (int)($state['last_value'] ?? 0);
                            $isCalled = !empty($state['is_called']) && in_array($state['is_called'], [true, 't', 1, '1'], true);
                        }
                    } catch (\Throwable $t) {
                        // ignore state query error
                    }

                    if ($currSeq === null || $currSeq < $maxVal || ($currSeq === $maxVal && !$isCalled)) {
                        $setval = $pdo->prepare("SELECT setval(:seq, :maxval, true)");
                        $setval->execute([
                            ':seq' => $seq,
                            ':maxval' => $maxVal
                        ]);
                    }
                }
            } catch (\Throwable $t) {
                // Table might not exist or non-serial
                continue;
            }
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
            return; // Already initialized
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
            division_id INTEGER NOT NULL REFERENCES divisions(division_id),
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
        }
    }
}
