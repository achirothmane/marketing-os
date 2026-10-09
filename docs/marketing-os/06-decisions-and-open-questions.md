# 06 — Architecture decision and risk register

## Accepted design decisions (NOT evidence of deployed behavior)
| ID | Decision | Motivation / constraint |
|---|---|---|
| ADR-001 | Start from Mautic source, preserve its root layout | Retain mature CRM/marketing runtime and upstream compatibility |
| ADR-002 | Modular PHP/Symfony monolith initially; packages as boundaries | Simplicity, affordable operations, no premature 13 microservices |
| ADR-003 | Use typed UUIDv7 and tenant-scoped canonical Person, not legacy Lead integer | Stable cross-source identity and workspace isolation |
| ADR-004 | Email is an observation/identity assertion, not automatic person uniqueness | Shared emails and false merges |
| ADR-005 | Event + evidence + outbox written transactionally, idempotent inbox consumers | Recovery and at-least-once queue delivery |
| ADR-006 | Fact-level authority and provenance; observed != resolved | Bidirectional sync without silent overwrite |
| ADR-007 | DNC != Consent != Suppression != Preference | Respect permissions and protect customers |
| ADR-008 | Audience truth != send eligibility or experiment cohort | No unsafe targeting shortcuts |
| ADR-009 | Effect != attempt != receipt; UNKNOWN reconciled | Lost-ACK and provider duplicate protection |
| ADR-010 | DB-backed workflow state/timers, Messenger as transport | Long-running journeys recover after worker death |
| ADR-011 | Observation != exposure != attribution != causality | Financial and causal honesty |
| ADR-012 | Fixed-horizon experiment/ITT v1 with quality checks | Valid early causal estimates |
| ADR-013 | One central Dots across long-running projects | Coherent portfolio-wide orchestration |
| ADR-014 | Separate Data Engine service/repository and Marketing OS semantics | Avoid leaking unrelated data model into customer policy |

## Material unresolved choices
- UPSTREAM_IMPORT: specific Mautic 7.x commit/release, importer procedure for already-existing repository, Composer lock and license validation.
- STORAGE: MySQL/MariaDB version, binary UUID ordering, datetime(6) mapping, FK/index limits and cross-workspace key scheme.
- TENANCY: workspace creation/bootstrap, HMAC salt isolation, encryption key ID and rotation, secure PII destruction.
- IDENTITY: shared email observation schema versus verified 1:1 identity, merge/split rules, legal deletion.
- MESSENGER: transport and transaction integration, failure handling, outbox publisher concurrency.
- CAUSALITY: deterministic policy DSL boundaries, snapshot freshness, dispatch recheck race.
- EFFECTS: provider-idempotency capability per email transport, webhook receipt correlation, UNKNOWN reconciliation contract.
- WORKFLOW: task substrate, event-wait race resolution, pause/resume semantics.
- MEASUREMENT: source transaction vs marketing event dedup, FX evidence, conversion maturity.
- EXPERIMENT: unit of randomization, statistical sample-size design, holdout interference and SRM thresholds.
- COMMERCIAL: actual users, willingness to pay, distribution, price; no claim these are validated.

## Change control
Any change to an accepted decision gets: date, triggering evidence, old/new rule, affected stages, required migration, tests, rollback and ADR update. Mark decisions SUPERSEDED, never erase earlier rationale. Prior 'design PASS' in chat is not code verification.
