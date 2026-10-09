# 10 — What is recorded, what is missing, and how to recover it

## Evidence sources used for this baseline
- Marketing Automation Suite / Marketing OS conversations 2026-10-06 through 2026-10-09: Layer 13; C1 through C11 design discussions; Mail Transition Engine; long-term philosophy and product gates.
- GitHub repository metadata/contents inspected on 2026-10-09: marketing-os existed, default branch main, EMPTY before the documentation initial commit.
- Local C4-01 bootstrap archive created 2026-10-09 (separate conversation attachment) and 13 reported local CLI tests. Do not substitute for code in GitHub or Mautic integration tests.
- Public upstream reference for future integration: https://github.com/mautic/mautic, branch 7.x at inspection; exact immutable commit NOT YET PINNED.

## Fidelity policy
This repository captures the material technical and governance decisions from the available context in organized, searchable form. It is a **structured reconstruction**, not a word-for-word transcript of every past ChatGPT message. Do not claim the complete verbal 3,063-feature list or any missing discussion is fully transcribed. When original prompts/files become available, attach them as dated annexes and update source mappings without silently modifying earlier decisions.

## Claims we explicitly cannot make today
- Upstream Mautic imported into marketing-os.
- A working Marketing OS deployed.
- C4 end-to-end Contact->Person test passed on real Mautic.
- Any C5–C11 engine implemented/merged.
- Customer demand or financial profit validated.
- Source MTE v0.8 code present inside this repo.

## How to avoid losing project memory
1. Keep this directory in Git and update through PR review.
2. At each stage, link exact PR/commit and CI test artifacts.
3. Append handoff chronology, not replace it.
4. Preserve superseded decisions and previous definitions.
5. For long conversations, produce a source-backed dated appendix before summarizing.
6. Keep one Dots coordinator's repository-specific checkpoint: current SHA, gate, blocker, next executable step.
7. Prefer narrow, testable PRs to large speculative implementation commits.

## Source retrieval TODO
- Full original Layer 1–13 titles and exact sequence, if they differ from the reconstructed architecture map.
- Exact source and enumeration for all ~3,063 idea catalog entries.
- Original MTE v0.8 source SHA and reproducible tests, if in another repository.
- C4-01 canonical source is now committed and PHP contract CI succeeded on PR #2; remaining work is root Composer integration and actual Mautic tests.
