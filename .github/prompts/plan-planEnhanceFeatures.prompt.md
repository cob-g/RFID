## Plan: Admin Logs Enhancements & Audit Trail

Extend the admin dashboard with log filters/search, daily activity summaries, an audit trail with CSV export, and quick export shortcuts. Use a shared filter builder so list views and CSV exports stay consistent, and add audit logging in the admin actions you called out.

**Steps**
1. Phase 1 - Schema and logging foundation: add an audit_log table to the SQL backup (id, actor info, action, target info, details, ip, created_at) plus indexes and FK to users; add auditLogWrite in [db.php](db.php) modeled after authLogWrite with column checks and string limits. *Blocks steps 3 and 5.*
2. Phase 2 - Log filter helpers: in [admin_dashboard.php](admin_dashboard.php), add filter parsing helpers for attendance/auth/audit (date range, action, user/target, device/IP) and build WHERE clauses plus bound params using dbBindParams. Normalize dates to start/end of day for timestamp columns. *Blocks step 3.*
3. Phase 3 - Logs UI + queries: apply filters to attendance events/sessions, auth events/sessions, and the new audit log list queries plus counts; add filter forms and a new Admin Actions table with pagination and CSV export that preserves filters and clears pagination when filters change. *Depends on steps 1-2.*
4. Phase 4 - Dashboard summaries: compute today's TIME_IN count, TIME_OUT count, active RFID sessions, failed logins today, and active web sessions; render a Today's Activity card row beneath the existing summary cards, guarded by table-existence checks. *Parallel with step 3.*
5. Phase 5 - Action audit hooks: write audit entries for system hours updates, user edits (username/role/password change flags), user deletion (capture target before delete), and reservation releases (seat/computer label). *Depends on step 1.*
6. Phase 6 - Export shortcuts: add an Exports & Backups panel in the dashboard with CSV links for users, seats, computers, and audit log; implement export handlers in [admin_dashboard.php](admin_dashboard.php) (audit export uses the same filters). *Depends on step 3 for audit export.*

**Relevant files**
- [admin_dashboard.php](admin_dashboard.php) - filter helpers, filtered queries, audit log table UI, dashboard summary cards, export handlers, export shortcuts
- [db.php](db.php) - add auditLogWrite helper
- [scc_library_backup.sql](scc_library_backup.sql) - add audit_log schema and indexes
- [edit_user.php](edit_user.php) - write audit log on updates
- [delete_user.php](delete_user.php) - fetch user, delete via prepared statement, write audit log
- [free_item_admin.php](free_item_admin.php) - audit log for reservation release

**Verification**
1. Load [admin_dashboard.php](admin_dashboard.php) and confirm no PHP errors after adding helpers and filters.
2. In Logs, apply attendance and auth filters (date range, action, user, device/IP) and confirm pagination counts and CSV exports match filtered results.
3. Trigger audit actions (system hours update, edit user, delete user, release seat/computer) and verify entries appear in Admin Actions and the audit CSV.
4. Check the Today's Activity cards update correctly with existing data and show zeros when tables are missing.
5. Use the dashboard Exports & Backups links to download users, seats, computers, and audit CSVs.

**Decisions**
- Auth filters include user search plus IP address.
- Audit filters include Actor and Target search fields.
- Audit action list uses: user_update, user_delete, reservation_release, system_hours_update.
- Store audit action as a flexible string (not enum) and details as text (JSON string), to avoid schema changes as actions grow.

**Further Considerations**
1. Should filters apply to both event and session tables, or only events? Recommendation: apply to both, with action filters limited to event tables.
2. Should CSV exports for attendance/auth always respect current filters? Recommendation: yes, to match on-screen results.
3. Should delete_user.php gain CSRF protection and a POST flow? Recommendation: yes if you want to harden destructive actions beyond this scope.
