# 05 — Acceptance tests and falsification matrix

A design phase is not PASS until implementation evidence (code SHA, exact commands, results) demonstrates relevant invariants.

## C3/C4 — Contact, identity, evidence and transport
- Import Contact 312 three times: exactly one Person, one legacy mapping, one imported event.
- Import Contact 891 with same email as 312: two distinct Persons and explicit ambiguity, NO silent merge.
- Contact with invalid/missing email still imports; invalid email not promoted as verified identity.
- Concurrent import of same legacy ID: uniqueness constraint forces one winner, losing worker reloads mapping.
- Inject failure between Person creation and outbox insert: DB rollback leaves zero Person/Event/Outbox.
- Kill publisher after DB commit and before message delivery: pending outbox survives.
- Redeliver message twice: single consumer business projection with atomic inbox.
- Cross-workspace binding attempt rejected.
- Legacy Contact PII not present in event payload/logs.

## C5 — Authority and sync
- Mautic field changes produce observations, not silent canonical overwrite.
- Projection echo does not cause loop; lost ACK leads UNKNOWN and reconciliation.
- Email changed creates historical binding evidence, not destruction of old identity.
- Mautic merge or Contact disappearance != automatic Person merge/erase.
- Legacy DNC of unknown reason does not become recorded consent withdrawal.

## C6 — Contactability
- Consent withdrawal beats earlier grant at same subject/channel/purpose.
- Hard bounce != consent withdrawal, pause != unsubscribe.
- Missing required consent yields UNKNOWN/DENY per policy, never ALLOW by default.
- Policy version persisted with enforced external effect.
- Canonical suppression may add legacy DNC; cannot silently remove existing DNC.
- Marketing purpose may not be relabeled transactional to evade policy.

## C7 — Audiences
- Missing value with EXISTS -> false; unknown country comparison -> UNKNOWN.
- IN -> IN reevaluation emits no new entry event.
- IN -> UNKNOWN due to dependency outage is NOT an exit.
- Frozen snapshot unchanged when live members change.
- Concurrent evaluator updates yield one transition.
- Unsubscribe does not itself erase audience membership; C6 denies send.
- Legacy segment stale state is visible.

## C8 — Effect / receipt
- Stable business key creates one Effect even under repeated queue messages.
- Policy revoked before dispatch => zero provider calls.
- Provider accepted + response lost => PENDING_CONFIRMATION/UNKNOWN; no blind retry.
- Authenticated duplicate provider webhooks => one receipt.
- Conflicting receipts => CONFLICTING.
- Acceptance != delivery to person; later bounce affects delivery and suppression, not history rewrite.
- Reservation remains held through unknown result; definitive pre-dispatch rejection releases.
- Crash recovery cannot blindly replay irreversible actions.

## C9 — Long-running workflows
- Duplicate enrollment => one execution under re-entry policy.
- Worker death after node/outbox commit => next node still runs.
- WAIT timer survives service shutdown; late firing logs lag.
- Event and timeout racing have one winner.
- New published workflow version doesn't mutate ongoing pinned execution.
- C8 UNKNOWN does not map to success/failure branch.
- Cancellation blocks late timer resurrection.
- Validator refuses unsupported DAG cycles/dangling edges.

## C10 — Measurement
- Duplicate purchase webhook does not double-count conversion/revenue.
- No verified human exposure from email provider acceptance.
- Revenue/refund/processing fee separated; missing cost not zero.
- Cross-currency reports require evidenced FX.
- Attribution model change produces new immutable run.
- Purchase following a click is not automatically incremental revenue.

## C11 — Experimental validity
- Sticky assignments survive retries, merging/identity conflicts are surfaced.
- A/A simulation checks assignment bias and false-positive rate.
- SRM detection prevents invalid causal claims.
- Control contamination via legacy Mautic campaign is detected.
- Participants denied by policy remain in original assigned treatment for intent-to-treat.
- Underpowered or too-early outcomes remain INCONCLUSIVE.
- Fixed-horizon analysis cannot claim victory due to ordinary repeated peeking.
- Incremental contribution includes incremental costs, not only attributed sales.

## Required reporting for each test
Test ID, severity, environment (PHP/DB/Mautic ref), setup, action, expected invariant, actual result, evidence/artifact, SHA, status. A passed mock test is not the same as passing Mautic integration or production reconciliation.
