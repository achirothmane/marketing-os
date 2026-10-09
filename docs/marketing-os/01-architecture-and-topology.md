# 01 — Architecture convergence and repository topology

## C1 — Convergence / Domain Compression
The early 13-layer capability map and 3,063-item conceptual expansion catalog were compressed into:
- A small **Platform Kernel** (workspace, typed identity, versioning, execution context, evidence, policy contracts).
- Thirteen **shared domain-engine boundaries**, initially modules, NOT thirteen microservices:
  1. Identity & Relationship
  2. Event & Evidence
  3. Policy / Consent / Eligibility
  4. Profile & Audience
  5. Workflow & Task
  6. Connector & Effect
  7. Content & Asset
  8. Conversation & Case
  9. Commerce & Revenue
  10. Measurement & Economics
  11. Experiment & Decision
  12. Knowledge & Research
  13. Planning & Portfolio
- Reusable feature packs (SEO, social, ads, email, affiliate, etc.) above engines.
- Thin provider and legacy adapters below engine ports.
Avoid megaservices and speculative independent deployments.

## C2 — Repository topology
The target is a Mautic-root-compatible hard fork: Mautic's existing app/bundles, bin, config, plugins, themes, tests, translations and root composer files remain in their UPSTREAM locations. Marketing OS-owned packages are additive.

    marketing-os/
      [Mautic original root files, after source import]
      packages/
        contracts/
        platform-kernel/
        event-evidence/
        identity/
        policy/
        audience/
        effects/
        workflow/
        content/
        assets/
        conversations/
        commerce/
        measurement/
        experiments/
        knowledge/
        planning/
      plugins/MarketingOSBundle/
      adapters/mautic/
      adapters/email/
      adapters/social/
      adapters/commerce/
      adapters/data-engine/
      packs/social/, packs/seo/, packs/affiliate/, ...
      services/intelligence/                 # later, only if justified
      ui/design-system/                      # later
      sdk/typescript/, sdk/python/           # later
      docs/marketing-os/

DO NOT create every empty path during initialization. Add real packages as executable slices need them. The final modules and names are subject to tested integration with pinned Mautic version.

## Boundary rules
- Domain/identity must not import Mautic Lead entities, Doctrine entities, Symfony HTTP Request, or a provider SDK.
- The Mautic bridge/adapter converts legacy integer Contact IDs into workspace-scoped canonical typed Person IDs.
- Never introduce an upstream/mautic nested source root: doing so complicates upstream updates and tests.
- Use a modular PHP/Symfony monolith initially. Reuse Mautic operations and Symfony Messenger for workers, and transactional outbox/inbox for durable business state and processing.
- Messenger is transport; durable workflow timers and effect records live in the database.
- Independently deployed Python Intelligence is an eventual option, not a prerequisite.
- Do not use Mautic segment membership as a universal truth or Mautic DNC as a complete consent model.
- Avoid duplicate engines doing the same thing; use one predicate/condition IR where semantics align.
- Data Engine (separate repository) provides generic data-processing; do not copy its ontology into Marketing OS.

## Open source/upstream compatibility
As of baseline, repository marketing-os was created EMPTY; it has not yet received the upstream Mautic source. Upstream targeted branch is mautic/mautic 7.x, but a specific immutable upstream commit, dependency lock, and local CI baseline MUST be selected before calling this a hard fork. Because this GitHub repo already exists, do not assume GitHub's ordinary Fork button can reuse its name. Import through a traceable Git upstream integration method with license notices intact; record both upstream commit and integration commit in an ADR.

## Initial runtime data flow
    Mautic Contact
      -> thin bridge snapshot
      -> workspace-bound Person/legacy mapping
      -> evidence + domain event + transactional outbox
      -> idempotent inbox consumer

Then evolve:
    C5 Profiles -> C7 Audiences -> C9 Workflows
    -> C6 policy near dispatch -> C8 Effects + receipts
    -> C10 Measurement + Economics -> C11 Experiments

## Four separate acceptance thresholds
1. Architectural intent recorded.
2. Code reviewed and merged in repository.
3. Reproducible CI/integration tests pass at a SHA.
4. Running deployment measured without regressions.
Never collapse these thresholds into one label.
