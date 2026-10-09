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

- **2026-10-09 (M0):** imported exact Mautic 7.2.1 source snapshot from upstream `8cbb7ef874d52a411ae5a884f979acf6cc320181` at repository root, preserving original project docs/contracts. Import run 37867965021 passed. Source smoke run 37868041910 passed Composer validate, entrypoint syntax, and standalone 13 PHP contract tests. Upstream Git ancestry NOT grafted. Runtime/vendor/DB/integration still unverified. See PR #6 and issue #3.
- **Next:** verify install, runtime/DB and upstream regression baseline before C4-02 Contact domain adapter. Do not mark C4 end-to-end PASS.

- **2026-10-09:** M0 upstream source import PR #6 merged to main commit `8ed40e15d0b07ab90efee7ee1e89d9698b858d9c`; Mautic 7.2.1 root paths verified present on main. `composer validate`, entrypoint PHP lint, and 13 canonical contract tests passed in GitHub Actions run 37868041910. No full composer install, DB install or upstream integration tests yet. Tracking issues #3 and #4 updated.

- **2026-10-09:** PR #8 merged `1c0016c9066f36eb274ad0688ebfc89ada4f433d`. Composer install, PHP platform check, upstream Mautic Lead unit test, and contracts PASS in CI run 37868814299. This is *not* a DB install.
- **2026-10-09:** PR #9 merged `11474dac28205d839c948bfb1ef7ff0a213b64aa`. C4-02 Person aggregate and legacy mapping domains verified on PHP 8.2/8.3 in CI run 37868986534. No SQL uniqueness or real Contact bridge yet.
- **2026-10-09:** PR #10 opened to test isolated MariaDB 11.4 CLI install. Do not claim DB runtime PASS until workflow concludes.

- **2026-10-09:** PR #10 merged (`7b4cd1bf95df9f803ac2858d4205ca5c9cb49c69`). Isolated MariaDB 11.4 installed using upstream `mautic:install`, 126 tables verified, CLI boot passed; workflow run 37869113329 SUCCESS. M0 installation baseline is now demonstrated, but full upstream regression, app deployment, and MOS domain persistence are still outstanding. Next C4-03 SQL/Doctrine persistence.

- **2026-10-09:** PR #13 merged `bb019fd6fe124fb6a36257acb15ebb077ea7a6d2`. C4-03 initial MariaDB 11.4 schema migration and PDO tenant-safe Person+Legacy source mapping persistence PASS. CI run 37869768883: contracts 13, identity 14, DB 11 tests all pass, including two independent PHP worker race, FK cross-workspace checks, rollback, CAS; four MOS-owned tables created without touching Mautic source tables. This is not yet Contact->Event->Outbox end-to-end. Next C4-04.

- **2026-10-09:** PR #15 merged `69ee68098110fc9478b5e0aed0b8ac732cd88a22`; C4-04 Event/Evidence/Outbox/Inbox proven on isolated MariaDB 11.4 run 37871039165: 13+14+11+14=52 test assertions, five injected precommit failures, 2-worker race, lost-ACK replay and inbox atomic projection/dedup. Existing direct C4-03 legacy registry remains TEST/COMPATIBILITY ONLY; C4-05 must bind a real Mautic snapshot. No Mautic Contact->Inbox real end-to-end or external transport yet.

- **2026-10-09:** PR #18 merged `7d205ed4ae365d495aaa1938a1875a4bfc52ed5a`. Installed Mautic 7.2.1 with disposable MariaDB 11.4, wrote source fixtures to actual leads table, read Contact allowlist to HMAC-SHA256 (workspace-scoped), persisted MOS shadow records via C4-04. Live tests 12/12 PASS run 37876095115 after correcting mandatory `is_published` and `points` fixture columns. Tests proved replay no duplicate, no PII leakage, source update BLOCK, legacy unmapped Evidence BLOCK. Auto `LEAD_POST_SAVE` subscriber and real Messenger transport still unimplemented; C4 end-to-end remains NOT VERIFIED. Next C4-05B.

- **2026-10-09:** PR #20 merged `423ad96304d5e2a3f49bcd85b63ed38f4fe4b63b`; C4-05B commit-safe source sweep tested in real installed Mautic 7.2.1 / MariaDB 11.4. CI run 37880743889: C4-05A 12 PASS; scanner 7 PASS; LeadModel actual post-save/outer-transaction probe 3 PASS. Source rollback invisible to independent connection; committed LeadModel save recognized; late lower-ID commit recovered by wraparound cursor. Legacy 52 scoped tests pass on parallel run 37880743901. No auto subscriber, scheduler, or queue transport deployed. C4 end-to-end still NOT VERIFIED.

- **2026-10-09:** PR #22 merged `dd5069c7dc4d18505eedb4a137bac73d94d19c9d`; C4-05C opt-in cron-ready bounded SHADOW runner and abrupt-crash replay CI PASS. Mautic 7.2.1 / MariaDB 11.4 CI https://github.com/achirothmane/marketing-os/actions/runs/37996553218 confirms 8/8 worker tests (batch cap, wrap, replay, advisory lock, process `exit(77)` after Person+Event COMMIT before cursor checkpoint, double opt-in CLI off/on, no PII). Earlier C4 stages pass regression. No real cron or production deployment, Mautic automatic save subscriber or Messenger transport. Next C4-06/C4-07. C4 overall remains NOT VERIFIED.

- **2026-10-09:** PR #24 merged `5e3fdd7e304399439909ea05b929787668c9be9f`; C4-06A source observation and persistent case history PASS (19/19) on installed Mautic 7.2.1 MariaDB 11.4 run 38001779877. Tests cover changed Contact, missing observation(s), restored/reappearing row, old mapping lacking Evidence, tenant isolation, SQL read failures, atomic rollback between case/history, mapping-ID pagination and PII check. Explicit audit CLI default OFF and mismatch exit 2 verified. No authoritative deletion/Person erase or source update, full C4 and Messenger remain NOT VERIFIED.
