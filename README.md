# Marketing OS — Marketing Automation Suite

**Status (2026-10-09):** Mautic 7.2.1 source imported; basic install/DB smoke verified. C4-01–C4-04 passed scoped PHP/MariaDB tests. C4-05A source-backed SHADOW import (12 tests) and C4-05B committed-source recovery and real LeadModel hook/rollback probes (7+3 tests) passed. Bounded manual/cron-ready scanner verified (C4-05C). C4-06A change/missing-source observation and review-case history passed 19 MariaDB tests. No automatic source deletion/merge, hosted cron, Messenger transport or full C4 end-to-end; these remain NOT VERIFIED.

This is the canonical repository for the self-hosted Marketing OS initiative, starting from the Mautic operational substrate and evolving toward canonical Identity, Consent, Audiences, Effects, Durable Workflows, Measurement, Economics and Experiments.

## Begin here
- [Permanent documentation index](docs/marketing-os/INDEX.md)
- [Origin and project constitution](docs/marketing-os/00-origin-and-constitution.md)
- [Architecture and repository topology](docs/marketing-os/01-architecture-and-topology.md)
- [C1–C11 stage register](docs/marketing-os/02-stage-register.md)
- [Execution roadmap](docs/marketing-os/04-implementation-roadmap.md)
- [Falsification tests](docs/marketing-os/05-verification-and-falsification.md)
- [Decision and risk register](docs/marketing-os/06-decisions-and-open-questions.md)
- [Chronology and next handoff](docs/marketing-os/07-handoffs-and-change-log.md)
- [Detailed stage specifications](docs/marketing-os/stages)
- [Machine-readable checkpoint](docs/marketing-os/project-status.json)

## Rules of evidence
DESIGNED means specified in discussion. IMPLEMENTED requires merged code; VERIFIED requires reproducible tests on a known SHA; SHIPPED requires deployment proof. No stage is silently marked complete.

## Upstream source
The repository started EMPTY but now contains the root Mautic 7.2.1 source snapshot (upstream SHA `8cbb7ef874d52a411ae5a884f979acf6cc320181`). This is a SOURCE IMPORT, not the full upstream git ancestry or an operational installation. See [upstream provenance](docs/marketing-os/upstream/MAUTIC-PROVENANCE.md). Distribution structure, Composer manifest validation, PHP entrypoint syntax and 13 canonical contract tests passed in [M0 source smoke CI](https://github.com/achirothmane/marketing-os/actions/runs/37868041910); Composer install and isolated MariaDB installer smoke passed; full upstream functional/regression suite and operational deployment remain unverified.

One central Dots coordinates long-running projects; this repo owns its own history, contracts and evidence.

### Latest bounded worker checkpoint — C4-07B1 (2026-10-09)
PR #30 merged (`2ea70105f456e57c354bdbe67dabe7906b21a5fd`). Actual MariaDB 11.4 worker CI 11/11 PASS: https://github.com/achirothmane/marketing-os/actions/runs/38004029403. Operator-only, double-opt-in PUBLISH/CONSUME/HEALTH with workspace isolation, real persistent projection and valid-event failure quarantine. No malformed-wire DLQ, deployed worker supervisor or full Mautic Contact→Inbox end-to-end proof; C4 overall and commercial sends are not operationally verified.

### C4-08 verified source-to-queue-to-inbox integration (2026-10-10)
PR #33 merged as `6c298593c1a3d0165779c046819b9c9964dbf8b5`. On installed Mautic 7.2.1 + MariaDB 11.4, https://github.com/achirothmane/marketing-os/actions/runs/38028110845 PASS **13/13** scenarios, including source transaction rollback invisibility, tenant isolation, PII-free HMAC/event pointer, real separate-process PUBLISH/CONSUME, lost send ACK with lease expiry, lost consumer ACK after Inbox commit, and safe replay. Fixtures were committed by test-only SQL into the REAL Mautic `leads` table; no active LeadModel hook, deployed supervisor, malformed-wire DLQ, production sends or full C4/SHIP claim. Next C4-07B2 and C4-08B.

### C4-07B2 encrypted malformed-wire quarantine (2026-10-10)
[PR #35](https://github.com/achirothmane/marketing-os/pull/35) merged at `655cfc0213f0425a7b5469c90691ec20e121e5ff`; [MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38035922092) **12/12 PASS**. Separate migration 006 and XChaCha20-Poly1305 encrypted archive preserve malformed Messenger bytes atomically before queue deletion, with no plaintext body/headers in DLQ. A finite double-opt-in SHADOW supervisor runs bounded WIRE_SCAN→PUBLISH→CONSUME, stopping for oversized wires or missing secret. All PR-head Mautic and earlier C4 workflows green. This is not an installed production daemon, automatic redrive or authorization to send marketing messages.

### C4-08B finite Mautic source-to-Inbox coordinator (2026-10-10)
PR #38 merged `7115710bc94780d1368a47abdeabdd80fe17cb49`; [integrated MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38049177697) 13/13 PASS. An explicitly authorized, bounded SHADOW CLI joins committed source scanning to encrypted wire inspection, durable Symfony Messenger and idempotent Inbox projection in independently executed PHP processes. Tests include crash after Person COMMIT before cursor, after Messenger send before ACK and after Inbox COMMIT before ACK, tenant isolation and restoration after source mismatch. Not an automated deployed worker or approval for commercial sends. Full C4 remains operationally UNVERIFIED.
