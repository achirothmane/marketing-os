# C10 — Measurement, Exposure & Economics Spine
Status: DESIGNED, NOT IMPLEMENTED.

## Critical distinctions
Observation != Exposure != Conversion != Attribution != Causal Lift != Revenue != Profit.
Email accepted by relay is provider acceptance; it is NOT proof the human was exposed. A sale after an email click does NOT prove the email caused the sale.

## Ownership
C9 knows journey; C8 knows attempts/receipts; C7 knows audience; C6 knows contactability. C10 owns metric definitions, measurement observations, exposures, conversions, marketing-related financial mirror ledgers and attribution runs. Commerce/payment systems remain authoritative for original transactions; Marketing OS is NOT a general ledger/accounting system.

## Models
MetricDefinition(key, dimensions, grain, unit, numerator/denominator, event-time rules, version, precision).
MetricResult(value, time window, data watermark, evidence coverage, COMPLETE/PARTIAL/STALE/INSUFFICIENT/CONFLICTING).
MeasurementObservation(source_event_id, source, occurred_at, recorded_at, identity ref, evidence).
ExposureObservation(surface, kind, DIRECTLY_INSTRUMENTED/PROVIDER_REPORTED/INFERRED/UNVERIFIABLE, evidence, assignment ref).
ConversionDefinition(qualifying event, identity requirements, dedup, qualification window, value rule).
Conversion(instance, source transaction, stable dedup, occurred/recorded timestamps, evidence).
RevenueEntry(SALE/RECURRING_PAYMENT/REFUND/CHARGEBACK/ADJUSTMENT, amount/currency/source transaction/evidence).
CostEntry(AD_SPEND/EMAIL_DELIVERY/AI/INFRA/AFFILIATE/etc, direct vs allocated vs estimate, evidence).
AttributionModel(version, FIRST_TOUCH/LAST_TOUCH/DIRECT_ASSOCIATION/UNATTRIBUTED, lookback, identity policy).
AttributionRun(immutable inputs/watermark/model), AttributionCredit(conversion,touch,credit fraction/amount).

## Finance safeguards
Processor fees != refund != gross revenue != settled cash; do not double count payment/provider webhook and legacy CRM order. Refund/correction creates linked reversal entry. Never sum currencies without evidenced FX; missing cost != 0. Attribution model changes regenerate credited reports, not underlying revenue records. Net/contribution is not statutory accounting profit.

## Ingestion
Use C3 Event/Evidence spine and separate Data Engine for generic ingestion/profiling. Canonical business-admission layer validates domain semantics, dedup and actor/source/identity quality. Late arrivals can trigger versioned recomputations with updated watermarks.

## First test case
Synthetic audience -> controlled email -> captured click -> confirmed fake order/payment -> mapped revenue/cost -> versioned attributed report. No actual commercial results claimed.

## Gate
Replay duplicate purchase stays one conversion, missing data yields UNKNOWN, click without experimental contrast doesn't produce 'incremental' money, report shows evidence coverage and limitations.
