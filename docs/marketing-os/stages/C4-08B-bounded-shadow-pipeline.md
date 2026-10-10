# C4-08B — Bounded committed-source → Messenger → Inbox coordinator

Status: VERIFIED in disposable Mautic 7.2.1/MariaDB 11.4 SHADOW integration scope only. PR #38 merged `7115710bc94780d1368a47abdeabdd80fe17cb49`; [CI run 38049177697](https://github.com/achirothmane/marketing-os/actions/runs/38049177697) 13/13 PASS. No deployed scheduler/production sends or overall C4 operational PASS.

## Capability

`packages/identity/bin/run_shadow_pipeline.php WORKSPACE_UUID SCAN_BATCH_SIZE SCAN_MAX_BATCHES QUEUE_CYCLES QUEUE_BUDGET`

Explicit operator invocation requires all six flags: `MOS_PIPELINE_ENABLED=1`, `MOS_BRIDGE_MODE=SHADOW`, `MOS_SCHEDULED_SHADOW_ENABLED=1`, `MOS_SUPERVISOR_ENABLED=1`, `MOS_QUEUE_ENABLED=1`, `MOS_QUEUE_MODE=SHADOW`.

Requires strong `MOS_SNAPSHOT_HMAC_KEY` and `MOS_DLQ_KEY_B64` (32 decoded bytes). Scanner batch 1–1000, batches 1–10, queue cycles 1–3, queue work budget 1–20, source subprocess 90-second timeout and queue subprocess 120-second timeout. All phases are restricted to a single explicit Workspace and mutually excluded by a workspace-scoped advisory lock.

Runs the existing committed-source scan as a separate process, checks zero BLOCKED results, then runs the existing wire quarantine / durable publisher / idempotent consumer supervisor. It cannot advertise a full source sweep if scan reported only a partial pass. An error in either phase refuses a success receipt. Already committed Person, Event, Outbox, Inbox survive crashes for idempotent replay.

No raw email, source payload, secret, HMAC, or message body is included in the returned receipt. Only bounded counters are returned.

## Limits

This is a manually invoked finite SHADOW coordinator, not installed cron/systemd/Kubernetes deployment. Source scan may commit multiple independent records and is NOT a single distributed transaction with Messenger. No live marketing messages, ads or provider writeback; C5/C6/C8 authority/consent/effect controls remain absent. Key rotation, DLQ review/redrive, deployed worker supervision and production hardening remain separate.

## Falsification

CI runs installed Mautic 7.2.1 on disposable MariaDB 11.4 and tests: disabled-by-default/caps/required DLQ key; rolled-back Contact invisibility; committed source → HMAC evidence → Person+Outbox → real Messenger queue → Inbox projection; repeat/tenancy; encrypted malformed-wire quarantine; hard process exits after Person COMMIT before scan cursor, after queue insert before Outbox ACK, and after Inbox COMMIT before queue ACK; changed source blocks queue phase and preserves pending Outbox; restoration safe recovery; concurrent coordinator lock. The Mautic fixture is direct test-only SQL to the real leads table, not an automated LeadModel subscriber.

Mark this C4-08B scope VERIFIED only with successful CI and merged PR SHA; full C4 operational readiness remains unverified.

## Accepted test evidence — 2026-10-10
- Code merged SHA `7115710bc94780d1368a47abdeabdd80fe17cb49`; https://github.com/achirothmane/marketing-os/actions/runs/38049177697 passed 13/13 contiguous source-to-Inbox recovery tests.
- All current PR-head M0/C4-02/C4-03/04/C4-05/C4-07B1/B2 workflows returned SUCCESS.
- Test-only SQL fixture in installed Mautic leads; committed read excludes open source transactions. Crash tests used real child exit(77) and explicitly accelerated test-only database lease expiry.
- Evidence scope does NOT cover a continuously deployed supervisor, production user authorization, source mutation acceptance, operator key rotation or marketing provider effects.
