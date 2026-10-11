# C4-07B3B2 — Runtime database bootstrap and role boundary

**Status:** IMPLEMENTED on a feature branch; MariaDB acceptance and all PR-head regressions PENDING. **No production deployment or claim of actual tenant isolation by database grants.**

## Architectural change

All `packages/identity/bin/*.php` runtime entrypoints now require `packages/identity/bootstrap/runtime.php` instead of the test bootstrap. No runtime code reads `MOS_TEST_DSN`, `MOS_TEST_DB_USER` or `MOS_TEST_DB_PASSWORD`. `packages/identity/tests/bootstrap.php` may explicitly map disposable test fixture credentials into the three runtime role profiles **only when executing tests**; production entrypoints never import that file.

Three independent TCP+utf8mb4 connection profiles, with no implicit role fallback:

- `MOS_RUNTIME_READ_DSN`, `MOS_RUNTIME_READ_DB_USER`, `MOS_RUNTIME_READ_DB_PASSWORD` — read-only preflight, DLQ INVENTORY and VERIFY.
- `MOS_RUNTIME_WRITE_DSN`, `MOS_RUNTIME_WRITE_DB_USER`, `MOS_RUNTIME_WRITE_DB_PASSWORD` — scoped SHADOW Contact, audit, Messenger, DLQ ROTATE and coordinated worker mutations.
- `MOS_RUNTIME_MIGRATE_DSN`, `MOS_RUNTIME_MIGRATE_DB_USER`, `MOS_RUNTIME_MIGRATE_DB_PASSWORD` — schema DDL through `migrate.php`, requiring separate `MOS_SCHEMA_MIGRATION_ENABLED=1`.

These are *configuration boundaries*. Database grants, managed secrets, host/network identity and multi-tenant row security MUST be supplied and verified by the operator. Never use one privileged credential for all three in production. No production credentials in code/CLI argv, CI workflow, Git history or logs.

## Least-privilege verification

The C4-07B3B2 CI job creates disposable MariaDB 11.4 users with actual GRANTs. The READ account receives SELECT on precisely the tables accessed by the readiness preflight, not write privileges. The WRITE account receives only DML on MOS tables and no CREATE/ALTER/DROP permission; there is a separate MIGRATE profile to administer schema. Read-only preflight must function as the READ user, while the same user cannot UPDATE. A DML-only account must be able to insert/delete a disposable scoped Workspace row and must be refused CREATE TABLE. Existing integration tests continue with their explicit ephemeral test accounts; they do not prove production least-privileged full pipeline operation.

The code rejects missing credentials even when `MOS_TEST_*` are present, unknown role, unsafe MySQL DSN forms, absent WRITE when READ is supplied, and migration without explicit opt-in or MIGRATE credentials. Error paths must never echo credentials, raw SQL or Contact data.

## Remaining operational barriers

- Actual deployed secrets manager and credential rotation (including encrypted DLQ keys and backups).
- Real isolated deployment account provisioning, complete MOS/Mautic table grants and per-Workspace data authority.
- Supervised service processes, restart budgets, resource limits and crash/upgrade/restore drills.
- Manual encrypted DLQ case review and controlled authorized disposition without automatic redrive.
- C5/C6/C8 policy/contactability and external effects: all unauthorized and disabled.

**C4 as a whole remains NOT VERIFIED, NOT SHIPPED.**
