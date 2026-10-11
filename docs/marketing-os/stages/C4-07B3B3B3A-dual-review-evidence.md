# C4-07B3B3B3A — Dual-signed encrypted-quarantine review evidence

**Status: IMPLEMENTED ON PR BRANCH; MariaDB 11.4 acceptance PENDING.** This is a no-effect human review journal, NOT live deployment, true durable external witness, authenticated operator identity, or permission to replay/send.

## Audited recommendation contract

The existing read-only dlq_review_queue.php returns Workspace-scoped authenticated case IDs, key IDs, reason codes and ciphertext SHA-256 hashes without plaintext. For an operator record, prepare a JSON object with exactly these claim fields:

- review_id: randomly generated 32-character lowercase hexadecimal unique event identifier
- workspace_id: explicit Workspace UUID
- case_id: existing numeric quarantined source-message ID
- key_id, ciphertext_sha256, reason_code: exact current authenticated DLQ fields
- decision: CONTINUE_HOLD, ESCALATE, or REJECT_REPLAY (all are recommendations with ZERO operational effects)
- reviewer_id and approver_id: distinct identifiers registered in externally managed roles

The signing message is the domain-prefixed canonical JSON produced by MosDLQReviewJournal::message(claim), and both humans independently sign those exact bytes with separate Ed25519 private keys. The input JSON file contains claim plus signatures (base64 reviewer_sig and approver_sig). Each reviewer/approver public key must exist under its independent role in a protected, externally provisioned public-key manifest with reviewers and approvers maps. The journal refuses the same identity or same public key for both roles, invalid signatures, missing historical decryption key, forged case references, wrong Workspace, altered archive digest or wrong DLQ cipher authentication. Do not put free-text notes, Contact fields or secrets in the artifact.

This proof supports **two distinct key holders**; it cannot prove that different people actually control those keys or that keys were issued after HR/organization identity checks. That is an external operator identity and key escrow requirement.

## CLI, default OFF

Run: php packages/identity/bin/dlq_record_dual_review.php WORKSPACE_UUID /absolute/path/signed-review.json

Requires MOS_REVIEW_RECORD_ENABLED=1, MOS_QUEUE_MODE=SHADOW, a runtime READ-only DB role, MOS_REVIEW_TRUST_FILE (protected file mapping public signing keys), MOS_DLQ_REVIEW_KEY_B64 (the 32-byte raw historical key encoded as base64), MOS_REVIEW_JOURNAL_DIR (existing protected absolute directory), and MOS_REVIEW_EXPECTED_HEAD (64-character previously witnessed journal head). The signed artifact and trust file must be regular, non-symlinked, protected files. Do not provide private signing keys to this executable or pass encryption keys through argv.

Success appends one journal record with flock, a SHA-256 history chain, signature validation and flush/fsync. Output contains only RECORDED_NO_EFFECT, entry_count and new head_hash; NO message is acknowledged, deleted, requeued, or sent. Refusal is exit 2 and generic/allowlisted errors with no raw SQL or decrypted bytes. The journal is scoped to one protected directory; deploy separate directories per independent tenant.

## External witness mandatory

An append requires the immediately prior trusted head from **separate storage**. After successful append, securely record the returned new head into a separately access-controlled witness (for example remote WORM/object-lock storage or a manually attested independent ledger), with Workspace identity and sequence. The journal verifier detects tampering, reordering, duplicate review IDs or removed entries **only relative to that trusted externally pinned head**. If the journal and external witness are both rewritten by one privileged actor, the proof fails. Never describe a locally hosted journal file alone as immutable/audit-compliant.

## What remains before production

The CI tests disposable MariaDB encrypted evidence, signed no-effect review, malformed signatures, forged claims, altered history, missing old head, and absence of replay. It does not establish remote separate-host deployment, operator key provisioning/rotation, private-key custody, off-site witness, disaster recovery of this journal, filesystem crash durability, a human approval workflow with verified identities, or manual re-drive authority. The preflight continues to block quarantined evidence even after a journal recommendation.

Full C4-07B3B3B3 and top-level C4 remain NOT VERIFIED; C5/C6/C8 external marketing effects remain disabled.
