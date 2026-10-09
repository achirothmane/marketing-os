# C4 — First Executable Vertical Slice
Status: C4-01/02/03/04 source merged and CI verified for their respective local/MariaDB scopes; full Mautic Contact->Inbox vertical slice NOT verified (C4-05 onward remains).
Priority: FIRST executable milestone after upstream import and baseline test.

## Goal
Mautic Contact -> thin snapshot adapter -> Canonical Person -> Evidence -> Domain Event -> transactional Outbox -> Messenger -> idempotent Inbox Consumer.

## PR sequence
C4-01 contracts; C4-02 identity aggregate; C4-03 identity schema; C4-04 event/evidence/outbox/inbox; C4-05 bridge plugin; C4-06 keyset backfill; C4-07 publisher/consumer; C4-08 falsification suite; C4-09 shadow rollout, metrics, kill switch.

## Domain behavior
A Contact legacy ID is workspace scoped and maps to exactly one Person, with idempotent import. Missing/invalid email doesn't abort the Person import. Two contacts sharing an email create distinct Persons and an ambiguity report, never silent merge. Contact updates refresh evidence but don't emit repeated imported events. Do not implement general bidirectional profile syncing yet.

## Bridge
Plugin: plugins/MarketingOSBundle, modern Symfony EventSubscriberInterface listening for relevant Mautic contact post-save events after verifying event semantics against pinned upstream source. Subscriber creates allowlisted LegacyContactSnapshot, then sends ImportLegacyContact command to application handler; Domain must not import Lead entity. Guard projection echoes. Backfill CLI uses id>lastId keyset paging, batch size, checkpoint, dry-run, limit, diagnostic collision counts.

## Event flow
A transaction checks legacy map, stores evidence, creates Person + legacy identity and mapping, records identity.person.imported.v1 plus outbox. On uniqueness race, losing transaction reloads winner. Outbox publisher may deliver more than once. Consumer's business projection and unique inbox marker commit atomically.

## Failure cases
- Kill publisher after DB commit: outbox survives.
- Crash after delivery before published_at write: duplicate delivery must be harmless.
- Concurrent imports: one mapping/event.
- Same email: no forced merge.
- Event logs: no raw email/PII.
- Previous Mautic contacts and campaigns unaffected.

## Ops
Shadow mode OFF/SHADOW/ACTIVE; start SHADOW. Status and backlog metrics; avoid enormous backfill without rate limits. Baseline performance 10k synthetic contacts as benchmark, no arbitrary target without evidence.

## Local seed limitation
The prior archive packages/contracts contains 18 files with UUIDv7, typed IDs, actor/clock, event/evidence, knowledge state, Money, CLI tests and README. Need commit actual files and run tests in repo; local PASS does not imply Mautic integration.

## Verified provenance of first code
- Local bootstrap archive existed with 13 tests; its contracts were reconstructed as a standalone package and committed via PR #2.
- GitHub CI PHP 8.2 and 8.3: SUCCESS for syntax and 13 behavior tests.
- C4-02 Person/mapping, transaction outbox/inbox and Mautic bridge remain future work.
