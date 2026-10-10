# C4-07B2 — Encrypted malformed-wire quarantine and bounded SHADOW coordination

**Status:** VERIFIED for isolated MariaDB 11.4 and finite SHADOW supervisor scope. PR #35 merged `655cfc0213f0425a7b5469c90691ec20e121e5ff`, [CI 12/12 PASS](https://github.com/achirothmane/marketing-os/actions/runs/38035922092). Production supervision, key rotation, DLQ review/redrive and full C4 remain UNVERIFIED.

## Root cause

Symfony Doctrine Messenger reads queue rows and decodes wire headers/envelopes *before* the consumer's business handler runs. C4-07B1 only quarantines syntactically valid EventPointers with repeated handler failures. Invalid JSON, unknown types, corrupted headers or cross-workspace pointers can cause repeated read failures without ever reaching the Inbox or valid-pointer failure ledger.

## New capability and boundary

- Explicit migration 006 introduces \`mos_messenger_wire_quarantine\`: tenant + immutable queue row identity, reason code, SHA-256 of ciphertext (not enumerable raw Contact data), key identifier and **authenticated encrypted original body+headers**; never plaintext wire content. No event FK is required because a malformed pointer may lack a valid EventId.
- \`MosEncryptedWireQuarantine\` scans at most 100 available queue rows per call; locks each row within a MariaDB transaction. It verifies the exact versioned \`MosMessengerJsonSerializer\`, and for corrupted rows seals the unmodified header/body bytes using XChaCha20-Poly1305 with a **separate, externally provided 32-byte secret**.
- The archive INSERT and source DELETE happen in one transaction; if encryption/storage/deletion fails, the entire transaction rolls back, leaving the original queued bytes in place. A unique archive key prevents unsafe overwrite.
- Valid pointer bytes stay untouched. Recently claimed messages are skipped; a stale claim may be inspected only after the current 60s visibility bound. Oversized wires above 256KiB are not deleted or silently truncated: stop the supervised workflow and require manual review.
- An observed wrong-workspace pointer in a tenant queue is moved to that tenant's wire DLQ without invoking Inbox.
- \`MOS_DLQ_KEY_B64\` is required for \`WIRE_SCAN\`. This key **MUST NOT be hardcoded or committed** in production. Key retention, backup, rotation and recovery need an operator runbook. Archived ciphertext is recoverable with the matching secret and authenticated AAD; there is **NO automatic re-drive of quarantined wires**.
- \`messenger_shadow_worker.php WORKSPACE_UUID WIRE_SCAN LIMIT\` remains double-opt-in SHADOW and bounded.
- \`messenger_shadow_supervisor.php WORKSPACE_UUID CYCLES BUDGET\` additionally needs \`MOS_SUPERVISOR_ENABLED=1\`. It runs WIRE_SCAN -> PUBLISH -> CONSUME as separate PHP subprocesses; max 10 cycles x 20 messages, fixed per-child time budget, at most one controlled retry if a process fails, and stops fail-closed on oversized wire. It does **not** install a systemd/Kubernetes supervisor or run indefinitely.

## Acceptance / falsification

The MariaDB 11.4 tests must prove (1) pinned schema 006, (2) correct valid messages left untouched, (3) atomic raw-wire encryption+quarantine, (4) ciphertext tampering refused, (5) bad headers, (6) wrong-workspace wire, (7) oversize preservation, (8) respect for unexpired broker leases, (9) duplicate archive rollback preserving source, (10) PII-free manual WIRE_SCAN, (11) explicit disabled-by-default supervisor bounds, (12) bounded supervisor actually quarantines then publishes and consumes one real local domain event, (13) oversight halt before publication if oversized.

All prior C4 acceptance suites must pass after migration 006; update prior fixed migration-count assertions as necessary.

## Remaining work

This is an operator-invoked finite SHADOW coordinator, not a production daemon or comprehensive service supervisor. A production rollout still requires external secret management, authenticated key rotation, per-tenant DB credentials/authorization, malformed message operational review / redrive policy, actual scheduled worker supervision and disaster recovery. C4-08 proof is integrated but uses test-only SQL fixtures in Mautic; C5/C6/C8 gates remain. No external sends, CRM effects or authorization to contact recipients.

## Accepted checkpoint — 2026-10-10
- Source merge `655cfc0213f0425a7b5469c90691ec20e121e5ff`, [CI https://github.com/achirothmane/marketing-os/actions/runs/38035922092](https://github.com/achirothmane/marketing-os/actions/runs/38035922092): 12/12 wire tests; all PR-head legacy Mautic/C4 workflows successful.
- Crypto stores ciphertext digest, encrypted original raw header/body, nonce and reason code. The operator secret never appears in event payloads or logs.
- Tests inject archive identity collision and verify source retention, tamper ciphertext authentication failure, live lease skipping and stoppage for oversized payloads. Operator-only CLI can run a finite full WIRE_SCAN→PUBLISH→CONSUME cycle.
- This does NOT constitute a deployed persistent supervisor, safe automatic re-drive or commercial send readiness.
