# C4-07B3B3B2 — Actual disposable SQL backup and restore

**Status:** IMPLEMENTED ON BRANCH; CI pending. This is a **database-level** recovery rehearsal inside GitHub Actions, not remote disaster recovery or deployed service verification.

The pinned C4-08B workflow installs actual Mautic 7.2.1 into MariaDB 11.4, runs its real SHADOW Mautic Contact-to-Inbox acceptance and C4-07B3B3A protected-credential service-cycle tests. Only after both pass does the workflow take a genuine MariaDB SQL dump of the resulting database using mariadb-dump --single-transaction. It calculates/checks the private dump file's SHA-256, creates a distinct disposable database and imports the SQL file with the MariaDB client. Both database instances live on the **same test server**.

The read-only MosDatabaseRestoreAttestor compares the entire restored table set, ordered column schema metadata and byte-preserving sorted row digests across all SQL tables, including real Mautic and MOS data. It requires core Contact/Person/Evidence/Event/Outbox/Inbox/Messenger/encrypted-quarantine/migration tables, checks row and archive counts, then the test independently authenticates recovered quarantined ciphertext using the known disposable historical key. Corruption of restored Person and DLQ rows must cause a refusal, and restoring them must recover identical proof.

No SQL dump, raw Contact, secret or plaintext archive is uploaded to GitHub artifacts, printed to stdout or committed. Temporary dump material is deleted in an always-run workflow step. Database credentials and cryptographic keys are **CI-only disposable fixtures**.

This tests a real SQL export/import of the database under a frozen test workload. It does not test a physically separate host, secure external backup media, source files/uploads/volumes, exact point-in-time guarantees under concurrent writes, encryption-key escrow, network outages, a real systemd deployment, operator authorization or email sending. **C4 overall remains NOT VERIFIED.** C4-07B3B3B3 requires independently operated host, backup retention, secret restoration, service health/restart drills and authorized review workflow before any production claim.
