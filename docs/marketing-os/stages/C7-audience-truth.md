# C7 — Audience Engine & Membership Truth
Status: DESIGNED, NOT IMPLEMENTED.

## Non-equivalences
Audience Definition != members. Member IN != permission to contact. OUT != UNKNOWN. Live membership != frozen snapshot. Segment opt-out != global unsubscribe. Assignment to an experiment and destination activation are separate objects.

## Objects and storage
Audience(workspace, subject type, type, active version, lifecycle);
AudienceDefinition(immutable version, validated typed expression AST/hash, evaluation mode);
AudienceDependency(attribute/event/audience refs);
AudienceMembership(subject, IN/OUT/UNKNOWN, version, timestamps, freshness);
AudienceMembershipTransition(append-only, from/to, evidence/reason);
AudienceOverride(INCLUDE/EXCLUDE, scope/evidence/expiry);
AudienceSnapshot and SnapshotMembers(immutable, input watermark, hash);
LegacyAudienceMap(Mautic Segment ref and semantic compatibility).

## Definitions
Types RULE_BASED/STATIC/COMPOSITE/LEGACY_BACKED. Initial typed operators EXISTS, EQUALS, AND, OR, NOT; later comparisons and event windows. Validate types, referenced audience cycles, missing sources and unsupported predicates before publish. Composite audiences must be cycle-free.
Unknown input to equality -> UNKNOWN unless explicit operator semantics say otherwise. EXISTS missing -> OUT/false. Don't silently conflate uncertainty with non-membership.

## Evaluation and history
Batch and incremental first, real-time later. Attribute change triggers dependent audience reevaluation; do not reevaluate every audience on every Person update. Use watermarks, freshness and next_evaluate_at for time-window expiration even without new user events. Store one idempotent ENTER/EXIT/BECAME_UNKNOWN event only on material transition. Concurrency via version/CAS.
Definition version changed: existing snapshots remain immutable and historical reasons explain membership movement. Workflow consumers PINNED version or FOLLOW_ACTIVE explicitly.

## Mautic compatibility
Map static/dynamic segments as LEGACY_BACKED sources; mark last observation freshness. Only translate supported filter semantics into native AST. Shadow compare legacy vs canonical membership before cutover, with MATCH/DIVERGED/UNKNOWN. Native audience -> legacy static segment only if legacy campaign requires it.

## Gate
Duplicate evaluator no duplicate entry; IN->UNKNOWN not EXIT; snapshot immutable; unsubscribe does not alter audience membership unless definition explicitly says; uncertain legacy segment flagged STALE; big changes previewed for blast radius.
