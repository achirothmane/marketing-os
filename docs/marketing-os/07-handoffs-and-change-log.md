# 07 — Timeline, handoff and change log

## Chronology reconstructed from project discussions
- **2026-10-06:** Marketing Automation Suite named as active direction. Mautic selected as operational runtime base. Broad layered Marketing OS vision including final Platform Layer (Layer 13): deployment, queue workers, retries, extension and recovery. Mail Transition Engine v0.8 Unknown Outcome Reconciler described as designed/tested in a separate conversation; do NOT assume it exists in this repository.
- **2026-10-06:** Mail Transition Engine flow: Audience Truth -> Sender Truth -> Message Truth -> Ramp -> Lease -> Atomic RESERVE -> SendDock -> COMMIT/RELEASE; ambiguous results remain RESERVED and need signed webhook/reconciliation, possibly KEEP_RESERVED/AMBIGUOUS/CONFLICT. Previously reported as end-to-end in dialogue, but code ref in THIS repo not verified.
- **2026-10-06–08:** Scope broadened to Social, Content, SEO, Ads, Affiliate, CRM, Analytics, Intelligence, Economics and Platform. Conceptual large feature catalog compressed under C1 into Platform Kernel + 13 shared engine domains + packs/adapters. C2 chose in-place Mautic root hard-fork topology.
- **2026-10-08:** C3 canonical contracts designed, including typed UUIDv7, Person, External Identity, Evidence, Event, transactional outbox/inbox and legacy mapping. Important email collision/schema inconsistency flagged for resolution.
- **2026-10-08–09:** C4 first executable vertical slice designed with 9 PRs; C5 fact authority/synchronization, C6 policy/contactability, C7 membership, C8 effect receipts, C9 durable workflows, C10 economic measurement, C11 experiments and causal inference designed sequentially. Discussion often said 'PASS تصميميًا' meaning design-only.
- **2026-10-09:** Convergence inspection found no Marketing OS repository among the then-listed account repositories. A local bootstrap archive was created, reportedly with 13 PHP contract tests passing and syntax checks. The archive was NOT uploaded as code to GitHub at that point.
- **2026-10-09:** User supplied newly created GitHub repository achirothmane/marketing-os and directed the complete history/sequence to be saved there. Connector inspection found it EMPTY, without upstream Mautic tree or prior implementation PRs. This documentation baseline is the first repository record.

## Current handoff (update every substantive PR)
**Last confirmed remote status:** documented only, after empty-repository initialization.
**Next work:** preserve and review documentation; import pinned Mautic 7.x upstream into existing repository root; integrate local C4-01 contracts; execute initial real contact/evidence/outbox/inbox slice and gate.
**Do not start:** building C12 or claiming C4–C11 integration until executable evidence exists.

## Central Dots coordination contract
Dots is one cross-project coordination system, not a separate technical owner per repo. Each repo should publish current phase, last SHA, verified facts, unresolved decisions, tests, next bounded work, and external dependencies. Dots may read and coordinate this record but cannot infer commercial traction or test passes from planned documents alone.

## Maintenance rule
At each merged PR:
1. Update stage status and SHA/test reference in 02-stage-register.md.
2. Record decisions and supersession in 06-decisions-and-open-questions.md.
3. Append timestamped concise changelog/handoff with next blocker here.
4. Add/update stage-specific acceptance tests and affected cross-stage dependencies.
5. Record any external evidence, competitive findings, or demand signal separately rather than passing them off as implementation.

- **2026-10-09:** PR #1 documentation archive merged into main (e67b8e49745eff6545b8b7624b9d791df6900fd3); 25 files, detailed C1–C11 stages, governance, timeline, machine-readable checkpoint.
- **2026-10-09:** PR #2 isolated C4-01 typed contracts merged (33fb9f490bcf85bc02a0a4635cd74cc27871ef1f); GitHub Actions run 37867114064 passed on PHP 8.2/8.3. This is NOT the C4 vertical slice; M0 upstream source import (issue #3) remains blocking.
- **2026-10-09:** Master execution issue #4 opened for durable stage tracking. The next handoff is M0 and then C4-02, not C12 design.
