# C4-01 Canonical Contracts

Independent library requiring PHP >=8.2. This is the first executable slice, not a Mautic integration or an end-to-end C4 PASS.

Run: php packages/contracts/tests/run.php

Contains typed UUIDv7, EntityRef, Actor, UTC Clock, versioned DomainEvent, EvidenceRef, KnowledgeState and decimal-string Money. No Mautic/Doctrine dependency. Do not use email as a Person key, store PII in general event payloads, treat UNKNOWN as ALLOW, use floats for amounts or claim universal exact-once transport.

Next: import pinned Mautic source into repository root, wire Composer and run integration/recovery tests.
