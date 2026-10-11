# C4-07B3B3B1 — Archive recovery comparison and read-only review

Status: IMPLEMENTED ON BRANCH, CI pending. Not a full-database backup or recovery demonstration.

The verifier compares encrypted quarantine archive rows for a single Workspace between two separate MariaDB databases, authenticates each record with its matching historical key and checks original identity, metadata and encrypted bytes. Pagination is bounded (1–100), completeness is explicit, and an empty archive is not treated as proof. Incorrect keys, modified rows and missing rows stop verification.

The disposable CI run independently creates a second MariaDB schema and copies encrypted archive records into it. It then attempts byte-for-byte authenticated comparison as well as deliberately damaged restore scenarios. This proves only the selected encrypted archive rows can be compared across two schemas. It does not prove restoration of a physical database backup, other MOS tables, or operation on a remote server.

The new dlq_review_queue.php command reads authenticated encrypted cases with READ credentials and separate explicit SHADOW opt-ins. It returns only an identifier, reason, key ID and ciphertext digest; each case stays HOLD_FOR_OPERATOR. No approval, deletion, automatic retry, or customer communication is implemented.

Next acceptance gates: real backup-media restore and key recovery on an independent machine; verified service installation and restricted OS identity; full Contact/Person/Outbox/Inbox restore; authenticated and audited human disposition; C5/C6/C8 external permission policy. C4 overall remains NOT VERIFIED. No marketing sends.
