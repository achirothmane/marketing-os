# 03 — Cross-cutting canonical technical contracts

## Identity, tenancy and versioning (C3/C4)
- Canonical identifiers: typed UUIDv7, not legacy integer Contact IDs.
- Every persistent marketing business entity is workspace-scoped; enforce workspace consistency in keys/joins where feasible.
- Person is an aggregate: id, workspace, ACTIVE/MERGED/ERASED state, merged_to?, version, created/updated timestamps.
- Email is NEVER a globally unique Person ID; shared emails must not force merge.
- External identity has type, issuer, lookup hash, protected/encrypted value, verification state, current/historical bindings, evidence.
- Observed unverified email identity can be associated with multiple people as candidate assertions; a unique globally bound email table cannot represent shared email without an observation/binding layer.
- Separate immutable legacy mapping (workspace, legacy system, legacy type, legacy id) -> canonical id, unique per source item. Same Mautic Contact imported 3 times -> same canonical Person.
- Compare-and-swap via aggregate version on concurrent update; avoid last-write-wins.
- UTC DATETIME(6), injected clock. event occurred_at versus recorded_at and source event timestamp are different.

## Domain event / evidence
- Event envelope: event_id, type, schema_version, workspace_id, aggregate type/id/version, occurred_at, recorded_at, actor, correlation_id, causation_id, payload, metadata.
- Example: identity.person.imported.v1 distinguishes importing an existing legacy record from creating a new native Person.
- EvidenceRef points to kind, source, locator, hash, observed time, protected blob when needed; store no raw PII in generic event/queue logs.
- Every material decision includes rule/policy version, used evidence, reason code, and knowledge state.
- Epistemic KNOWLEDGE: KNOWN / UNKNOWN / AMBIGUOUS / CONFLICTING / REFUSED. These do NOT equal authorization ALLOW/DENY or effect SUCCEEDED/FAILED.
- Mutate Person + evidence + domain event + outbox in ONE transaction.
- Publisher may redeliver; inbox dedup is local consumer idempotency, not proof of exactly-once external provider effect.
- Receiver dedup records in same local transaction as its projection; provider side effects require C8 business keys and status reconciliation.

## Monetary invariants
- Money values are decimal strings / fixed precision, ISO currency; no PHP float as financial truth.
- Rounding must be explicit and tested before performing arithmetic.
- USD and MAD cannot be summed absent evidenced FX rate/time/method.
- Refunds/corrections are separate linked ledger entries, not silent mutation.
- Reported, confirmed, settled, recognized, attributed and incremental values mean different things.

## Privacy and consent
- Scope secrets/lookup hashes appropriately; define encryption, key-id and rotation, minimizing log data.
- Marketing DNC != consent; consent != preferences; suppression != frequency cap.
- Consent ledger append-only with provenance and purpose/channel scope; suppression is separately add-only in early migration.
- Never silently remove Mautic DNC; UNKNOWN does not grant permission.
- Legacy deletion != canonical erase; follow proper privacy/retention workflows.

## Side-effect truth and durable execution
- Effect business key stable for a logical action. Effect != attempt != receipt.
- If a provider response is lost after request may have been transmitted, state is UNKNOWN, not FAILED; don't blind-retry unless provider idempotency proves safety.
- Reservation stays held in ambiguous results; authenticated webhooks are deduplicated.
- Authorization rechecked near dispatch; provider acceptance is not delivery to a human.
- Workflow definitions immutable once published; existing executions pin version.
- Durable timer ledger in DB, Messenger for wake-up, not as sole timer truth.
- Single logical node transition under concurrent events/timeouts; effect node uses stable business key.

## Measurement / causal truth
- Audience IN is independent of permission to contact; OUT != UNKNOWN.
- Frozen audience snapshot immutable; policy still rechecked at execution.
- Observation != exposure != conversion != attribution != causality.
- Money movement must originate in actual payment/order evidence; do not synthesize revenue.
- Attribution allocates credit by a stated model and may be UNATTRIBUTED; an attribution run does not prove incremental lift.
- Experiment assignment sticky, randomization-unit aware; intent-to-treat analysis preferred.
- SRM, sample power, data maturity and cross-experiment contamination are first-class quality checks.

## Capability contract (apply to inherited and new code)
For each capability publish:
1. What it can do.
2. Preconditions and assumptions.
3. Evidence inputs.
4. Decision/output.
5. Confidence/epistemic boundary.
6. Reasoning/transformation trace.
7. Known failure cases.
8. Falsification tests.
9. Boundary outcome (KNOWN / UNKNOWN / AMBIGUOUS / CONFLICTING / REFUSED).
Designing a capability without a boundary is incomplete; passing a happy path alone is not verification.
