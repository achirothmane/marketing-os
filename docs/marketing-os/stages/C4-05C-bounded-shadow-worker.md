# C4-05C — Opt-in bounded Contact reconciliation worker

Status: proposed until CI passes. No cron job or production service has been installed.

## Runtime contract

- C4-05B proved Mautic LEAD_POST_SAVE can fire before an outer transaction commits. Never use that callback as permission to import or send.
- A dedicated cron-ready, CLI-based bounded worker reads committed source rows and uses the atomic C4-04 importer. It does not register a live Mautic subscriber or send messages.
- Fail closed: both MOS_BRIDGE_MODE=SHADOW and MOS_SCHEDULED_SHADOW_ENABLED=1 are required. Explicit Workspace UUID, MySQL DSN/credentials, 32+ byte HMAC secret and optional validated source table prefix are mandatory.
- Worker defaults to 100 IDs/batch, 5 batches, 30 seconds; maximum 1000 IDs/batch, 100 batches, 300 seconds. Time is checked between batches, not inside a DB transaction.
- A workspace-specific MariaDB advisory lock excludes overlapping operations per batch. The durable cursor wraps to zero after end-of-sweep, allowing eventual discovery of late commits.
- Unexpected failure before cursor checkpoint means the next run replays from old cursor. Person/Evidence/Event/Outbox remain idempotent and are not duplicated.
- Blocked records return exit code 2 with aggregate reason counts; unexpected error returns 1. Both need an operator. No raw email/name or secret output. Zero exit does not establish full source completeness or absence of consent issues.

## Staging-only scheduling example (disabled until manually configured)

Supply secret environment variables through a host secret manager, never in crontab, shell history, repository or argv. Apply migration 003, provision least-privilege DB credentials and the intended Workspace first.

Illustrative cron line, for an operator to install on a STAGING host after verification:

    */5 * * * * cd /srv/marketing-os && /usr/bin/php packages/identity/bin/run_scheduled_shadow_scan.php WORKSPACE_UUID >> /var/log/marketing-os-shadow-summary.log 2>&1

The example is documentation only. No cron, server or hosted background process is provisioned by this PR. Operators must route exit 2 to review and monitor exit codes/lag; do not use a bare cron setup as proof of alerts.

## Falsification

- Real Mautic 7.2.1 and MariaDB 11.4: bounded batches, end-of-sweep stop, disable-by-default and explicitly enabled JSON CLI.
- Database advisory lock contention refuses a concurrent scanner.
- A child PHP process exits abruptly immediately after Person/Evidence/Event/Outbox are committed but before scanner cursor checkpoint. Fresh process recovery replays without a second Person or DomainEvent.
- Existing C4-03, C4-04, C4-05A/B tests continue to prove transaction rollback, source commit and late lower-ID recovery.

## Boundaries

No production activation, Mautic EventSubscriber, automatic scheduling on a real server, Messenger queue delivery, human Inbox processing, Contact source updates/deletes/consent reconciliation, or production-scale load proof. The cursor is only a progress hint and full C4 remains unverified.
