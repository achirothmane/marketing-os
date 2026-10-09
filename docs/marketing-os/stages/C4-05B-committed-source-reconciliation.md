# C4-05B — Source transaction probe and bounded committed Contact reconciliation

**Status:** MERGED and VERIFIED for the described narrow source-commit/recovery scope. PR #20 commit `423ad96304d5e2a3f49bcd85b63ed38f4fe4b63b`; CI https://github.com/achirothmane/marketing-os/actions/runs/37880743889 PASSED (7 scanner + 3 true LeadModel probe tests), alongside original C4-05A 12 and C4-03/04 regression. No production activation.

## Source evidence

Pinned upstream Mautic 7.2.1:
- `app/bundles/CoreBundle/Model/FormModel.php::saveEntity`: dispatch pre_save -> repository persist/flush -> dispatch post_save.
- `app/bundles/LeadBundle/Model/LeadModel.php::dispatchEvent`: maps post_save to `LeadEvents::LEAD_POST_SAVE`.
- `app/bundles/CoreBundle/Entity/CommonRepository.php::saveEntity`: ORM persist and flush; does not prove that caller's **outer DB transaction** committed.

Therefore **LEAD_POST_SAVE != committed source effect**. A listener must not directly create MOS Person or publish external messages solely on this callback.

## Implemented path

Separate on-demand `PdoMauticContactReconciler` scans committed Mautic `leads` IDs using the safe C4-05A reader, and invokes the C4-04 evidence-backed importer. A workspace-scoped DB advisory lock prevents simultaneous sweeps. Migration 003 adds `mos_contact_scan_cursor` for incremental progress. If a source ID was allocated but its transaction committed after a higher ID was scanned, **end-of-sweep wraps cursor to zero**, so later sweeps can discover that delayed row. Missed callbacks and process interruptions do not block rescan; retried committed imports are idempotent.

Errors requiring explicit reconciliation (changed source, legacy evidence absent, source mismatch) are counted and do not silently become success; the command exits nonzero when blocked. Unexpected failures abort before updating cursor, so next scan can retry safely.

No persistent Mautic event listener is enabled. A scan is **not automatically scheduled**; operators must explicitly execute the SHADOW-mode command with configured secrets and isolated source DB credentials. This is bounded read-only Mautic input, but writing the MOS shadow tables. No email/campaign send.

## Verification

- Real Mautic 7.2.1 installer, MariaDB 11.4; regression C4-05A, real-source reconciliation checks and actual `LeadModel::saveEntity` hook timing tests.
- Inject separate DB transaction: confirm uncommitted source row invisible to scanner; rollback never creates MOS Person.
- Commit a lower source ID after a higher ID was scanned: verify wrap discovers delayed commit.
- Real LeadModel: prove `LEAD_POST_SAVE` occurs with an active outer transaction, then rollback does not leave a source Contact in independent connection; committed LeadModel Contact is subsequently observed and imported.
- Verify modified Contact is blocked for C5 reconciliation, without silently creating a second event.

## Explicit remaining limitations

- Source changes/deletions are not reconciled yet; C5 and C6 authority/consent rules remain.
- Cursor is a **progress hint**, not a proof of completeness at any instant. Repeated full sweeps can become expensive for large source databases; scale with CDC and audited catch-up later.
- This does not wire a production event subscriber or a scheduled worker, and does not prove queue transport or automatic Inbox delivery.
- SQL migration 003 must be tested with existing 001/002, and default-off behavior preserved.
- C4 end-to-end still NOT VERIFIED; no production activation or marketing sends.

## Verified evidence checkpoint
- LEAD_POST_SAVE inside active outer transaction, source rollback unseen by independent DB reader, committed LeadModel import via idempotent scanner: PASS.
- Delayed lower-ID commit, worker replay, incomplete Evidence and changed source evidence: PASS under MariaDB 11.4.
- Real Contact EventSubscriber is NOT enabled. Scanner invocation is manual/opt-in. No background automation or outbound messaging.
