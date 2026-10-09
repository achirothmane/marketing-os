# C4-06 — Source reconciliation observations (non-destructive)

**Acceptance condition:** Real Mautic 7.2.1 / MariaDB 11.4 CI must pass before marking this narrow capability VERIFIED.

## The boundary

Observation of a Contact is not authority to change a Person. A missing source row may be a deletion, a delayed transaction, an access restriction or an operational failure. A changed HMAC may reflect a genuine source edit or HMAC key rotation. Neither is authority to erase, merge, send, unsubscribe or resolve identity automatically.

## New MariaDB migration 004

- `mos_source_reconciliation_case`: workspace-scoped current observation status, fingerprint (HMAC only), missing count and monotonically increasing revision; FK to the existing legacy mapping.
- `mos_source_reconciliation_observation`: append-only sequence of material observations linked to a case; unique revision per scoped source. Nothing stores raw email/name, an API token or a decrypted source snapshot.
- All case/observation writes happen in one transaction, locking the scoped legacy mapping; an injected fault between the writes rolls back both.
- The original Person, LegacyMap, Evidence baseline, DomainEvent and Outbox remain unchanged by the auditor.

## Explicit statuses

- `MATCH`: one committed-source read has the same workspace-aware HMAC as its original Evidence.
- `SOURCE_CHANGED`: a read returned a different HMAC. Require a separate C5 approval/reconciliation flow. Never silently replace original Evidence.
- `SOURCE_MISSING_ONCE`: one committed-source read did not find the row; **not a deletion assertion**.
- `SOURCE_MISSING_REPEATED`: at least two qualifying absence observations separated by >=60s by default, still **not proof of deletion** and cannot mark Person ERASED.
- `UNVERIFIED_LEGACY`: pre-C4-04 legacy map lacks a matching Evidence source row; cannot silently promote or auto-heal.

Unknown source lookup errors must fail, not turn into missing observations. Stable equal observations do not generate duplicate history. Repeated disappearance count is capped at 2 so polling does not grow history indefinitely.

## Finding deletions that the Lead scanner misses

An explicit bounded `auditMappedBatch(workspace, afterId, limit)` enumerates **existing legacy mappings**, rather than just live `leads` rows. It therefore observes previously imported Contacts whose current source rows are now missing. Output is aggregate, PII-free status counts, cursor, and `has_more`; caller controls pagination. It is NOT an automatically scheduled service.

CLI `packages/identity/bin/audit_mautic_source.php WORKSPACE_UUID [AFTER_ID] [LIMIT]` requires BOTH `MOS_BRIDGE_MODE=SHADOW` and `MOS_SOURCE_AUDIT_ENABLED=1`, dedicated MariaDB credentials and a >=32-byte HMAC secret. Exit 2 for any source disagreement or missing/legacy Evidence: **operator review is mandatory**. Exit 0 is only a no-new-discrepancy result in the bounded scanned subset, never full business truth.

## Tests and falsification

Real pinned Mautic/MariaDB: unchanged source, changed fields, restored fields, first missing, immediate repeat, time-separated repeated missing, reappearing source, same Mautic ID in two Workspaces, original legacy mapping with missing Evidence, unknown mapping, read failure, rollback between case and observation, mapped-ID pagination, no raw PII in persistent observations.

## Still missing

Actual authorized source mutation/delete resolution with consent semantics, cryptographic key rotation migration, GDPR/retention erasure, durable operator approval, reconciliation of the old registry, production migrations, Messenger transport, full C4 end-to-end and operational deployment. These belong in later bounded slices; no marketing send or automated identity merge allowed.
