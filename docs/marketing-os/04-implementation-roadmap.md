# 04 — Executable roadmap and PR order

## Stop designing more layers until the spine is proven
Current highest-priority goal: reproducible Contact -> Canonical Person -> Evidence -> DomainEvent -> transactional Outbox -> Inbox Consumer. C5–C11 remain preserved designs, NOT a mandate to implement ten engines in parallel.

## First prerequisite — proper upstream source import
Current repository was empty at baseline, not an actual fork. Preserve Mautic upstream root and history when importing to this already-created repo. Steps:
1. Choose and pin a specific Mautic 7.x release/commit compatible with the deployment; capture its commit SHA.
2. Record upstream URL, license/attribution and import method in an ADR.
3. Import files into repository root without nesting. Reconcile any README collision; preserve existing docs/marketing-os.
4. Verify Composer install/lock integrity and minimal upstream automated tests.
5. Configure upgrades, test containers and rollback before touching upstream behaviors.
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
