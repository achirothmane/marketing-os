# 09 — Mail Transition Engine v0.8: historical decision record

## Context
During 2026-10-06 Marketing Automation Suite planning, a Mail Transition Engine v0.8 — Unknown Outcome Reconciler was described as end-to-end completed in conversation outside this newly created GitHub repository. No linked commit/ref inside marketing-os has been verified for that claim. Record it as HISTORICAL DESIGN/EXTERNAL CLAIM, NOT code merged here.

## Original flow (logical)
Audience Truth -> Sender Truth -> Message Truth -> Ramp -> Execution Lease -> Atomic RESERVE -> SendDock.
Known outcome:
- email.sent / SMTP relay accepted => COMMIT reserved send slot, according to the particular SendDock semantics used.
- email.bounced with hard 5xx rejection => RELEASE under prior contract (verify whether bounce was pre-acceptance before reusing broadly).
- email.failed representing timeout/transport/soft failure => KEEP_RESERVED until reconciliation.
Ambiguous or lost response => RESERVED -> signed verified webhook -> Outcome Reconciler -> COMMIT, RELEASE, KEEP_RESERVED, AMBIGUOUS or CONFLICT.

## Security and outcome semantics
- Verify webhook signature and prevent replay.
- Only evidence-supported state transitions.
- Do not confuse SMTP relay acceptance with end-human delivery, opens, clicks or conversion.
- Never blindly resend if an external effect may have occurred.
- A consumer/read-only verifier is not allowed to invent authoritative send state.
- Reservation must not be released simply because a timeout elapsed.
- A conflicting webhook does not override via arrival-order wins.

## Relationship to later architecture
This becomes a historical source for C8 Effect/Attempt/Receipt/Reservation/UNKNOWN Reconciliation.
C6 owns Consent/Suppression, C7 Audience Truth, C9 workflow sequencing. C8 must not carry all of those concerns inside its mail adapter.
Preserve original SendDock semantics as one adapter contract, not a universal invariant for all email providers: some providers acknowledge submission before later bounce. Provider-specific acceptance/finality capabilities determine safe retry and accounting.

## Integration checklist
- Recover exact source repository/commit of MTE v0.8, if it exists.
- Compare existing tests and terminology with C8 contract.
- Import reusable code only through reviewed commits preserving provenance and license.
- Falsify lost ACK, duplicate webhooks, hard/soft failure and late conflicting receipts against chosen real transport.
