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

### C4-07B3A encrypted DLQ key lifecycle (2026-10-10)
[PR #41](https://github.com/achirothmane/marketing-os/pull/41) merged `63713aa2b1ca451d7083c43cbfeb55cb3d8c4be2`; [CI](https://github.com/achirothmane/marketing-os/actions/runs/38058452138) PASS 14/14 bounded MariaDB 11.4 tests, all older PR-head Mautic and C4 workflows green. The opt-in maintenance CLI can inventory archived malformed-wire counts, authenticate versioned ciphertext in pages, and rotate XChaCha20-Poly1305 keys one archive at a time with resumable progress and no plaintext output. **Not a deployed production secret manager, externally supervised worker or approved DLQ redrive.** Full C4 remains NOT VERIFIED.

### C4-07B3B1 local read-only SHADOW preflight (2026-10-11)
[PR #44](https://github.com/achirothmane/marketing-os/pull/44) merged `24c15f7e8aa49ad90a54000d6464f32ba77a01e2`; [MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38138667408) **12/12 PASS**, with all eight prior PR-head M0/C4 workflows successful. The default-off, bounded per-Workspace preflight validates all six checksum-pinned schema versions, active encrypted-wire archive authentication/pages, old key presence, quarantine/backpressure/oversized queues and safe PII-free statuses without mutations or outbound effects. This is a **LOCAL SHADOW proof**, not installation, secret escrow or deployment. Next C4-07B3B2 separate operational database bootstrap from test-only credentials, and C4-07B3B3 supervised runtime/backup/review; full C4 and all marketing sends remain NOT VERIFIED.


### C4-07B3B2 runtime database role separation — 2026-10-11
[PR #46](https://github.com/achirothmane/marketing-os/pull/46) merged `40624cd65cb9cc56858142100fc902dfab5392de`; [MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38143962328) **9/9 PASS**, all nine other M0/C4 PR-head workflows SUCCESS. All MOS identity runtime CLIs use an independent runtime-only bootstrap with strict READ/WRITE/MIGRATE credentials rather than test DSN fallback. Disposable real MariaDB grants verify read-only SELECT cannot UPDATE, scoped DML cannot DDL, missing roles/unsafe DSNs/migration without approval fail closed. **This is not a deployed production account, external secret manager or authorized marketing send.** C4 overall remains NOT VERIFIED.


### C4-07B3B3A protected-credential one-shot SHADOW cycle — 2026-10-11

[PR #48](https://github.com/achirothmane/marketing-os/pull/48) merged at 2064ae495e0cbe4ed36aa14b75b399a74983b9ea. [Installed Mautic 7.2.1 / MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38146136473) **12/12 PASS**, with all ten other PR-head workflows successful. The default-off one-shot supervisor reads four protected credential files, checks Workspace-scoped READ-only pre/postflight under a dedicated DB advisory lock, and drives the existing bounded internal SHADOW Contact→Messenger→Inbox cycle. Malformed encrypted messages force human review; there is no automatic DLQ redrive, provider effect, marketing send or production authorization. systemd service/timer templates have **not been installed** or operated on an actual host. Next C4-07B3B3B: external secrets escrow and restore, real deployment verification, manual DLQ disposition and operator audit. Full C4 remains NOT VERIFIED.


### C4-07B3B3B1 encrypted archive recovery comparison — 2026-10-11

[PR #50](https://github.com/achirothmane/marketing-os/pull/50) merged 76ac79742fe0f85ec941f2331f1d433f9dcb01fd, [MariaDB 11.4 run 38148508716](https://github.com/achirothmane/marketing-os/actions/runs/38148508716) **16/16 PASS**; all ten older PR-head M0/C4 workflows SUCCESS. Bounded read-only crypto authentication now compares encrypted quarantine records across two disposable independent DB schemas, detecting missing/changed bytes, historical-key loss and incomplete pages. A new read-only operator CLI exposes HOLD-only case metadata after authentication; it never re-drives, approves, deletes, sends, or exposes plaintext. The CI scenario copied encrypted archive rows to a second schema; it **did not restore full database backup media or deploy onto a real host**. Overall C4 remains NOT VERIFIED. Next: genuine backup-media restoration for all MOS state plus deployed operator/secret lifecycle and authorized disposition.


### C4-07B3B3B2 disposable SQL backup/import verified — 2026-10-11

[PR #52](https://github.com/achirothmane/marketing-os/pull/52) merged cbfa3051c2b124f0309cccd6d3065de52dc225bd; [installed Mautic 7.2.1/MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38149136108) **8/8 PASS**, plus eleven earlier M0/C4 workflows green. After real SHADOW Contact→Inbox tests, CI created a private real mariadb-dump SQL file, verified its checksum, imported it into a fresh database on the *same disposable DB server*, and matched all tables, column metadata and full sorted row digests, including recovered encrypted archives. Altered Person/DLQ records were detected. Still no off-host restore, protected independent backup store, external key escrow/restore, uploads/volumes, actual installed supervised service, audited human disposition or provider effects. **Overall C4 NOT VERIFIED.**


### C4-07B3B3B3A — Dual-signed quarantine review journal (2026-10-11)

[PR #54](https://github.com/achirothmane/marketing-os/pull/54) merged `441a76ee5b31ebda4e293d49feacbf921b46d722`; [MariaDB 11.4 CI](https://github.com/achirothmane/marketing-os/actions/runs/38178823499) **15/15 PASS**, all eleven prior M0/C4 PR-head workflows SUCCESS. Verified an independent per-role offline Ed25519 signing helper, two signatures over an authenticated encrypted quarantine record, a chained protected on-host journal and fail-closed review CLI with explicit previously witnessed head. Tampered entries, missing anchor and forged signatures fail. All outcomes are **no-effect review recommendations**, without archive deletion, approval to replay or marketing sends. The host-local journal is **NOT immutable without independent external head escrow**. Production operator identities/credentials, separately deployed systemd supervision, remote recovery and C5/C6/C8 policy remain NOT VERIFIED.
