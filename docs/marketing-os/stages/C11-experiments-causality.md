# C11 — Experimentation, Holdouts & Causal Measurement
Status: DESIGNED, NOT IMPLEMENTED.

## Invariants
Random assignment != observed exposure != treatment received != conversion != causal effect. Attribution isn't incrementality. Statistical significance doesn't automatically imply economic worth. Failure to reject null doesn't prove equivalence or no effect. Experiment conclusion is NOT automatic authority to spend.

## Objects
Experiment(hypothesis, population, randomization_unit, primary metric, guardrails, start/end, outcome window, state/version);
ExperimentVersion immutable;
Variant CONTROL/TREATMENT, allocation;
ExperimentAssignment unique per experiment+unit, stable deterministic keyed hashing, persisted/sticky;
AnalysisPlan(frozen primary estimand, outcome definitions, metric/analysis unit, MDE, sample size/power, significance, fixed horizon, stopping rule);
ExclusionGroup/CollisionPolicy;
ExperimentAnalysisRun(input snapshot/watermark, sample counts, effect and CI, quality checks);
ExperimentConclusion(POSITIVE/NEGATIVE/EQUIVALENT_WITHIN_BOUNDS/INCONCLUSIVE/UNDERPOWERED/INVALID).

## First methodology
Randomized A/B and holdout only. First primary analysis intent-to-treat: compare units by original assignment even if consent/policy prevented email. Keep control measured with equivalent outcome windows. More advanced exposure-based/complier analysis requires explicit identification assumptions. Randomization unit may be PERSON/ACCOUNT/ORGANIZATION/CLUSTER and analysis respects clustering/interference.

## Quality
- A/A tests and simulated null effects for false-positive calibration.
- Sample Ratio Mismatch (SRM) at assignment level, compare expected allocation with actual distribution.
- Stable assignment through retries and identity changes; surface collision ambiguity.
- Cross-campaign contamination (e.g., Mautic legacy email sent to control).
- Missingness, biased telemetry, outcome-window maturity, lookbacks and refund treatment.
- Avoid ordinary repeated peeking with fixed-horizon p-values. Safety stop rules separately allowed.

## Economic question
Estimate treatment minus control difference in conversion/revenue/contribution, with uncertainty and incremental costs; don't equate last-click attributed revenue with incremental sales. Reports state limitations and whether enough participants were observed to support meaningful effect.

## Workflow integration
C9 Experiment node calls sticky assignment. CONTROL does not dispatch campaign email; this is NOT a failed effect. TREATMENT checks C6 and sends via C8 only if allowed; denied participant stays in assigned arm. C10 measures both groups.

## Initial engineering fixture
Welcome Newsletter Holdout, 50/50 illustrative allocation (subject to final statistical plan), primary 7-day activation outcome, guardrails unsubscribe/complaint, first using fake effects and synthetic outcomes. Validate randomization and SRM before real user experiment.

## Gate
Idempotent assignment, A/A correctness, contamination detection, no premature winner, result validity classification, immutable analysis plan, economic vs causal explanation.
