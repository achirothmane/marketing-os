# C4-02 - Canonical Person and Legacy Mapping (domain only)

A standalone domain slice for Person lifecycle with optimistic version checks, Mautic legacy source references, source-to-person mapping with required EvidenceId, and a read-side idempotency planner.

This DOES NOT add DB tables or UNIQUE constraints, Composer root wiring, Mautic subscribers, outbox/inbox, concurrent imports, or regulatory PII erasure. Email is never used as a Person key.

Run: php packages/identity/tests/run.php

Next: C4-03 database persistence with UNIQUE (workspace, legacy system, type, ID), concurrency/transaction tests; C4-04 event/outbox and C4-05 Mautic adapter.
