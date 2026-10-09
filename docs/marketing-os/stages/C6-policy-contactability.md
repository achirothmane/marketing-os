# C6 — Policy, Consent & Contactability
Status: DESIGNED, NOT IMPLEMENTED.

## Central question
Can(Subject, Action, Channel, Purpose, Resource, Context) -> outcome, reason, policy version, evidence, knowledge, valid_until. Outcomes ALLOW/DENY/REVIEW/UNKNOWN/NOT_APPLICABLE. Epistemic confidence is a separate field. UNKNOWN cannot silently authorize a marketing email.

## Separation of concerns
Permission (may contact) != Contactability (valid endpoint) != preference (desires) != suppression (blocking) != operational send capacity.
Purpose registry includes marketing.newsletter, marketing.promotions, sales.outreach, support.case_update, transactional.order_receipt, etc. Purpose is explicit and immutable at enforcement; labeling marketing as transactional does not bypass consent.
Channel registry: EMAIL, SMS, WHATSAPP, PUSH, IN_APP, WEB_PUSH, VOICE etc. Channel != provider.

## Evidence ledgers
- Append-only consent entries scoped by subject + channel + purpose with source and evidence; GRANTED / DENIED / WITHDRAWN / UNKNOWN / NOT_REQUIRED. NOT_REQUIRED does not equal ALLOW.
- Suppression entries scoped by person/contact point/channel/purpose, reason (UNSUBSCRIBE, HARD_BOUNCE, COMPLAINT, MANUAL_BLOCK, LEGAL_BLOCK, LEGACY_UNKNOWN), lifecycle.
- Preferences (channel choice, frequency, topics), pause interval and quiet hours separate from consent.
- Frequency windows computed from effect/delivery history; communication pressure across channels where defined.
- Mautic DNC and Preference Center are imported legacy observations; do not equate DNC reason MANUAL with consent withdrawal.

## Evaluation / persistence
Deterministic typed rules and versioned PolicyBundle (avoid premature custom DSL). Evaluation checks endpoint, purpose, consent/basis, suppression, preferences, pause, quiet hours, frequency and workspace constraints; store enforced decisions and evidence, not every preview evaluation.
Re-evaluate near C8 dispatch; audit racing unsubscribe without making impossible claims about cancellation after irreversible send.

## Rollout
Legacy-vs-canonical shadow comparator: safety divergence vs liberalization divergence. Conservative cutover honors old suppression; canonical -> legacy DNC only add-only, no automatic removal. Start with EMAIL + marketing.newsletter. Purpose and regional legal rules require explicit configuration/review, not blanket legality claims.

## Gate
Withdrawal wins; bounce != consent withdrawal; missing required evidence never ALLOW; policy version attached to send; pause expires, preference doesn't grant channel rights; legacy protection can't be silently bypassed.
