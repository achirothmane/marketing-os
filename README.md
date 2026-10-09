# Marketing OS — Marketing Automation Suite

**Status (2026-10-09):** Project record preserved; upstream Mautic code NOT yet imported; C3–C11 implementations are NOT verified in this repository.

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
The repository started EMPTY. It is not yet a working hard fork of [Mautic](https://github.com/mautic/mautic). Import the pinned upstream source into the repository ROOT, preserve upstream licensing and structure, and run the baseline CI before integrating the Contact -> Person -> Evidence -> Event -> Outbox -> Consumer vertical slice.

One central Dots coordinates long-running projects; this repo owns its own history, contracts and evidence.
