# C4-05 — Real persisted Mautic Contact shadow bridge

**Status:** C4-05A merged/verified in its limited scope. PR #18 commit `7d205ed4ae365d495aaa1938a1875a4bfc52ed5a`; live Mautic 7.2.1 / MariaDB 11.4 CI https://github.com/achirothmane/marketing-os/actions/runs/37876095115 PASS 12/12. C4-05B and full C4 remain unverified.

## Why this first slice

Upstream Mautic 7.2.1 exposes LeadEvents::LEAD_POST_SAVE and LeadEvent, but post-save dispatch does not itself prove commit of a surrounding Mautic transaction. A direct subscriber making MOS writes may leave a phantom MOS identity after source rollback. Do not auto-register a subscriber yet.

Instead, an explicit read-after-persistence bridge queries a REAL persisted leads table row through PDO. It uses only an allowlist: id, email, firstname, lastname, date_added, date_modified. Data is only transiently used to compute a **workspace-scoped HMAC-SHA256** using a >=32 byte secret. Generic MOS Event/Outbox and logs never contain those values.

## Interfaces

- PdoMauticContactSnapshotReader::fingerprint(workspace, contactId) reads persisted source and returns HMAC; no raw PII returned.
- PdoMauticContactBridge::importPersisted verifies old mapping evidence consistency and invokes C4-04 atomic Person+Mapping+Evidence+Event+Outbox.
- Prior legacy C4-03 mapping-only rows are explicitly BLOCKED pending reconciliation, rather than silently promoted.
- Updated source snapshot is rejected pending C5 field authority/reconciliation, not silently overwritten.
- The CLI import_mautic_contact.php requires explicit MOS_BRIDGE_MODE=SHADOW, MOS_TEST_DSN/USER/PASSWORD, MOS_SNAPSHOT_HMAC_KEY and a workspace UUID. SHADOW persists MOS records; it never writes to Mautic or emails recipients.

## Validation and limits

- CI installs actual Mautic 7.2.1 in disposable MariaDB 11.4, persists a synthetic Contact in its real leads table, then runs source read->HMAC->C4-04 atomic import.
- Tests check no PII leakage, replay, missing contacts, changed snapshots, separate contacts, tenant-scoped HMAC, and legacy mapping-only refusal.
- This test creates source fixtures using direct test-only SQL, not the production Mautic LeadModel save path. Real LeadEvent subscriber and transaction coordination remain OPEN.
- HMAC key rotation requires migration/reconciliation or preserving key versions; do not rotate blindly in production.
- A query using the same PDO database does not alone prove cross-transaction causality if the source is being changed simultaneously; scheduled verification/reconciliation remains required.

## Next gate

C4-05B: verify actual Mautic LeadModel save->LEAD_POST_SAVE timing and outer transaction effects, wire a safe plugin subscriber or CDC, register real Messenger transport, prove missed hooks/backfill and recover after crash. C4 remains NOT VERIFIED until then.