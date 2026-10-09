# C3 — Canonical Contracts & Data Model
Status: DESIGNED only. The local C4-01 contract seed is a separate artifact, not yet verified in this Git repository.

## Canonical IDs
Use typed RFC 9562 UUIDv7 IDs for Person, Workspace, Event, Evidence, etc.; don't expose Mautic integer Contact ID as PersonId. Time-aware identifiers do not themselves guarantee globally strict order. Workspace scope in every business identity/relationship. Binary UUID storage if benchmarked, with documented conversion. UTC datetime microseconds, injected Clock, source occurred_at != local recorded_at.

## Person
Person aggregate: id, workspace_id, ACTIVE/MERGED/ERASED, merged_into_person_id nullable, version for CAS, created_at, updated_at. No email or first_name as global Person uniqueness. Profile values and contact points belong to observed/typed identity and attribute models. Preserve merge provenance.

## ExternalIdentity and legacy mapping
Typed ExternalIdentity assertions: workspace, type, issuer, lookup_hash (tenant-bound HMAC), protected value, verification state UNVERIFIED/OBSERVED/VERIFIED/REVOKED, normalizer version, first/last-seen, binding history, version.
Critical schema correction: if two Mautic Contacts share the same email, their email observations cannot both map to one required 1:1 UNIQUE email identity binding. Distinguish shareable email observations/candidates from confirmed identity ownership.
mos_legacy_entity_map unique (workspace, legacy_system, legacy_type, legacy_id) to typed canonical entity ID. Replay of Contact 312 must return same Person.

## Event envelope
event_id, event_type, schema_version, workspace_id, aggregate type/id/version, occurred_at, recorded_at, actor, correlation_id, causation_id, payload, metadata.
identity.person.imported.v1 refers to canonical representation of a legacy contact, not person birth. Payload uses evidence/identity refs, not full raw PII. Evidence has id, source, kind, location, content hash, times, retention/encryption controls.

## Knowledge / Decision / Receipt
Epistemic states: KNOWN/UNKNOWN/AMBIGUOUS/CONFLICTING/REFUSED. A separate authorization decision may be ALLOW/DENY/REVIEW/UNKNOWN; a separate effect receipt may be ACCEPTED/FAILED/PENDING, and 'accepted' may prove only relay acceptance. Evidence used, version and reason must survive.

## Atomicity
All Person + Evidence + DomainEvent + Outbox persisted in same transaction. Inbox consumer unique (consumer_name, event_id) records its projection inside same local transaction. Queue remains at-least-once. External effects use C8 business keys, provider semantics and receipts.

## Money
DECIMAL(24,8) concept, ISO currency, no float; FX has sourced rate/time/precision. Missing cost/revenue != zero.

## Initial tables
mos_workspace, mos_person, mos_external_identity, mos_identity_binding_history, mos_legacy_entity_map, mos_evidence, mos_domain_event, mos_outbox, mos_inbox.

## Gate
Verify uniqueness under concurrency; cross-workspace FK safety; no email false-merge; DB rollback before outbox; redelivery; duplicate consumer; encryption/PII; deterministic schema migration against pinned Mautic version.
