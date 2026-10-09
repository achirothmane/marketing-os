# Marketing OS — Permanent Project Record

**Repository:** `achirothmane/marketing-os`  
**Date of baseline:** 2026-10-09  
**State:** architecture documented; the pinned Mautic 7.2.1 SOURCE SNAPSHOT is imported; source-smoke CI passed; the Marketing OS runtime/integration is **not yet implemented or tested**.

This directory is the durable, version-controlled source of record for the Marketing Automation Suite / Marketing OS initiative, from the initial architecture through the C11 experiment design and subsequent executable work. Design decisions must never be mistaken for merged code or passing integration tests.

## Reading order

1. `00-origin-and-constitution.md` — purpose, constraints, product principles, and 13 layers.
2. `01-architecture-and-topology.md` — C1 convergence, C2 module layout, architecture boundaries.
3. `02-stage-register.md` — complete C1–C11 register, dependencies, and status.
4. `03-technical-contracts.md` — shared domain/evidence/event/identity rules.
5. `04-implementation-roadmap.md` — PR sequence, test gates, and implementation priorities.
6. `05-verification-and-falsification.md` — acceptance and failure scenarios.
7. `06-decisions-and-open-questions.md` — recorded decisions and unresolved risks.
8. `07-handoffs-and-change-log.md` — chronology and how to resume work.
9. `stages/` — per-stage preserved engineering decisions.

## Truth policy

- **DESIGNED**: specified in project discussions, not necessarily implemented.
- **IMPLEMENTED**: merged source code and associated commit link.
- **VERIFIED**: reproducible test results on a known commit/environment.
- **SHIPPED**: operational release proven in a deployment.
- No stage becomes VERIFIED based on conversation approval alone.

## Historical distinction

This repository was observed empty on 2026-10-09 before initialization. Its subsequent M0 import is a pinned upstream source snapshot, without full upstream Git ancestry. C4-01 contracts passed standalone CI; the C4 end-to-end slice and later engines remain unverified. See upstream provenance. Preserve the original upstream Mautic root layout when importing source; do not nest Mautic under `upstream/mautic/`.
