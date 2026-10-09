# 04 — Executable roadmap and PR order

## Stop designing more layers until the spine is proven
Current highest-priority goal: reproducible Contact -> Canonical Person -> Evidence -> DomainEvent -> transactional Outbox -> Inbox Consumer. C5–C11 remain preserved designs, NOT a mandate to implement ten engines in parallel.

## First prerequisite — proper upstream source import
Repository was empty at baseline. M0 source snapshot from pinned Mautic 7.2.1 has now been imported into root. Full upstream Git history was not imported; source provenance is pinned and documented. Steps:
1. [DONE] Choose and pin Mautic 7.2.1 release SHA `8cbb7ef874d52a411ae5a884f979acf6cc320181`; deployment compatibility itself still requires validation.
2. [DONE] Record upstream URL, GPL license/attribution and pinned snapshot method in provenance record.
3. [DONE] Import source into root, preserve owner README/docs/contracts and exclude upstream CI workflows.
4. [PARTIAL] Composer manifest validation, entrypoint syntax and canonical contracts passed; dependency installation and meaningful upstream tests have NOT yet run.
5. [TODO] Configure baseline install/tests, DB fixtures, CI upgrades and rollback before touching upstream behaviors.
Do not label fork complete until code and upstream ref appear in Git.

## C4-01 local seed
An 18-file local archive was prepared earlier (typed UUIDv7 IDs, Event/Evidence contracts, Clock, Actor, KnowledgeState, Money, PHP CLI tests). Its local test result was 13/13, with syntax checks performed, but source was not yet merged. Until the actual files are committed and CI verified, mark status LOCAL SEED ONLY. Do not count this as integrated Marketing OS.

## Proposed PR chains
- C4-01: Composer/autoload and typed canonical contract integration; PHP test runner and CI.
- C4-02: Person aggregate, legacy Contact reference, conservative external identity observations.
- C4-03: Workspace/Person/ExternalIdentity/LegacyMap migrations and concurrency uniqueness.
- C4-04: Evidence/Event/Outbox/Inbox persistence in one DB transaction.
- C4-05: modern Mautic Contact post-save adapter, no legacy domain leak.
- C4-06: backfill CLI with keyset pagination, dry run, checkpoint and identity collision report.
- C4-07: Outbox publisher and Inbox projection; redelivery and process-failure verification.
- C4-08: end-to-end falsification tests on real Mautic schema.
- C4-09: shadow rollout, health/counters, kill switch.

After C4 verified:
- C5: fact observations, per-field authority, verified identity bindings, shadow sync/reconciliation, safety suppression propagation.
- C6: purpose and consent/suppression ledgers, deterministic Contactability API and add-only Mautic DNC bridge.
- C7: typed audience IR, tri-state membership, transitions, immutable snapshots, Mautic Segment adapter.
- C8: effect creation, idempotent business keys, attempt/receipt/reservation, UNKNOWN reconcilers, capture adapter then provider.
- C9: immutable workflow IR, event enrollment, durable nodes/timers, condition and effect integration, shadow Mautic compiler.
- C10: metric/observation/financial ledgers, exposure and conversion registry, versioned attribution, evidence coverage.
- C11: randomization/holdouts, SRM and A/A checks, fixed-horizon ITT, economic incrementality, validation.

## One vertical journey is worth more than speculative breadth
Canonical Person -> C7 Audience -> C9 Wait -> C6 policy -> C8 CaptureEmailAdapter -> Receipt -> C10 conversion report -> C11 controlled holdout simulation.
Do NOT send real email or spend funds in an automated test.

## Promotion gates
DESIGNED -> source PR accepted -> local tests -> CI upstream regression -> integrated staging tests -> security/reliability review -> measured release.
If any gate fails, record failure and stop promotion. Every PR must link exact falsification cases and a rollback path.

## First reusable evidence record
Record PR, author, SHA, affected domains, migrations, tests, results, known limitations, and next blocker in 07-handoffs-and-change-log.md or an associated durable link. Do not replace history with only a current-state README.

## Verified C4-01 checkpoint — 2026-10-09
- PR #2 merged: https://github.com/achirothmane/marketing-os/pull/2
- Commit: 33fb9f490bcf85bc02a0a4635cd74cc27871ef1f
- GitHub Actions run: https://github.com/achirothmane/marketing-os/actions/runs/37867114064
- PHP 8.2 and 8.3 jobs both succeeded; each ran syntax lint and 13 contract tests.
- This is isolated package verification, NOT Composer-root Mautic integration or Contact->Outbox acceptance.

## M0 source import proof (2026-10-09)
- Upstream Mautic 7.2.1 SHA 8cbb7ef874d52a411ae5a884f979acf6cc320181.
- Source import job SUCCESS: https://github.com/achirothmane/marketing-os/actions/runs/37867965021
- Source-smoke CI SUCCESS: https://github.com/achirothmane/marketing-os/actions/runs/37868041910
- Verified: upstream root source paths present; GPL LICENSE.txt present; composer validate; PHP entrypoint lint; PHP 8.3 canonical contracts (13/13).
- NOT VERIFIED: Composer install/vendor, DB schema/migrations, server boot, upstream regression suite, C4 Contact integration.

## M0 dependency baseline and C4-02 (2026-10-09)
- PR #8 merged commit `1c0016c9066f36eb274ad0688ebfc89ada4f433d`: Composer install from lock, `composer check-platform-reqs`, upstream Lead unit test, contracts passed. CI https://github.com/achirothmane/marketing-os/actions/runs/37868814299
- Upstream GPL-3.0 deprecated SPDX warning intentionally kept and recorded; `composer validate --no-check-publish` passes, strict mode failed because of the warning only.
- PR #9 merged commit `11474dac28205d839c948bfb1ef7ff0a213b64aa`: Person lifecycle, source-scoped legacy mappings and planner. PHP 8.2/8.3 tests + lint pass: https://github.com/achirothmane/marketing-os/actions/runs/37868986534
- M0 isolated MariaDB install smoke pending PR #10; no database proof at the time of this record.
- Next C4-03 needs real DB uniqueness and concurrent transactions. Planning code cannot guarantee uniqueness on its own.

## M0 isolated database installation proven — 2026-10-09
- PR #10 merged commit `7b4cd1bf95df9f803ac2858d4205ca5c9cb49c69`.
- GitHub Actions run: https://github.com/achirothmane/marketing-os/actions/runs/37869113329 — SUCCESS.
- PHP 8.3 locked Composer install; Mautic `mautic:install` completed against isolated MariaDB 11.4.
- Installer created 126 Mautic database tables; `bin/console --version` confirmed Mautic 7.2.1 application/prod CLI boot.
- No real contacts or emails were used. CI database/admin credentials were ephemeral test-only values.
- **NOT YET VERIFIED:** full upstream test suite, mail/worker runtime, upgrade and rollback, MOS Person/LegacyMap tables, idempotent Contact bridge and transactional outbox.

## C4-03 verified database persistence — 2026-10-09
- PR #13 merged: https://github.com/achirothmane/marketing-os/pull/13, commit `bb019fd6fe124fb6a36257acb15ebb077ea7a6d2`.
- CI run https://github.com/achirothmane/marketing-os/actions/runs/37869768883: 13 C4-01 tests, 14 C4-02 tests, 11 MariaDB 11.4 tests passed.
- Checksum migration, scoped source uniqueness, composite cross-workspace FKs, duplicate rollback, optimistic CAS, two independent PHP workers racing same Contact ID; exactly 4 new MOS tables in isolated schema.
- Still absent: Evidence persistence + DomainEvent + transactional Outbox/Inbox and real Mautic Contact subscriber. DO NOT claim C4 end-to-end or send external emails.
- Next executable task: C4-04 atomic Evidence/Event/Outbox/Inbox within Person registration transaction, then C4-05 Mautic Contact adapter.

## C4-04 atomic Evidence/Event/Outbox/Inbox verified (2026-10-09)
- PR #15 merged: https://github.com/achirothmane/marketing-os/pull/15 at SHA `69ee68098110fc9478b5e0aed0b8ac732cd88a22`.
- CI: https://github.com/achirothmane/marketing-os/actions/runs/37871039165 on MariaDB 11.4; **52/52 tests PASS** (13 contracts, 14 identity, 11 C4-03 DB, 14 C4-04 DB). M0 smoke + C4-02 tests also passed for PR head.
- Migration 002 adds Evidence, DomainEvent, Outbox, Inbox; migrator protects 001/002 checksums, 8 MOS-owned tables total.
- New importer atomically writes Person+Map+Evidence digest+DomainEvent+Outbox; five pre-commit failure-injection tests roll back all new rows.
- Concurrent import of one simulated Contact from 2 PHP workers => exactly 1 Person/Evidence/Event/Outbox. ACK-lost delivery leaves pending event; inbox dedup suppresses second projection, handler failure rolls back inbox marker.
- **Safety boundary:** Digest is asserted by caller and must be attached to a genuine Mautic snapshot in C4-05; legacy C4-03 mapping-only registry bypass still exists for tests and MUST NOT be used by production adapter. No real Contact event, real Messenger transport or email send.
- Next: C4-05 verified Mautic Contact snapshot adapter, then actual integration/recovery. No C4 end-to-end PASS.

## C4-05A source-backed Mautic Contact observation verified (2026-10-09)
- PR #18 merged `7d205ed4ae365d495aaa1938a1875a4bfc52ed5a`, CI https://github.com/achirothmane/marketing-os/actions/runs/37876095115 PASS (12/12 live Mautic table bridge tests; all earlier scoped PR checks pass).
- Installer used Mautic 7.2.1 / MariaDB 11.4; test fixture was inserted directly into REAL Mautic `leads` table (mandatory is_published=1, points=0), not via LeadModel save event.
- Snapshot HMAC over allowlisted persisted values: no email/name/secret in MOS Event/Outbox payloads. SHADOW import persists MOS Person/Map/Evidence/DomainEvent/Outbox; replay reuses identity; source-change and missing evidence BLOCK for explicit future reconciliation.
- CLI OFF by default; no automatic Contact subscriber, live outbound effects or real queue transport. C4 full stage remains NOT VERIFIED until LeadModel event/timing, transport/recovery and missed-event backfill tested.
