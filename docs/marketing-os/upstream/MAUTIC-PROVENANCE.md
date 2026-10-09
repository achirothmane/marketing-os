# M0 - Mautic source import provenance

- Upstream: https://github.com/mautic/mautic
- Tag: 7.2.1 (published 2026-09-23)
- Immutable pinned commit: 8cbb7ef874d52a411ae5a884f979acf6cc320181
- Source snapshot: https://github.com/mautic/mautic/tree/8cbb7ef874d52a411ae5a884f979acf6cc320181
- License: GPL-3.0; see imported LICENSE.txt and upstream copyright notices.
- Import method: pinned Git source snapshot committed to Marketing OS history. This is NOT a Git ancestry merge of the full upstream Mautic history. Exact SHA is retained for provenance and upgrade comparisons.
- Existing Marketing OS README, docs/marketing-os and packages/contracts intentionally preserved.
- Upstream .github is intentionally excluded to prevent unreviewed CI workflows from running. Marketing OS .github is retained.
- Composer install, database migration, upstream runtime tests, deployment and Marketing OS integration are separately required.
- For upgrades, diff exact pinned commits; resolve owner-managed paths and test before merging.
