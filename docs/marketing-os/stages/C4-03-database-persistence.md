# C4-03 — MariaDB persistence, scoped mapping and concurrency

Status: C4-03 source is on PR #13; promote to VERIFIED only with passing GitHub Actions CI.

## Storage boundary

Four Marketing OS-owned InnoDB tables:
- mos_schema_migration: version, checksum and applied_at.
- mos_workspace: workspace UUID identifier.
- mos_person: scoped Person UUID, lifecycle state, version, optional same-tenant merge target.
- mos_legacy_entity_map: unique (workspace, source system, entity type, external ID) to one Person UUID and an Evidence UUID placeholder.

Enforced by MariaDB:
- Foreign keys linking mappings only to Person in the SAME workspace.
- Legacy entity source key unique within workspace, with case-sensitive source keys.
- CHECK constraints for state, version and merge-target shape.
- Optimistic compare-and-swap on Person state updates.
- One transaction for new Person plus mapping (losing concurrent duplicate insert rolls back the tentative Person and reads winning mapping).

## Explicit migration / test

Migration uses an advisory lock, a checksum registry and explicit DDL. It does not modify Mautic tables or run on application startup. MySQL DDL is not transactionally rolled back: interrupted migrations require investigation; recording migration version only after schema succeeds avoids claiming nonexistent rollback.

Environment variables MOS_TEST_DSN, MOS_TEST_DB_USER and MOS_TEST_DB_PASSWORD are required for the test and migration CLI; no default production credentials.

Run on a DISPOSABLE database only:

    php packages/identity/bin/migrate.php
    php packages/identity/tests/db_integration.php

GitHub Actions runs the suite against isolated MariaDB 11.4, including two independent PHP worker processes attempting to claim the same Contact number.

## Capability boundary and falsification

This C4-03 stage stores Person and source mapping, not a real Mautic Contact snapshot. No raw email is required. There is no Evidence table or referential integrity for evidence_id until C4-04; no DomainEvent/Outbox/Inbox in the same transaction yet. No Mautic Contact subscribers or production import are authorized. Mark ERASED is a domain state marker, NOT regulatory deletion of actual data.

Next: C4-04 must extend this single transaction to Evidence + DomainEvent + Outbox/Inbox; C4-05 then adds Mautic read-only snapshot adapter, replay and recovery tests.

## Acceptance evidence

Link exact PR #13 SHA, CI run, migration output, tested MariaDB version, concurrent test results and limitations here before marking verified. Domain design PASS is not database integration PASS.
