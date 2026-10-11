# C4-07B3B3B1 — Archive recovery comparison and read-only review

Status: MERGED AND VERIFIED for scoped encrypted-archive comparison and HOLD-only review. PR #50 merged at 76ac79742fe0f85ec941f2331f1d433f9dcb01fd; MariaDB 11.4 CI 38148508716: 16/16 PASS, and ten earlier M0/C4 PR-head workflows SUCCESS. Not a full-database backup or recovery demonstration.

The verifier compares encrypted quarantine archive rows for a single Workspace between two separate MariaDB databases, authenticates each record with its matching historical key and checks original identity, metadata and encrypted bytes. Pagination is bounded (1–100), completeness is explicit, and an empty archive is not treated as proof. Incorrect keys, modified rows and missing rows stop verification.

The disposable CI run independently creates a second MariaDB schema and copies encrypted archive records into it. It then attempts byte-for-byte authenticated comparison as well as deliberately damaged restore scenarios. This proves only the selected encrypted archive rows can be compared across two schemas. It does not prove restoration of a physical database backup, other MOS tables, or operation on a remote server.

The new dlq_review_queue.php command reads authenticated encrypted cases with READ credentials and separate explicit SHADOW opt-ins. It returns only an identifier, reason, key ID and ciphertext digest; each case stays HOLD_FOR_OPERATOR. No approval, deletion, automatic retry, or customer communication is implemented.

Next acceptance gates: real backup-media restore and key recovery on an independent machine; verified service installation and restricted OS identity; full Contact/Person/Outbox/Inbox restore; authenticated and audited human disposition; C5/C6/C8 external permission policy. C4 overall remains NOT VERIFIED. No marketing sends.

## 2026-10-11 verified checkpoint

- PR #50 https://github.com/achirothmane/marketing-os/pull/50 merged SHA 76ac79742fe0f85ec941f2331f1d433f9dcb01fd; CI https://github.com/achirothmane/marketing-os/actions/runs/38148508716 on MariaDB 11.4 passed 16/16 tests.
- Authenticated comparison of ciphertext, metadata and historical keys in independent disposable DB schemas; tampering, missing archive records and missing/wrong keys were rejected. HOLD-only read-only review CLI returned PII-free cases without mutating the queue or archives.
- NOT verified: actual restored full backup media, any real host, backup escrow, full MOS event state, audit/dual-control disposition or any external marketing action. Top-level C4 stays NOT VERIFIED.
