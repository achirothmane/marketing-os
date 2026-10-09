# C2 — Repository and Module Topology
Status: DESIGNED; M0 pinned source snapshot IMPORTED and source-smoke VERIFIED. Full Mautic runtime/DB and upstream regression NOT VERIFIED.
Source period: early October 2026.

## Core layout decision
Mautic upstream files MUST stay at repository root (app/bundles, bin, config, plugins, themes, tests, translations, vendor conventions). Do NOT nest source under upstream/mautic. This is an upgradeability and compatibility decision.

Add modules only when they have real code:
- packages/contracts, platform-kernel, event-evidence, identity, policy, audience, effects, workflow, content, assets, conversations, commerce, measurement, experiments, knowledge, planning.
- plugins/MarketingOSBundle as a thin anti-corruption bridge for Mautic.
- adapters/mautic plus mail/social/commerce/data-engine as justified.
- packs/social, seo, ads, research, scheduling, affiliate, support and others only with concrete needs.
- services/intelligence (Python) and ui/design-system (TypeScript) later.
- SDKs optional; Go/Rust only with demonstrated operational/performance benefit.

## Runtime
PHP/Symfony modular monolith + existing MySQL/MariaDB and Symfony Messenger infrastructure. Transactional outbox/inbox for reliable local actions. Explicit Effect ledger and reconciliation for remote irreversible effects. Native Mautic operations are retained as legacy compatibility while canonical truth migrates per capability.

## Avoid
Mautic Lead/Doctrine entities inside domain Person model. Business logic inside framework plugin subscribers. Independent 'new CRM' or 'new SMTP provider' without evidence. Empty structural placeholders.

## Execution prerequisites
Pin an exact compatible upstream Mautic 7.x commit, record its SHA/license, perform root import to existing GitHub repo, baseline Composer and tests, then register local contract packages without moving upstream files.

## Current known issue
marketing-os was EMPTY on 2026-10-09 before initial docs. Later M0 imported pinned Mautic 7.2.1 source (upstream commit 8cbb7ef874d52a411ae5a884f979acf6cc320181) into root. This is a SOURCE SNAPSHOT import, not full git ancestry, and not proof of runtime compatibility.
