# C8 — Effect & Receipt Engine
Status: DESIGNED, NOT IMPLEMENTED. Prior Mail Transition Engine reasoning is recorded separately.

## Separation
Intent != authorization != business Effect != technical Attempt != provider Receipt != ultimate external outcome. One effect per (workspace, effect_kind, stable_business_key), not a new business effect on every queue retry.

## Objects
EffectIntent(subject, resource, channel, purpose, business key, requested by/time);
Effect(state CREATED/AUTHORIZING/READY/RESERVED/DISPATCHING/PENDING_CONFIRMATION/SUCCEEDED/FAILED/CANCELLED/EXPIRED/CONFLICTING, plus knowledge state KNOWN/UNKNOWN/AMBIGUOUS/CONFLICTING);
EffectAttempt(attempt_no, adapter/account, provider key, request fingerprint, transport result, retry safety);
EffectReceipt(kind/source/provider ref, provider occurred_at, recorded_at, evidence);
EffectReservation(RESERVED/COMMITTED/RELEASED/EXPIRED, resource/capacity);
AdapterCapabilities(provider idempotency, status query, cancel, webhook, batch, scheduled send).

## Execution
1. Request stable Effect idempotently.
2. C6 policy recheck near dispatch.
3. Reserve slot atomically where needed.
4. Persist attempt/lease before external call (DB cannot remain open across network).
5. Dispatch via adapter.
6. Persist immediate receipt or UNKNOWN after ambiguous transmission.
7. Normalize signed, replay-protected webhooks; reconcile via provider status where supported.
8. Outcome reducer takes receipts and provider-specific success criterion, not arbitrary latest-wins.
9. Commit reservation on confirmed accepted/success criterion; release on definitive pre-effect failure; KEEP_RESERVED on ambiguity.

## Failure safety
Queue redelivery must not directly replay provider calls. A timeout after request possibly reached provider is UNKNOWN + RECONCILE_FIRST, unless provider idempotency guarantee applies. Lease expiry doesn't prove call was absent. If provider cannot reconcile and no idempotency support, block unsafe retry/manual evidence resolution.
Accepted by SMTP relay != delivered to human. Later bounce creates delivery observation/suppression evidence; do not retroactively overwrite a correctly defined provider-acceptance effect.

## First slice
SEND_EMAIL with Mautic/Symfony Mailer adapter after CaptureEmailAdapter and synthetic fixtures. Physical batch APIs may exist but business Effect stays per recipient. Existing Mautic Campaigns continue legacy execution during SHADOW; no mass resend or send to unauthorized people.

## Gate
Lost ACK no blind retry; duplicate webhook one receipt; conflict -> CONFLICTING; near-dispatch policy denial zero provider calls; UNKNOWN reservation held; crash between lease and call safe handling; stable workflow-derived business key links C9 and C8.
