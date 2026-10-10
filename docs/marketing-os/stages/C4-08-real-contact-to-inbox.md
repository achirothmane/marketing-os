# C4-08 — One contiguous real persisted Mautic Contact → Inbox acceptance

**Status:** VERIFIED for narrow, opt-in SHADOW integration. PR #33 merged `6c298593c1a3d0165779c046819b9c9964dbf8b5`; https://github.com/achirothmane/marketing-os/actions/runs/38028110845 **13/13 PASS**. Full operational C4 and production shipping are NOT VERIFIED.

## Acceptance path under test

Mautic 7.2.1 installed on ephemeral MariaDB 11.4 → persisted row in actual \`leads\` table → independent committed-read \`PdoMauticContactSnapshotReader\` → workspace-specific HMAC evidence → atomic Person, Legacy Map, Evidence, DomainEvent and Outbox → manual opt-in \`PUBLISH\` in a NEW process → durable Symfony Messenger Doctrine transport queue → manual opt-in \`CONSUME\` in ANOTHER process → Inbox marker + identity projection transaction → broker ACK.

Two Workspaces independently import the same source Contact ID and produce different canonical Persons. Source Contact fields and HMAC key never enter Messenger pointer, generic event payload or projection. Publisher/consumer CLI is OFF by default; tests enable SHADOW exclusively for each subprocess. No external email, channel effect, or contact writeback.

## Crash/falsification

- Insert source Contact in a transaction without COMMIT: independent connection cannot observe it; after source rollback, **no phantom Person** is created.
- C4-04 commits durable Outbox before a publisher ever runs: restarting a worker recovers the event.
- Child process calls actual Doctrine transport send and exits 77 BEFORE outbox publication ACK. Queue message exists and local outbox remains pending; once claim lease expires, publisher resends pointer; Inbox commits ONE projection.
- Child consumer process commits the Inbox/projection, then exits 77 BEFORE transport ACK; after test-only simulated 60-second broker visibility timeout, a new process reconsumes pointer and hits a dedup no-op.
- Two Workspaces with same persisted Contact number yield separate Persons, isolated queues, Inbox records and projections.
- Replay, source update conflict and PII check are enforced.

The test intentionally **inserts test-only fixtures directly into Mautic's real \`leads\` table**, not via \`LeadModel\`. Upstream LeadModel event timing has separate C4-05B probes. This validates the committed-source read pipeline, not automatic event subscription.

## CI

\`.github/workflows/c4-08-source-to-inbox.yml\` installs pinned Mautic 7.2.1, runs MariaDB 11.4, performs all above scenarios and reports exact failure stage. A PASS demonstrates one contiguous, opt-in SHADOW vertical integration across real installed Mautic tables and actual Messenger transport using separate PHP processes.

## Not yet released / remaining gates

- C4-07B2: malformed-wire poison handling, DLQ, worker supervision and recoverable runbook; no supervised production worker exists.
- C4-08B: scheduled source sweeper→operational queue worker and full crash-recovery under automatic orchestration, including source updates/deletion resolution.
- External effects/marketing send require C5 source authority, C6 consent and C8 effects/receipt reconciliation first.
- Upstream regression coverage, upgrade/rollback, secret rotation and deployment review are not implied by this integration test.

Record exact PR merge SHA, CI run and count before marking **C4-08 verified in its SHADOW integration scope**. Do NOT mark full C4 or production SHIPPED on this evidence alone.

## Verified evidence (2026-10-10)
- Source PR #33 merge `6c298593c1a3d0165779c046819b9c9964dbf8b5`, CI https://github.com/achirothmane/marketing-os/actions/runs/38028110845 with 13/13 integrated cases.
- All other PR-head checks M0, C4-02, C4-03/04, C4-05 and C4-07B passed.
- Recovery used real independent PHP subprocess exits(77); clock/lease expiry accelerated explicitly in the disposable CI database.
- SOURCE fixture is persisted directly in a real Mautic leads table (not a LeadModel call). Committed-read source bridge prevents phantom imports after rollback; operational automation and failed-message DLQ remain open.
