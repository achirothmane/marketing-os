# C4-07B3B1 — Local read-only SHADOW readiness preflight

**Status:** IMPLEMENTED ON FEATURE BRANCH; GitHub Actions MariaDB verification PENDING. This document does **not** claim any production deployment, operator secret manager, automated queue supervisor, authorized redrive or C4 completion.

## Intent

Before a future supervised service is permitted to run, a scoped Workspace can be inspected without replay, dispatch, schema migration or secret/PII output. This is a bounded **local** preflight, not a guarantee of a safely deployed system.

`MosShadowReadinessPreflight::assess()` checks:
- All six exact migration SHA-256 values against the checked-in migration files; fails closed on missing, unexpected or altered versions or required tables.
- Workspace existence and isolation by explicit UUID; never scans other Workspaces' queue state.
- Active-key XChaCha20-Poly1305 archive authentication in bounded pages. Unvisited pages are marked incomplete; old-key ciphertext is not assumed decryptable and forces review.
- Outbox backlog, Messenger queue depth, oversized queue bytes, valid-pointer quarantine count and encrypted malformed-wire archive counts.
- No read of raw Contact fields, no wire plaintext emitted, no migration, no redrive, no launch and no network/provider effect.

CLI:

`php packages/identity/bin/shadow_readiness_preflight.php WORKSPACE_UUID ACTIVE_KEY_ID PAGE_LIMIT`

Requires `MOS_PREFLIGHT_ENABLED=1`, `MOS_QUEUE_MODE=SHADOW`, `MOS_BRIDGE_MODE=SHADOW`, externally supplied `MOS_SNAPSHOT_HMAC_KEY` (at least 32 characters) and `MOS_DLQ_KEY_B64` (32 raw bytes, base64-encoded). The existing isolated `MOS_TEST_DSN` connector is still used; **this is not a production credential integration**. Secret material must never be passed in argv or stored in the repository.

Result is PII-free JSON, with explicit:
- `LOCAL_PREFLIGHT_PASS` (exit 0): scoped local checks passed; **not** a deployment certificate.
- `REVIEW_REQUIRED` (exit 3): non-active keys, incomplete verification, archived/poison rows, oversized wires or queue backpressure need operator review.
- Refusal (exit 2): invalid flags/keys, missing Workspace, changed schema checksum, database failure or unauthenticated archive.

## Falsification and limitations

CI must prove checksum mismatch, corrupt AEAD, old key, pagination boundaries, oversized queue retention, tenant isolation, strict opt-in and safe receipt classification. The command must never replace the encrypted quarantine human review or authorize sending.

Still open for C4-07B3B: an actual deployed service runner, runtime-vs-test DB bootstrap separation, least-privileged workspace credentials, external secrets escrow and recovery drills, key replacement across backups, approved/manual DLQ review and redrive workflow, staging upgrade/rollback and full upstream security/regression evidence. C5/C6/C8 policy and external marketing effects remain OFF.
