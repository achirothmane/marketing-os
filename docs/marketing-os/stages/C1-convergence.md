# C1 — Convergence: Domain Compression
Status: DESIGNED; architectural, not an executable PASS.
Source period: 2026-10-06 to 2026-10-08.

## Initial input
The platform began as a broad Marketing Automation Suite with 13 conceptual layers plus successive Feature Expansions: social networks, publishing, content, SEO, ads, research, affiliate, lead/sales, support, economics and other areas. Discussions enumerated roughly 3,063 conceptual capabilities across approximately 18 feature-expansion regions. They are not implemented commitments or market-validated SKUs.

## Accepted compression
Instead of implementing a layer or service for each feature, use:
1. Platform Kernel (workspace, IDs, evidence, contracts, provenance, transaction discipline).
2. Shared engine boundaries: Identity/Relationship; Event/Evidence; Policy/Consent; Profile/Audience; Workflow/Task; Connector/Effect; Content/Asset; Conversation/Case; Commerce/Revenue; Measurement/Economics; Experiment/Decision; Knowledge/Research; Planning/Portfolio.
3. Product/feature packs assembled from engines (email, social, SEO, ads, research, scheduling, affiliate, support, etc.).
4. Provider and legacy adapters into existing systems.

These are logical boundaries inside a modular monolith initially, NOT thirteen microservices.

## Avoid
- One service and database per imagined feature.
- One provider adapter that embeds its own consent/effect/idempotency rules.
- Infrastructure abstractions that do not prove reuse.
- Narrowing to a small commercial feature prematurely; commercialization is gated separately.

## Gate
An executable vertical slice must demonstrate canonical IDs, trusted evidence, durable events and reliable recovery before new feature packs are expanded.
