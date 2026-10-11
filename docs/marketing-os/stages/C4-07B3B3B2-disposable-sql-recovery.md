# C4-07B3B3B2 — Actual disposable SQL backup and restore

**Status:** MERGED AND VERIFIED for disposable same-server SQL dump and import. [PR #52](https://github.com/achirothmane/marketing-os/pull/52) merged cbfa3051c2b124f0309cccd6d3065de52dc225bd; [MariaDB 11.4 CI 38149136108](https://github.com/achirothmane/marketing-os/actions/runs/38149136108) **8/8 PASS**. All 11 prior PR-head M0/C4 workflows SUCCESS. This is a **database-level** recovery rehearsal inside GitHub Actions, not remote disaster recovery or deployed service verification.

The pinned C4-08B workflow installs actual Mautic 7.2.1 into MariaDB 11.4, runs its real SHADOW Mautic Contact-to-Inbox acceptance and C4-07B3B3A protected-credential service-cycle tests. Only after both pass does the workflow take a genuine MariaDB SQL dump of the resulting database using mariadb-dump --single-transaction. It calculates/checks the private dump file's SHA-256, creates a distinct disposable database and imports the SQL file with the MariaDB client. Both database instances live on the **same test server**.

The read-only MosDatabaseRestoreAttestor compares the entire restored table set, ordered column schema metadata and byte-preserving sorted row digests across all SQL tables, including real Mautic and MOS data. It requires core Contact/Person/Evidence/Event/Outbox/Inbox/Messenger/encrypted-quarantine/migration tables, checks row and archive counts, then the test independently authenticates recovered quarantined ciphertext using the known disposable historical key. Corruption of restored Person and DLQ rows must cause a refusal, and restoring them must recover identical proof.

No SQL dump, raw Contact, secret or plaintext archive is uploaded to GitHub artifacts, printed to stdout or committed. Temporary dump material is deleted in an always-run workflow step. Database credentials and cryptographic keys are **CI-only disposable fixtures**.

This tests a real SQL export/import of the database under a frozen test workload. It does not test a physically separate host, secure external backup media, source files/uploads/volumes, exact point-in-time guarantees under concurrent writes, encryption-key escrow, network outages, a real systemd deployment, operator authorization or email sending. **C4 overall remains NOT VERIFIED.** C4-07B3B3B3 requires independently operated host, backup retention, secret restoration, service health/restart drills and authorized review workflow before any production claim.

## Verified checkpoint — 2026-10-11

- Actual private mariadb-dump SQL backup was exported after pinned real Mautic/SHADOW integration tests, hash-checked and imported into separately named disposable MariaDB database. This is not a row-copy simulation.
- All SQL table names, ordered column metadata and byte-preserving row digests matched source, with selected durable MOS/Contact/Outbox/Inbox tables required; restored encrypted archives authenticated. Tampering was detected and rerestoration of test rows returned PASS.
- Limit: both schemas on same ephemeral CI server; no true second host, external persisted storage, uploads, secret escrow, installed supervision, or live concurrent-write recovery. Entire C4 remains NOT VERIFIED.
