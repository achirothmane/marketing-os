# 02 — Stage register and dependency map (C1–C11)

**Baseline date:** 2026-10-09.
**Baseline repo inspection:** empty GitHub repository before documentation initialization. None of C3–C11 is verified as integrated or deployed in this repository.

| Stage | Purpose | Dependency | Design status | Runtime verification |
|---|---|---|---|---|
| C1 | Convergence: compress broad capability catalog into shared engines/kernel/packs | concept | DESIGNED | NOT APPLICABLE (architecture) |
| C2 | Repository topology and pinned Mautic source import | C1 | DESIGNED + SOURCE IMPORTED | SOURCE, COMPOSER, LEAD UNIT + MARIA DB INSTALL PASS (126 tables); FULL REGRESSION UNVERIFIED |
| C3 | Canonical data model, evidence/events, Person, legacy mapping, outbox/inbox | C2 | DESIGNED | NOT VERIFIED |
| C4 | Contact -> Person -> evidence -> event -> outbox -> consumer vertical slice | C3 | DESIGNED; C4-01/02/03/04, C4-05A/B/C and C4-06A observations merged, scoped CI PASSED (PRs #2, #9, #13, #15, #18, #20, #22, #24) | C4 end-to-end NOT VERIFIED: LeadModel save timing tested; C4-06A source observations verified; no operational subscriber/scheduler, queue transport or authorized delete handling |
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
