# C4-07A — Durable Messenger transport for MOS Domain Events

**Status:** Implementation on feature branch; requires a green MariaDB CI run before promoting or merging.

## Architecture

```
C4-04 atomic Person+Map+Evidence+Event+Outbox COMMIT
  -> PdoOutboxPublisher claims pending event
  -> MosMessengerRelay sends ONLY (workspace_id,event_id)
  -> Symfony Messenger Doctrine transport (persistent MariaDB queue)
  -> independently restarted MosMessengerReceiver
  -> PdoInboxConsumer UNIQUE (workspace,event,consumer) + projection in ONE transaction
  -> queue ACK after projection commit
```

The repository lockfile pins Symfony Messenger and Symfony Doctrine Messenger. This slice exercises their actual persisted transport on MariaDB 11.4, not an in-memory callback.

### Contracts and invariants

- `MosEventPointer` carries only opaque UUIDv7 workspace and event IDs; no raw email, Contact fields, HMAC secret, or event payload crosses the transport.
- `MosMessengerJsonSerializer` accepts a single versioned JSON shape; rejects arbitrary serialized PHP classes and additional properties.
- `MosMessengerRelay` marks local outbox published **only after durable transport.send acknowledgement**. A lost ACK can produce a duplicate queue pointer. This is expected at-least-once behavior, not exactly-once network delivery.
- `MosMessengerReceiver` acknowledges the durable queue only after the Inbox marker and local projection COMMIT. Business side effects outside this transaction must have separate effect/receipt/reconciliation contracts (later C8).
- A worker crash before queue ACK allows redelivery; Inbox ensures one local projection, not that a marketing email was sent exactly once.
- Tests simulate lost transport ACK and worker failure, then restart a fresh queue/consumer connection; apply once and ACK on successful repeat.

### Remaining work / safety

- This slice does **not** register a Symfony/Mautic DI transport service or deploy a continuous worker. It tests a real Doctrine transport constructed explicitly. Worker supervision, failure queue, per-workspace quotas, backpressure, metrics, poison-message quarantine, lease expiry and a bounded CLI are later steps (C4-07B).
- The projection in tests is a disposable `mos_test_projection` table, not yet a production projection schema.
- No user Contact is imported here; fixtures are synthetic DomainEvents. Source-backed C4-05A tests and C4-05B/C snapshots are separate verified slices.
- No external email/ads/CRM effects are performed or authorized. C6 policy and C8 effect gates must precede those.
- Full C4 end-to-end and production deployment remain **NOT VERIFIED**.

## CI acceptance

- GitHub workflow: `.github/workflows/c4-07-messenger-doctrine.yml`.
- PHP 8.3, Composer lock, real MariaDB 11.4, Doctrine transport persistence across new connection, duplicate pointers, lost ACK, handler rollback, redelivery after simulated broker lease timeout, empty queue, and strict no-PII wire envelope.
- Record exact PR SHA and CI run in project ledger before changing to VERIFIED.
