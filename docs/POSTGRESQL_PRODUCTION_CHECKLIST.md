# PostgreSQL Production Readiness Checklist

This document outlines the mandatory operational and architectural steps for deploying the SAMS PostgreSQL database in high-availability, institutional production environments (AWS RDS, Neon, Supabase, Railway, DigitalOcean Managed PostgreSQL).

---

## 1. Connection & Infrastructure

- [x] **Primary Engine**: PostgreSQL 14+ with strict ANSI SQL compatibility.
- [ ] **Managed Cloud Host**: Use connection pooling (PgBouncer) for serverless or containerized environments:
  - Neon: `postgresql://user:pass@ep-sample-123-pooler.us-east-2.aws.neon.tech/sams_db?sslmode=require`
  - Supabase: Session mode port `5432` or Transaction pooler port `6543`.
- [ ] **SSL / TLS Enforced**: `sslmode=require` must be set in the production `DATABASE_URL` or `.env`.
- [ ] **Connection Limits**: Ensure `max_connections` accommodates peak attendance marking bursts (minimum 50-100 connections or PgBouncer with `default_pool_size = 20`).

---

## 2. Schema Execution Order

Execute schema files in strict sequential order:

```bash
# 1. Initialize tables, indexes, check constraints, foreign keys
psql "$DATABASE_URL" -f database/schema.sql

# 2. Seed initial roles, institution settings, and academic structure
# (Modify passwords before running in production)
psql "$DATABASE_URL" -f database/seed.sql
```

---

## 3. Database Indexes & Performance Verification

Verify that critical indexes exist on hot query paths:

```sql
-- Verify indexes
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
ORDER BY tablename, indexname;
```

Key indexes configured in `database/schema.sql`:
- `attendance_records(session_id, student_id)` — Unique constraint & lightning-fast duplicate checking
- `attendance_sessions(session_date, class_id, division_id)` — Rapid session filtering for reports and calendars
- `audit_logs(created_at, user_id)` — Efficient compliance and log search
- `students(roll_number, class_id, division_id)` — Class roster queries

---

## 4. Column Data Types & Casting Safety

- `audit_logs.metadata`: Stored as `JSONB` for deep querying.
  - SAMS `AuditService` automatically casts parameters via `CAST(:metadata AS jsonb)` when PostgreSQL driver is active.
- Timestamps: All timestamps use `TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP` to prevent timezone misalignment across multi-campus institutions.
- Password hashes: `VARCHAR(255)` storing secure Bcrypt/Argon2 hashes.

---

## 5. Automated Backups & Disaster Recovery

1. **Automated Snapshots**: Enable daily automated snapshots with at least 14–30 days retention.
2. **Point-in-Time Recovery (PITR)**: Enable WAL archiving for zero-data-loss rollback in the event of administrative error.
3. **Manual Backup Dump**:
   ```bash
   pg_dump "$DATABASE_URL" --format=custom --file=sams_backup_$(date +%Y%m%d).dump
   ```
4. **Restore Test**:
   ```bash
   pg_restore --clean --if-exists --dbname="$RESTORE_TARGET_URL" sams_backup_20260913.dump
   ```

---

## 6. Security Hardening

1. **Principle of Least Privilege**: Create a dedicated application user (`sams_app`) without `SUPERUSER` privileges:
   ```sql
   CREATE USER sams_app WITH PASSWORD 'strong_unique_password';
   GRANT CONNECT ON DATABASE sams_db TO sams_app;
   GRANT USAGE ON SCHEMA public TO sams_app;
   GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO sams_app;
   GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO sams_app;
   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sams_app;
   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO sams_app;
   ```
2. **Network Isolation**: Restrict database inbound access to the IP addresses of the application servers / VPC security group.
3. **Audit Log Protection**: Ensure the `audit_logs` table has no `DELETE` or `TRUNCATE` permissions granted to general application users.
