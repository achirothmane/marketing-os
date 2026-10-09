# C4-04 — Atomic Evidence, Domain Event, Outbox and Inbox

## Scope

The new importer `PdoCanonicalContactImporter` writes all five records in **one local InnoDB transaction**:

```text
Person + Legacy Mapping + Evidence + Domain Event + Outbox
                         COMMIT
                            |
                 At-least-once Publisher
                            |
               Idempotent Inbox + Projection
```

It accepts a workspace-scoped Mautic Contact number and a **caller asserted SHA-256 digest** (no raw email/name). This digest is not proof that a real Contact snapshot was read: C4-05 must produce and verify that snapshot. The legacy `PdoLegacyContactRegistry::registerMauticContact` from C4-03 remains an **isolated compatibility/test API** that does **not** create Evidence/Event/Outbox; C4-05 MUST use the new importer, not the legacy route.

## New migration

`002_evidence_event_outbox_inbox.sql` adds:
- `mos_evidence` (tenant-scoped observation digest and locator; no raw PII payload).
- `mos_domain_event` (immutable logical event, schema version, actor, correlation, causation, optional evidence FK, JSON payload).
- `mos_outbox` (same-transaction persisted publication intent, lease owner/fencing token, attempts, publication acknowledgement).
- `mos_inbox` (tenant+event+consumer uniqueness; consumer business projection is committed inside same local transaction).

Schema migration `001` and `002` are sequential and checksum-guarded. Since MariaDB DDL is non-transactional, partial migration requires explicit repair. No Mautic tables are modified.

**Important limitation:** existing C4-03 LegacyMap rows may have `evidence_id` values without evidence rows because the old registry created mapping-only records. Thus migration 002 cannot safely add a map->evidence FK to already populated databases; C4-05/C4-06 must reconcile preexisting mappings with evidence before enforcing that new constraint. New C4-04 imports always store real `mos_evidence` rows.

## Safety properties

1. Repeated import of the same `(workspace, mautic, contact, source id)` creates one canonical Person and one import event.
2. Two independent workers racing on the same legacy source key: one transaction wins; loser rolls back its tentative Person/Evidence and loads winner.
3. Five injected crashes **before commit** leave zero Person/Mapping/Evidence/Event/Outbox residue.
4. A committed outbox entry survives process failure before publishing; external publisher may deliver at least once.
5. The publisher marks published only after successful transport callback. A response lost after delivery retains a pending outbox row for safe replay.
6. A per-event, per-consumer Inbox marker and projection commit atomically; duplicate delivery is a no-op, and failed projection rolls back both marker and projection.
7. Message transport callbacks are injectable; no real email sends or external effects occur in this phase.
8. Release/lease expiry alone never establishes that the external consumer did not process the event. The sender and consumer must retain at-least-once semantics.

## Acceptance

Run `php packages/identity/tests/c4_04_integration.php` on an **isolated, disposable MariaDB 11.4 database** after migrations and C4-03 regression. The GitHub Actions workflow `c4-03-mariadb-persistence.yml` exercises isolated DB tests including the two-worker import and publisher lost-ACK cases. Record the exact SHA and run before changing this document from PLANNED to VERIFIED.

## Next

C4-05 Mautic Contact adapter: collect a real allowlisted snapshot, link its digest/evidence, import via C4-04, register an actual queue transport, and add the observed Contact->Person->Evidence->Event->Outbox->Inbox test. No bulk user contact ingestion or marketing sends are authorized before C6 permissions and C8 effect gates.
