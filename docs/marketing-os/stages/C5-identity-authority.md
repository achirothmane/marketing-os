# C5 — Identity Synchronization & Authority
Status: DESIGNED, NOT IMPLEMENTED. Dependency: C4 verified, or isolated contracts/tests.

## Constitution
Observed Value != Resolved Value. Source != Authority. Recency != correctness. Authority assigned per fact, purpose and period, not once per Person. Domain must retain source observations and deterministic versioned resolution policies; LLM must not author identity truth.

## Objects
AttributeDefinition(key, data_type, cardinality, sensitivity, normalizer, authority/resolution policy); AttributeObservation(subject, source, value, evidence, observed_at, confidence); ResolvedAttribute(value, rule version, inputs, epistemic state); AttributeConflict and ResolvedAttributeVersion histories.
Source-backed policies: SOURCE_OWNED, PRIORITY_RESOLUTION, RECENCY_RESOLUTION (explicit only), MANUAL_CANONICAL, MULTI_VALUE, CONFLICT_ON_DISAGREEMENT, DERIVED.
Contacts may supply first_name, last_name and email observations; email remains identity, not generic freeform attribute.

## Legacy sync
Post-save snapshot hash and diff avoids redundant events. Directions IMPORT_ONLY, EXPORT_ONLY, BIDIRECTIONAL, LEGACY_ONLY, CANONICAL_ONLY; prefer import/shadow first. Projection context includes origin, projection_id and canonical_version; echoed save confirms or diverges, not a new independent observation.
Sync ledger states PENDING, CONFIRMED, DIVERGED, UNKNOWN, FAILED. Lost acknowledgement needs reconciliation, not blind retry.

## Identity lifecycle
Email change creates new observation/current binding with old binding history retained. Same-email Contacts are AMBIGUOUS, not merged. Legacy Mautic merge is a candidate observation; it does not force canonical merge where product identities conflict. Legacy disappearing Contact is UNKNOWN reason (delete/merge) and must not erase Person globally.
Canonical merge uses evidenced command with workspace, cyclic-merge and conflict checks. Split contract preserves provenance but implementation can wait.

## DNC and consent
Mautic DNC != global Consent. Separate consent, preference, suppression and endpoint deliverability. Import DNC reason/provenance, add safety suppression to Mautic when appropriate. Never remove a legacy DNC just because MOS lacks evidence.

## First field scope
first_name, last_name, observed email identity, legacy email DNC. Shadow resolve/project before authority transfer; only first_name as low-risk trial write, and DNC add-only for safety. Email write to Mautic deferred due merge/dedup risk.

## Gate
Conflict doesn't overwrite; projection echo idempotent; lost ACK -> UNKNOWN/reconcile; legacy delete != canonical erase; suppression isn't silently removed; authority transfer requires migration and rollback.
