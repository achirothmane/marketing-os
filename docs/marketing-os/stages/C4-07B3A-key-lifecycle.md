# C4-07B3A — Encrypted DLQ key verification and rotation

**Status:** C4-07B3A scope VERIFIED on MariaDB 11.4; PR #41 merged `63713aa2b1ca451d7083c43cbfeb55cb3d8c4be2`, [CI https://github.com/achirothmane/marketing-os/actions/runs/38058452138](https://github.com/achirothmane/marketing-os/actions/runs/38058452138) **14/14 PASS**. This remains operator-only SHADOW and NOT production-ready.

## What changes

`MosWireQuarantineKeyManager` supports three **workspace-scoped, bounded** functions on already encrypted malformed-wire archives: `inventory()` reports only reason/key counts, `verify()` cryptographically authenticates stored ciphertext and associated data across paginated rows without revealing the plaintext, and `rotate()` re-encrypts with fresh XChaCha20-Poly1305 nonces under a new distinct key and key ID, committing each record in an individual InnoDB transaction.

Key rotation verifies the ciphertext digest, decrypts with authenticated AAD `{workspace}|{queue_name}|{source_message_id}`, checks archived length and header framing, then writes a new nonce/ciphertext/hash with the original archive identity and reason preserved. Before committing it self-tests the newly authenticated ciphertext. A crash after any row is safe to resume on the remaining old-key rows; wrong key, corruption or mismatched tenant abort the affected row without losing its existing bytes.

## Operator-only CLI (no raw wire access)

`php packages/identity/bin/dlq_key_maintenance.php WORKSPACE_UUID INVENTORY LIMIT`

`php packages/identity/bin/dlq_key_maintenance.php WORKSPACE_UUID VERIFY OLD_KEY_ID LIMIT AFTER_ID`

`php packages/identity/bin/dlq_key_maintenance.php WORKSPACE_UUID ROTATE OLD_KEY_ID NEW_KEY_ID LIMIT`

Each requires `MOS_DLQ_MAINTENANCE_ENABLED=1` and `MOS_QUEUE_MODE=SHADOW`. Operations allow only 1–100 rows per call and require an existing workspace. VERIFY/ROTATE read the old key from `MOS_DLQ_OLD_KEY_B64`; ROTATE additionally requires `MOS_DLQ_ROTATE_APPROVED=1` and a separate new `MOS_DLQ_NEW_KEY_B64`. Key identifiers are provided on the command line, but **key material never belongs in argv, source control or command logs**. No plaintext archives, emails, or secrets appear in output.

The maintenance CLI acquires the same per-Workspace MariaDB advisory lock as `WIRE_SCAN`. Pause and disable the bounded pipeline and external scheduler for the targeted Workspace before rotation; the lock prevents concurrent local WIRE_SCAN during one invocation, but does not implement a global production maintenance window. Review `INVENTORY`, verify old key across ALL pages, rotate repeatedly until `remaining_old_key` is zero, verify the new key across ALL pages, then update `MOS_DLQ_ACTIVE_KEY_ID` and `MOS_DLQ_KEY_B64` together in a managed secret environment. Re-enable the scanning worker only after validation. Retain the retired key until all workspaces and backups are reconciled; do not delete it based on a single Workspace's count.

## Security and failure boundary

Ciphertext SHA256 is the integrity marker, never a deterministic hash of low-entropy plaintext. AEAD authenticates original content, Workspace, queue, source message ID and nonce. The CLI only returns counts, next cursor and completion flag; verification of one page **does not** attest the entire archive. Rotation is resumable, but not a single atomic all-rows cutover. It has no automatic re-drive and cannot produce recipient authorization. Separate operator approval and review remain mandatory before any manual DLQ release, which is intentionally not implemented.

**Out of scope:** an external secrets manager, backed-up production key escrow and recovery drills, automated key distribution/synchronization across multiple workers, deployed supervisor/service, tenant ACLs and full upstream/Mautic production readiness. Marketing sends remain disabled until C5/C6/C8 gates.

## CI acceptance

MariaDB 11.4 tests must prove bounded paginated verification, wrong-key rejection without mutation, partial rotation/restart and authenticated new bytes, multi-workspace isolation, tampered ciphertext rollback, invalid bounds/same-key rejection, safe inventory, disabled-by-default/approval-gated CLI, CLI advisory lock and new active key usage by the existing wire scanner. All old C4 test workflows remain required. On success record exact PR merge SHA and run in project status and handoff.

## Verified acceptance evidence — 2026-10-10
- Source PR #41 merged `63713aa2b1ca451d7083c43cbfeb55cb3d8c4be2`; https://github.com/achirothmane/marketing-os/actions/runs/38058452138 14/14 cryptographic and CLI integration cases successful. All other PR-head M0/C4 workflows successful.
- Maintains archive digest on ciphertext and authenticates original Workspace/queue/message identity before per-row re-encryption. Reads and rotations are bounded to 1–100 records. All decrypted content remains in-process, not returned to operators or telemetry.
- C4-07B3B production secret escrow, security controls, actual service supervisor and approved DLQ review/redrive are still OPEN. No marketing send authorization.
