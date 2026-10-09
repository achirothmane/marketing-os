# 00 — Origin, scope, and constitution

## Identity
- Project name: **Marketing Automation Suite / Marketing OS**.
- GitHub source of record: achirothmane/marketing-os.
- Started conceptually in early October 2026. This record reflects decisions made during 2026-10-06 through 2026-10-09.
- The aim is a broad, self-hostable, AI-native marketing operating system supporting its owner's products and media properties first; commercial niches are chosen later from measured evidence.
- Build a useful internally owned asset which grows in utility with data, integrations, and repeated use. Avoid claiming revenue, customer traction, or successful builds without proof.

## Why this exists
Mautic is an established marketing-automation substrate and the designated starting point, not the final authority over our business model. The platform should ultimately coordinate identity, content, CRM, channels, workflows, experiments, measurement, and economics through a coherent kernel, with evidence and boundaries for every meaningful capability.

## Original thirteen conceptual layers (architectural map, not thirteen running services)
1. Identity / people, organizations, external identifiers.
2. Content / editorial and reusable message artifacts.
3. CRM / relationships, customer lifecycle and operator workflows.
4. Automation / journeys, triggers, orchestration.
5. Channels / email, messaging, social, web and providers.
6. Experiments / variants, assignments and measurement.
7. Analytics / observations, events and metrics.
8. Intelligence / research, diagnosis and recommendations.
9. Economics / costs, revenue, attribution, budgets.
10. Knowledge / accumulated research, source-backed decisions and retrieval.
11. Planning / strategy, priorities, portfolio and Dots handoffs.
12. Integrations / adapters, extensions, migration, reusable feature packs.
13. Platform Layer / tenancy, modularity, worker operations, upgrades, observability, restore, and isolation.

This list describes a capability map reconstructed from the approved broad direction; it is NOT a claim that these are the exact historical titles of thirteen code modules or that the layers have been implemented. C1 compressed their domains into a smaller shared kernel and engines.

## Feature-expansion archive
Prior brainstorming covered about 3,063 conceptual feature/catalog entries across roughly 18 expansion areas (social, SEO, ads, affiliate, research, scheduling, support, etc.). Those are IDEAS, NOT 3,063 shipped features, funded commitments, or a requirement to create 3,063 directories. Recover individual feature proposals from the conversation archive before calling them fully specified. A representative taxonomy belongs in feature-packs.md.

## Non-negotiables
- Broad capability first; do not prematurely shrink the platform to a small niche.
- **No build without evidence** when proposing a market-facing paid product; distinguish internal reusable infrastructure from paid-product demand.
- **Asset Test**: favor code, relationships, content, and trustworthy data whose useful value compounds with use.
- **Competitive Freshness Gate** before promoting a paid product: current competitors, feature parity, real differentiation, customer evidence.
- Product acceptance gates: Platform Acceptance Fit; Market Pull; Commercial Depth/MCP; Packaging.
- No automatic claim that sophistication equals revenue or that deployment implies product-market fit.
- Maintain upstream compatibility. Preserve Mautic's top-level filesystem when importing it; avoid reimplementing a mailer, ORM, queue broker, or CRM unnecessarily.
- Polyglot where justified: PHP/Symfony in the Mautic runtime, Python for AI/analytics, TypeScript for UI/integrations; Go/Rust only where the capability benefits.
- One **central Dots** is the cross-project coordinator; do not launch one independent Dots instance per repository. Marketing OS retains its own domain boundaries and decision history.
- Data Engine is a separate data substrate. It may clean, profile, reconcile or enrich datasets, but Marketing OS owns consent, campaign, audience, and customer semantics.
- Keep evidence and epistemic limits: KNOWN / UNKNOWN / AMBIGUOUS / CONFLICTING / REFUSED.
- Never turn uncertainty into authorization, a completed external effect, an attributable sale, or causal incrementality.
- Release only after passing functional, security, recovery and commercial gates appropriate to the capability.

## Explicit exclusions / safeguards
- No uncontrolled mass mailing or bypass of opt-out/consent/suppression. Contactability must be checked near dispatch.
- No identity merge solely by matching email.
- No AI-generated authorization or legal compliance claims without a deterministic governed policy.
- No generic IAM/agent-runtime platform inside Marketing OS.
- No assumption that exactly-once queue delivery implies exactly-once provider effect.
- No recreation of Aegis-EGE as an independent commercial product; use prior learnings selectively and with falsification.

## Record discipline
Every design/capability has owner, preconditions, required evidence, decision, confidence/boundary, failure scenarios and tests. All implementation statuses must be tied to branch/commit and test artifacts. Conversation approval = DESIGNED, not IMPLEMENTED.
