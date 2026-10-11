# C4-07B3B3A — Protected-credential one-shot SHADOW cycle

**Status: MERGED AND VERIFIED for disposable Mautic 7.2.1 / MariaDB 11.4 CI scope only.** [PR #48](https://github.com/achirothmane/marketing-os/pull/48) merged at 2064ae495e0cbe4ed36aa14b75b399a74983b9ea; [CI run 38146136473](https://github.com/achirothmane/marketing-os/actions/runs/38146136473) **12/12 PASS**. All other 10 PR-head M0/C4 workflows passed. Templates are not deployed. This is not a deployed service or a production key-management solution.

## Executable behavior

Entry point: packages/identity/bin/run_credentialed_shadow_cycle.php WORKSPACE_UUID.

All independent opt-ins must be explicitly enabled: MOS_SERVICE_ENABLED, MOS_PREFLIGHT_ENABLED, MOS_PIPELINE_ENABLED, MOS_SCHEDULED_SHADOW_ENABLED, MOS_SUPERVISOR_ENABLED and MOS_QUEUE_ENABLED are 1, while MOS_BRIDGE_MODE and MOS_QUEUE_MODE are SHADOW. MOS_DLQ_ACTIVE_KEY_ID must be set to a valid active identifier. Runtime READ and WRITE DSN/user profiles from C4-07B3B2 are required. No implicit test fallback.

A protected CREDENTIALS_DIRECTORY must contain four operator-provisioned files: mos_read_db_password, mos_write_db_password, mos_snapshot_hmac_key, and mos_dlq_key_b64. Missing, symlinked, group-readable, oversized or invalid secret files cause refusal. Direct environment collision with any of these secret variables also causes refusal. Files are loaded into process memory, then passed to the existing bounded CLI workers through a child process environment. A dedicated restricted OS identity is still required; in-process handling is not proof of external secret escrow.

Before spawning the source-to-Inbox worker, the service acquires a Workspace-scoped advisory lock and checks the read-only C4-07B3B1 preflight with the READ role. Only LOCAL_PREFLIGHT_PASS allows the bounded child cycle (at most 100 source IDs x 4 scan batches and one queue cycle of 10). The child must return a valid Workspace-specific receipt. Afterward the same read-only preflight runs again. Newly archived malformed wire leads to review rather than silent success. This runner never performs encrypted-wire redrive, recipient contact or external-provider effects.

Result and exit contract:
- Exit 0: LOCAL_SHADOW_CYCLE_PASS after verified local-only preflight, bounded child execution and postflight.
- Exit 3: REVIEW_REQUIRED before or after child; review reasons are aggregate codes only.
- Exit 2: blocked flags, secret input, lock, database, child, receipt or other unsafe condition. No raw SQL, Contact payload or secret appears in the refusal output.

## Proposed systemd service and timer

deploy/systemd/mos-shadow@.service, mos-shadow@.timer and shadow-example.env are **non-installed operator templates**. The oneshot unit assumes an already provisioned, restricted mos-shadow OS account, a read-only code tree at /opt/marketing-os, non-secret configuration under /etc/marketing-os/shadow, and secret files under /etc/marketing-os/secrets. systemd LoadCredential provisions the protected runtime directory. No credential value is committed here. The example configuration is disabled by default.

The optional timer schedules another bounded cycle after 15 minutes and is not a service-health certificate or an automatically authorized recovery. Disable it during investigation, maintenance, backup, migration or key rotation. The operator must verify service identity, OS permissions, network policy, secret backup, restore and end-to-end behavior on the actual target host before enabling anything.

## Secret backup and encrypted DLQ handling

The protected key values, active key-ID manifest, archive database and backups form one recovery set. Pause all supervisors first; use C4-07B3A bounded INVENTORY and authenticated VERIFY over all pages. Back up the archive and secret/key-ID manifests to protected storage. Rotate only with the explicit C4-07B3A operator approval, verify all new records, and retain historical keys needed to decrypt backups. A single Workspace verification is not global proof of recoverability.

An archived record is a **case for human review**, not permission to re-drive. No manual authorized redrive/disposition CLI exists in this change; no plaintext is exposed. An actual recovery exercise, escrow integration, operator audit and separation of duties remain future acceptance gates (C4-07B3B3B).

## Verification and remaining limits

CI must install pinned Mautic 7.2.1 and disposable MariaDB 11.4, exercise a Contact through the real local queue and Inbox, prove repeat idempotency, reject environment secrets and unsafe credential files, refuse duplicate Workspace supervision, detect new and existing quarantines and preserve another Workspace's ability to run.

Passing this CI is **not proof** of real systemd deployment, least-privileged production database provisioning, disaster recovery, security posture, external sending authorization or revenue. Overall C4 stays NOT VERIFIED, NOT SHIPPED; C5/C6/C8 effects remain OFF.

## Verified checkpoint — 2026-10-11

- Code PR #48 merge 2064ae495e0cbe4ed36aa14b75b399a74983b9ea; GitHub Actions run 38146136473 on installed pinned Mautic 7.2.1 with disposable MariaDB 11.4: 12/12 PASS. Ten other PR-head workflows successful.
- Verified: inaccessible and insecure credential rejection, actual Contact->Messenger->Inbox one-shot internal flow, replay idempotence, duplicate Workspace supervisor lock, ciphertext quarantine requiring review after the cycle, and preflight blocking a following cycle; second Workspace remains isolated.
- Not verified: actual installed and enabled systemd service/timer, managed escrow or backup restoration, human-approved DLQ re-drive, deployed tenant boundary and full C4 commercial effects. C4 NOT VERIFIED overall. C4-07B3B3B must resolve these before operating outside the test environment.
