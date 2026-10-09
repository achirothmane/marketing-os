# C4-07B — Bounded MariaDB Messenger workers and poison handling

**Status:** C4-07B1 narrow capability MERGED/CI VERIFIED. PR #30 commit `2ea70105f456e57c354bdbe67dabe7906b21a5fd`; MariaDB 11.4 CI https://github.com/achirothmane/marketing-os/actions/runs/38004029403 PASS 11/11. Full C4 and C4-07B operational supervision/undecodable-wire quarantine remain OPEN.

## Implementation

Migration `005_messenger_worker_runtime.sql` adds an explicitly managed `mos_messenger_messages` durable Symfony Messenger table, `mos_identity_projection` production-shaped local read model, and `mos_messenger_failure` durable failure/quarantine records. Migration checksum protected by the same source-of-truth migrator as 001–004. **Doctrine auto_setup=false** ensures no surprise queue schema mutations during send/consume. Only a DBA-approved explicit migration creates these tables.

`MosDoctrineTransportFactory`: one queue name `mos_identity_{WorkspaceUuid}` per workspace in one dedicated MOS queue table, separate from existing Mautic transport. Isolation requires explicit workspace ID, with no global queue processor.

`MosBoundedReceiver`: handles one message at a time, commits the MOS Inbox marker and real `mos_identity_projection` record atomically, then ACKs the Messenger queue. Invalid tenant/type is REFUSED without ACK. Valid but repeatedly failing handler events increment an auditable, PII-free failure ledger and are QUARANTINED after three attempts; the queue ACK occurs **only after the quarantine decision has durably committed**. If ACK is lost, repeated pointer is safely ACKed again from the quarantine record. After a single unquarantined failure, the worker leaves the broker message unacknowledged to redeliver after the configured visibility/redelivery timeout.

`MosMessengerRelay::publishOne(workspace)` narrows local Outbox selection to an explicit tenant. A CLI worker cannot publish another workspace's events. Queue backpressure prevents enqueue when its visible depth reaches 1,000.

## CLI — manual SHADOW operations only

`php packages/identity/bin/messenger_shadow_worker.php WORKSPACE_UUID PUBLISH|CONSUME|HEALTH LIMIT`

Requires **both** `MOS_QUEUE_ENABLED=1` and `MOS_QUEUE_MODE=SHADOW`; default OFF. Database `MOS_TEST_DSN`, `MOS_TEST_DB_USER`, `MOS_TEST_DB_PASSWORD` must be explicit. Limit must be 1–100. Tenant-scoped MariaDB advisory lock prevents concurrent runs of the same operation for the same tenant. Outputs bounded counters, pending/oldest-age/attempts and quarantine depth; no raw contact PII or secrets. This is opt-in and must **not** be deployed/scheduled on production until security, secret management, operational readiness and rollback are reviewed. `SHADOW` may persist MOS data and queue/projection records, but never sends emails.

## Boundaries not yet proven

- These workers do NOT implement process supervision, scheduled deployment, resource fairness across all tenants, admin poison redrive, or automatic job orchestration.
- Valid pointer poison is quarantined. **Malformed wire messages rejected by the JSON serializer before the worker can inspect the event ID are still FAIL CLOSED, but no durable poison quarantine exists for those bytes yet**. Do not call generic poison handling complete.
- Queue isolation is by Messenger queue_name, not distinct tenant database accounts. Credentials are privileged across the database and require operational controls.
- Health counters are snapshot telemetry for the specified Workspace; they do not prove all business effects.
- C4-08 actual installed Mautic Contact->Outbox->Messenger->Inbox integrated test remains required; C4-05B/C source sweeper and C4-06A reconcile observation are independently verified but not hooked into scheduled workers here.
- No real email, ads, CRM provider effects or authorization to contact recipients. C6/C8 must precede sends.

## Falsification tests

MariaDB 11.4 tests should cover migrations/replay, workspace queue scoping, durable projection before ACK, replay deduplication, 3-attempt quarantine with stable error classification, re-delivered quarantined event, CLI OFF rejection, explicit HEALTH diagnostics, and preserved C4-01/02 tests.

Do not change status to PASS until exact GitHub Actions result and PR merge SHA are recorded.

## Verified acceptance evidence — 2026-10-09
- PR #30 merged `2ea70105f456e57c354bdbe67dabe7906b21a5fd`; 11/11 tests PASS in https://github.com/achirothmane/marketing-os/actions/runs/38004029403.
- Other latest PR-head workflows M0, C4-02, C4-03/04, C4-05, C4-07A all SUCCESS.
- Observed and repaired failure: C4-06A existing regression expected exactly four migration versions; migration 005 made it five. Updated assertion without changing reconciler behavior. Re-ran full PR-head checks successfully.
- This is **manual bounded operations ONLY**, not a supervised production service; unknown/invalid wire payload DLQ and crash-exit supervision remain separate.
