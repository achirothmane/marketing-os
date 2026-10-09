# Marketing OS — Marketing Automation Suite

**Status (2026-10-09):** Mautic 7.2.1 source imported; basic install/DB smoke verified. C4-01–C4-04 passed scoped PHP/MariaDB tests. C4-05A source-backed SHADOW import (12 tests) and C4-05B committed-source recovery and real LeadModel hook/rollback probes (7+3 tests) passed. Automatic subscription/scheduling, Messenger transport and full C4 end-to-end remain NOT VERIFIED.

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
