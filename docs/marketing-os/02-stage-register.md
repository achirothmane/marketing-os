# 02 — Stage register and dependency map (C1–C11)

**Baseline date:** 2026-10-09.
**Baseline repo inspection:** empty GitHub repository before documentation initialization. None of C3–C11 is verified as integrated or deployed in this repository.

| Stage | Purpose | Dependency | Design status | Runtime verification |
|---|---|---|---|---|
| C1 | Convergence: compress broad capability catalog into shared engines/kernel/packs | concept | DESIGNED | NOT APPLICABLE (architecture) |
| C2 | Repository topology and pinned Mautic source import | C1 | DESIGNED + SOURCE IMPORTED | SOURCE, COMPOSER, LEAD UNIT + MARIA DB INSTALL PASS (126 tables); FULL REGRESSION UNVERIFIED |
| C3 | Canonical data model, evidence/events, Person, legacy mapping, outbox/inbox | C2 | DESIGNED | NOT VERIFIED |
| C4 | Contact -> Person -> evidence -> event -> outbox -> consumer vertical slice | C3 | DESIGNED; C4-01/02/03/04, C4-05A/B/C, C4-06A and C4-07A real Messenger transport and C4-07B1 bounded SHADOW workers merged, scoped CI PASSED (PRs #2, #9, #13, #15, #18, #20, #22, #24, #27) | C4 end-to-end NOT VERIFIED: LeadModel save timing tested; C4-06A source observations verified; no operational Mautic subscriber/scheduler or deployed worker supervisor; durable transport and opt-in bounded CLI tested in real MariaDB CI, authorized delete handling not implemented |
| C5 | Identity synchronization, fact authority, observations/conflicts, merge/split and DNC distinction | C4 | DESIGNED | NOT IMPLEMENTED |
| C6 | Purpose-aware policy, consent, suppression, preferences, contactability | C5 | DESIGNED | NOT IMPLEMENTED |
| C7 | Audience definitions, UNKNOWN membership, transitions and frozen snapshots | C5/C6 data contracts | DESIGNED | NOT IMPLEMENTED |
| C8 | Effect intent, reservation, attempt, receipts, provider retries and reconciliation | C6 (C7 for journey use) | DESIGNED | NOT IMPLEMENTED |
| C9 | Durable workflow version, nodes, timers, tasks, event waits, effect references | C7/C8 | DESIGNED | NOT IMPLEMENTED |
| C10 | Observations, exposures, conversions, revenue/cost ledger and versioned attribution | C8/C9 | DESIGNED | NOT IMPLEMENTED |
| C11 | Random assignment, holdouts, statistical quality, causal estimates and incremental economics | C7–C10 | DESIGNED | NOT IMPLEMENTED |

### Important: conversation phase numbers do not equal implementation order
C6 policy is evaluated by C8 Effects; C7 audiences can be implemented after C5 attributes, while C6 can be integrated progressively. C8 supports C9 but is not the same as a workflow. C10 and C11 can have test harnesses before fully integrated production journeys, but must never claim operational results prematurely.

### Sequence of executable milestones
M0: import/track upstream and obtain baseline test run.
M1: C4-01 typed contracts with GitHub CI (from local test seed).
M2: C3/C4 legacy mapping, evidence, Person, transactionally atomic event/outbox, idempotent consumer.
M3: C5 field observation and C6 suppression/permission shadow evaluation.
M4: C7 audience membership / snapshot and C8 capture-only effects.
M5: C9 single native durable welcome journey under test.
M6: C10 measured test journey / economics.
M7: C11 A/A and treatment/holdout simulation, eventually a sufficiently powered real experiment.

### Stage evidence status template
For every stage PR replace UNKNOWN placeholders in the stage spec with:
- code repository SHA and PR
- exact command/test environment
- test result and logs/artifacts
- known failures and exceptions
- upgrade/regression outcomes
- state: DESIGNED / IMPLEMENTED / VERIFIED / SHIPPED.

## 2026-10-10 — C4-08 integrated Shadow proof
- [x] Real Mautic 7.2.1 persisted Contact->Evidence/Event->Outbox->Messenger->Inbox/Projection, opt-in SHADOW across independent PHP subprocesses. PR #33, https://github.com/achirothmane/marketing-os/actions/runs/38028110845, 13/13 PASS.
- [ ] Supervised background workers, invalid-wire DLQ, automatic LeadModel/sweeper pipeline, full production runbook, C5/C6/C8 controls. Therefore top-level C4 status remains NOT VERIFIED as a deployed operational system.

## 2026-10-10 — C4-07B2 malformed wire encrypted quarantine
- [x] Separate MariaDB migration 006, tenant-locked transactional encrypted archive for invalid Messenger JSON/header/wrong-workspace bytes, no deletion if archival fails; ciphertext hash avoids low-entropy plaintext digest leaks.
- [x] Explicit bounded SHADOW supervisor and WIRE_SCAN/PUBLISH/CONSUME subprocess tests: PR #35, CI https://github.com/achirothmane/marketing-os/actions/runs/38035922092, 12/12 PASS. Previous M0/C4 tests passed on same PR head.
- [ ] Real deployed worker supervisor, key-management rotation and archive review/redrive approval, oversized payload handling runbook, automatic sweeper→queue operations. Full C4 operational stage remains NOT VERIFIED.

## 2026-10-10 — C4-08B finite orchestration proof
- [x] Bounded source scan → encrypted-wire check → Messenger publish → Inbox consume integrated in one explicit SHADOW coordinator, PR #38, CI https://github.com/achirothmane/marketing-os/actions/runs/38049177697 (13/13 PASS).
- [x] Five/six independent operator opt-ins, per-Workspace advisory lock, bounded batches/cycles/time, safe replay after three simulated process failures, no raw PII output, no provider sends.
- [ ] Deployed scheduler/system supervisor, external key lifecycle, DLQ human redrive, production auth/fairness and C5/C6/C8 effects remain open; full C4 operational acceptance NOT VERIFIED.

## 2026-10-10 — C4-07B3A encrypted DLQ verification and key rotation
- [x] Operator-only per-Workspace inventory, paginated authenticated verification and recoverable key rotation; MariaDB 11.4 [CI](https://github.com/achirothmane/marketing-os/actions/runs/38058452138) 14/14 PASS, PR #41 merged `63713aa2b1ca451d7083c43cbfeb55cb3d8c4be2`.
- [ ] Production key escrow, secret manager and rotation/backup drills, deployed supervised worker, DLQ review/redrive with approval and operator audit. C4 overall remains NOT VERIFIED; no customer sends.
