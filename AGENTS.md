# Thumbnail Manager Project Instructions

These instructions apply to the entire `thumbnail-manager` repository.

## Project authority

- This repository is the source of truth for Thumbnail Manager code, tests, metadata, and release preparation.
- `main` contains reviewed code. Do not push implementation work directly to `main`.
- WordPress.org publication, production deployment, release tags, and version decisions remain explicit Human actions.
- Runtime safety for media files takes precedence over convenience or refactor simplicity.

## Git safety and branch model

- Inspect branch/base state and the relevant diff before changing code.
- Preserve unrelated user work and never rewrite shared history.
- Never use force-push, destructive reset, or branch-protection weakening as part of normal task execution.
- Use `agent/<scope>` for normal feature, fix, test, tooling, and documentation work.
- Use `release/<version>` only for short-lived release preparation when needed.
- Concurrent Codex tasks must use separate worktrees or clean checkouts.
- Keep commits logically grouped and stage only files that belong to the task.
- Open or update a draft pull request for implementation work; the pull request is the primary durable execution record.

## Roles

- **Human** owns product direction, unresolved trade-offs, merge approval, release/version decisions, production actions, and WordPress.org publication.
- **ChatGPT** owns task framing, risk classification, architecture/product decisions, plan review for Controlled work, and the single external review gate focused on approved-plan/diff alignment, architecture and invariants, safety boundaries, scope drift, compatibility, and evidence sufficiency.
- **Codex** owns repository discovery, implementation, tests and runtime reproduction, validation, repo-local technical/adversarial verification before handoff, commits, branch/PR maintenance, and correction of review findings.
- **GitHub** is the durable record for commits, pull requests, CI, review results, and Controlled-Lane planning handoffs.

## Human command shorthand and routing

Keep Human commands short. Equivalent natural-language commands such as `Chạy task này`, `Tiếp tục`, `Review`, and `Sửa tiếp` must be resolved from the current conversation and durable GitHub state rather than requiring the Human to restate known context.

Commands may optionally include a target prefix. The prefix is a routing hint, not part of the task identifier or workflow status:

- `Codex - Continue TM-AUD-0001` tells Codex to recover the newest applicable GitHub state for that task and perform the next Codex-owned action.
- `ChatGPT - Review TM-AUD-0001` tells ChatGPT to recover the newest applicable durable handoff and route it to plan review, technical review, correction re-review, or Human decision as appropriate.
- General short commands may use the same form, for example `ChatGPT - Add workflow shorthand rule` or `Codex - Fix the current review findings`.
- `Human - ...` may be used when the next action is explicitly Human-owned, such as a merge, product decision, release action, or production action.

When a task ID or PR/issue reference is supplied, do not ask the Human to repeat plan text, findings, SHAs, PR numbers, or other context that can be recovered safely from GitHub. This rule applies in fresh ChatGPT/Codex threads as well as continuing conversations.

`Continue <TASK-ID>` and `Review <TASK-ID>` are canonical shorthand:

- `Continue <TASK-ID>` means recover the latest durable task state and continue the next action owned by the receiving agent. For Codex this includes correcting a rejected plan, implementing an approved plan, correcting technical-review findings, or continuing other repository work already authorized by the current workflow state.
- `Review <TASK-ID>` means ChatGPT recovers and reviews the newest applicable durable handoff rather than asking the Human which review stage applies.
- For pull-request state recovery, inspect both top-level PR conversation comments and PR review submissions. Order applicable records by creation/submission time and exact-head applicability; a newer exact-head review carrying a workflow status must not be hidden by an older top-level handoff.
- If a detailed review submission and a mirrored top-level handoff represent the same decision, the top-level PR comment is the canonical actionable handoff and the review submission is the detailed review artifact.
- If durable state is missing, contradictory, stale, or genuinely ambiguous, stop and report the specific missing/ambiguous state instead of guessing.

### Mandatory `Next:` hint

Every **final user-visible workflow response** from ChatGPT or Codex that has a clear next action must end with exactly one concise copy-ready hint:

`Next: <Target> - <short command>`

When that response also creates or updates a durable GitHub handoff, the issue/PR handoff comment must also end with the same `Next:` hint. The durable handoff and the user-visible final response must route to the same next owner and action.

Do not omit `Next:` merely because the preceding workflow status appears to make the next step obvious. Progress/intermediate updates are exempt; they should not emit a `Next:` hint unless they are also ending the current workflow turn.

Canonical handoff routing is:

- `PLAN_REVIEW_REQUIRED: <TASK-ID>` -> `Next: ChatGPT - Review <TASK-ID>`
- `TECHNICAL_REVIEW_REQUIRED: <TASK-ID>` -> `Next: ChatGPT - Review <TASK-ID>`
- `TECHNICAL_CHANGES_REQUIRED: <TASK-ID>` -> `Next: Codex - Continue <TASK-ID>`
- `READY_FOR_HUMAN_MERGE` -> `Next: Human - Merge PR #<N>`
- `HUMAN_DECISION_REQUIRED: <TASK-ID>` -> `Next: Human - Decide <TASK-ID>`

Other clear transitions use the same format. For example, after an approved Human merge, if the next roadmap task is unambiguous, use `Next: Codex - Continue <NEXT-TASK-ID>`.

Examples:

- `Next: Codex - Continue TM-AUD-0001`
- `Next: ChatGPT - Review TM-AUD-0001`
- `Next: Human - Merge PR #19`
- `Next: Human - Decide TM-AUD-0008`

`Next:` is Human UX only. It is not a workflow status, does not replace the durable GitHub handoff, and must not introduce a new state outside the status protocol below. If there is genuinely no clear next action, no `Next:` line is required.

## Task brief

For **Fast Lane**, use only the minimum useful brief:

- Goal
- Scope
- Acceptance Criteria
- Validation
- Risk: Fast

For **Controlled Lane**, also record the affected invariants, implementation constraints, material risks, and unresolved decisions when any exist.

When product semantics could be ambiguous, the Controlled brief/plan must freeze a compact set of user-visible and edge-case scenarios before implementation. Keep the scenario table in the existing issue/plan; do not create another artifact or workflow status. Each row should identify the scenario, expected behavior, and whether it is in scope so distinctions such as current-disabled versus historical-unregistered cleanup are resolved before code changes.

Controlled plans must also state a proportional complexity budget and stop-loss. Record the expected runtime surfaces and whether DDL, a new lock domain, a new recovery coordinator, or another destructive authority is expected. Stop and return through `PLAN_REVIEW_REQUIRED` or `HUMAN_DECISION_REQUIRED` when implementation unexpectedly requires a new persistent schema/migration, lock/transaction/recovery domain, cross-subsystem destructive authority, material scope expansion, or repeated blockers that make the approved architecture poor value for its cost.

Do not create repository planning documents merely to mirror conversation or pull-request history.

## Task and pull request shape

- Use one task = one draft pull request by default. Use logical commits as rollback and review checkpoints rather than mechanical tranche PRs.
- Split a task only at a real semantic boundary: an independently mergeable schema/migration, a distinct destructive authority, an intentional Human/product decision between parts, a dependency on accepted updated `main`, or a diff too broad for safe semantic review.
- The one-PR default does not authorize mega-PRs. Reviewability, safety, and the complexity stop-loss remain binding.

## Risk lanes

Use only `Fast` and `Controlled`.

### Fast Lane

Use Fast Lane when the change is isolated and cannot materially alter media integrity, destructive execution, persistence contracts, or security boundaries. Typical examples:

- documentation, copy, or translation;
- test-only changes that do not alter runtime behavior;
- CI, tooling, repository contracts, and governance;
- narrow CSS/admin presentation changes;
- small understood bugs outside safety-critical behavior;
- local refactors that preserve public, persistence, and media-safety contracts.

Normal flow:

`Human -> ChatGPT brief -> Codex implementation + validation + local verification -> draft PR/evidence -> ChatGPT lightweight external review -> Human merge gate`

A separate plan-review gate is not required. For very small documentation, test-only, tooling, or governance changes with no runtime or safety-contract impact, the task brief may explicitly waive the ChatGPT external review and use `Codex -> CI -> Human merge`; this is opt-in per task, not the default.

### Controlled Lane

Use Controlled Lane whenever implementation changes or may change any of these behaviors:

- prune candidate discovery or orphan classification;
- file deletion or filesystem path containment;
- original/full-size source protection;
- symlink handling;
- attachment metadata mutation or reconciliation;
- regeneration source selection, generated-file replacement, or obsolete-file cleanup;
- manifest composition, hashing, immutability, review, approval, or delete authorization;
- persistent job schema, migration, ownership, expiry, cursor, resume, or cancellation semantics;
- destructive-operation locking or concurrency;
- AJAX capability, nonce, authorization, or other security boundaries;
- recommendation logic that changes a keep/remove/protected decision;
- behavior that can materially affect a large Media Library;
- release or WordPress.org publishing semantics.

Classification is semantic, not filename-based. A text-only edit inside a safety-critical file can remain Fast; a seemingly small helper change that weakens an invariant is Controlled.

Normal flow:

`Human -> ChatGPT brief -> Codex discovery/plan -> PLAN_REVIEW_REQUIRED -> ChatGPT plan review -> Codex implementation + validation + local verification -> draft PR/evidence -> TECHNICAL_REVIEW_REQUIRED -> ChatGPT external review -> Human merge gate`

Before `TECHNICAL_REVIEW_REQUIRED`, Codex must complete the execution cycle `implement -> tests/runtime evidence -> repo-local adversarial review -> fix findings -> exact-head handoff`. The purpose is to make Codex the primary repo-local technical checker while retaining one external review gate rather than adding another mandatory workflow stage.

At `TECHNICAL_REVIEW_REQUIRED`, ChatGPT reviews the approved plan against the actual diff and evidence, with emphasis on architecture/invariants, safety boundaries, scope drift, compatibility, acceptance criteria, and evidence sufficiency. ChatGPT should not mechanically duplicate Codex's exhaustive repo-local verification or act as a second linter.

If Fast-Lane discovery reveals a Controlled risk, stop before risky runtime implementation and promote the task to Controlled Lane.

### Validation/review profiles

Profiles tune evidence intensity inside the existing Fast/Controlled lanes; they are not a third risk lane and do not add workflow statuses:

- **Structural** — behavior-preserving move, extraction, or refactor. Prove source/body/API equivalence where practical, characterize dependency boundaries, and run targeted tests.
- **Functional** — product behavior changes without destructive or safety-critical authority. Exercise the changed behavior and affected integration/browser boundaries.
- **Safety-critical** — deletion, metadata transaction/reconciliation, persistence/schema, locking/concurrency, authorization/security, crash recovery, or an equivalent irreversible state. Use the strongest relevant integration/runtime evidence and the mandatory fresh independent review rules below.
- **Release** — package, publisher, SVN, tag, or release-control work. Validate the exact package, Plugin Check, and release contracts without crossing the Human publication gate.

Record the selected profile in the PR evidence. Risk-lane classification remains authoritative if a profile and lane appear to conflict.

### Review intensity: fresh independent Codex review

A fresh independent Codex review is a **review intensity**, not a separate workflow stage or status. It runs inside the Codex execution cycle before the external ChatGPT handoff.

A fresh independent Codex review is mandatory for safety-critical Controlled changes that materially touch any of these surfaces:

- file deletion, destructive filesystem work, or path-containment enforcement;
- metadata transactional integrity or destructive metadata reconciliation;
- persistent schema or migration behavior;
- concurrency, locks, worker ownership, cancellation, or replay safety;
- authorization, nonce/capability enforcement, or other security boundaries;
- crash recovery or transactional recovery logic;
- irreversible migration or similarly hard-to-undo state transition.

The independent review must use a fresh context and a separate worktree or clean checkout, bind to the exact PR head, and produce findings that the implementing Codex resolves before posting `TECHNICAL_REVIEW_REQUIRED`. It does not create a new cross-agent status. ChatGPT may require this review during plan review when the change surface warrants it, and the Human may require it at any time.

Other Controlled tasks may use the implementing Codex's own adversarial verification plus strong automated/runtime evidence when a fresh reviewer would add disproportionate overhead.

A pure mechanical Structural change does not require a fresh independent reviewer merely because its source file belongs to a safety-critical subsystem, but only when semantic behavior is explicitly unchanged, exact source/body/API equivalence is mechanically proven where practical, dependency-boundary characterization tests exist, and no lock, persistence, destructive authority, metadata semantics, recovery ordering, authorization, or public/persisted contract changes. If any condition is not met, apply the stronger review intensity.

## Media safety invariants

`docs/media-safety-contract.md` is the durable safety contract. Read it before changing prune, delete, regenerate, media-path, metadata, manifest, or persistent-job behavior.

The following invariants are non-negotiable unless the Human explicitly approves a contract change through Controlled Lane:

1. Original/full-size media sources must not become prune candidates or obsolete generated-file deletions.
2. Destructive file operations must remain bounded to the WordPress uploads area after canonical path validation; symlink escapes must not bypass the boundary.
3. Disk-only candidates that cannot be mapped safely must remain non-destructive by default.
4. Prune deletion must operate only on the reviewed immutable manifest and must require the same persisted manifest hash.
5. Delete approval must remain explicit, authorized, nonce-protected, and short-lived.
6. Destructive `prune` and `regenerate` work must preserve site-level mutual exclusion.
7. Job ownership, cancellation, expiry, and resume state must not be bypassed by late or replayed batches.
8. Attachment metadata changes must be limited to references corresponding to the approved/deleted generated file.
9. Bounded batches and persistent cursors must remain resumable and must not silently restart destructive work from an unsafe state.

## Status protocol

Use only these workflow statuses at cross-agent handoff boundaries:

- `PLAN_REVIEW_REQUIRED`
- `TECHNICAL_REVIEW_REQUIRED`
- `TECHNICAL_CHANGES_REQUIRED`
- `HUMAN_DECISION_REQUIRED`
- `READY_FOR_HUMAN_MERGE`

Do not add more statuses unless a future workflow demonstrates a real need.

## Durable handoffs and review routing

- Fast-Lane implementation evidence belongs in the draft pull request.
- Controlled-Lane plan review requires a durable GitHub issue anchor. Reuse an existing issue; otherwise create one lightweight issue before returning `PLAN_REVIEW_REQUIRED`.
- Codex posts the Controlled plan to that issue before runtime implementation begins.
- ChatGPT posts `PLAN REVIEW: APPROVED` or `PLAN REVIEW: CHANGES REQUIRED` to the same issue.
- Before any technical-review handoff, Codex completes proportional repo-local technical/adversarial verification and records the relevant tests/runtime evidence in the PR. When fresh independent Codex review is required, the PR evidence must identify that exact-head review as completed and summarize any findings/corrections.
- After implementation and local verification, Codex updates the PR body and posts `STATUS: TECHNICAL_REVIEW_REQUIRED` with the current head SHA.
- A commit pushed after that handoff invalidates the review target; Codex must post a new handoff for the new head.
- ChatGPT's technical review is the single external boundary/acceptance review. It focuses on approved-plan/diff alignment, architecture/invariants, safety, scope, compatibility, acceptance criteria, and evidence sufficiency, and results in either `STATUS: TECHNICAL_CHANGES_REQUIRED` or `STATUS: READY_FOR_HUMAN_MERGE`.
- ChatGPT may use a PR review submission for detailed findings, but **every technical-review outcome must also be mirrored as a canonical top-level PR conversation comment**. The top-level handoff must identify the task, reviewed exact head, resulting workflow status, the source review ID when available, and the same `Next:` routing as the user-visible response.
- The canonical top-level PR comment is the actionable cross-agent handoff; a PR review submission is supporting detail and must not be the only place where a workflow state changes.
- `Continue` state recovery must inspect both top-level PR conversation comments and PR review submissions. If a newer applicable review submission carries a workflow status that has not yet been mirrored, treat that review as the newest state rather than allowing an older top-level handoff to mask it. If records are genuinely contradictory for the same/newer exact head, report the ambiguity instead of claiming there is no action.
- `Review` means recover the newest applicable durable handoff and route to plan review, technical review, correction re-review, or Human decision as appropriate.
- Keep durable PR evidence compact: goal/scope, binding plan or decision, semantic changes, affected invariants, a PASS/NOT RUN evidence table, blocking findings and corrections, and the release boundary. Put detailed command logs in CI/runtime artifacts or linked evidence when practical.

For re-review after corrections, inspect the delta from the previously reviewed SHA first, verify the original blockers and affected invariants, then spot-check the final PR state. Do not repeat a full review from zero unless the change surface materially expanded.

## Review severity

Only blocking findings should create another correction cycle. Blocking findings include correctness, safety, security, regression, acceptance failure, broken required CI, or contract violation.

Naming preferences, optional cleanup, and future refactors are advisory. Advisory-only findings do not prevent `READY_FOR_HUMAN_MERGE`.

## Validation routing

Validation must be proportional to the change:

- documentation/governance: repository contracts and diff hygiene;
- structural PHP move: source/API equivalence, dependency-boundary characterization, and targeted PHPUnit;
- PHP/runtime changes: coding standards plus the supported integration matrix;
- Jobs persistence: storage, resume, claim, expiry, and replay tests;
- locking/concurrency: cross-process contention and stale-owner evidence;
- prune/delete: media-safety, immutable-manifest, containment, and recovery evidence;
- Force regeneration: metadata/file transaction and real editor evidence where relevant;
- AJAX/security: capability, nonce, authorization, and transport evidence;
- JavaScript orchestration: Node contracts plus the changed browser flow;
- UI/CSS: targeted visual/manual evidence only for the changed surface;
- release: repository/release contracts, exact package validation, Plugin Check, and relevant safety smoke tests.

Do not claim a check passed if it did not run. Record unavailable checks as `NOT RUN — reason`.

A green CI run proves only the checks CI actually executes; it does not replace targeted runtime evidence when the changed behavior requires it.

## Pull requests and CI

- Keep the PR body current with goal, scope, risk lane, changes, validation, runtime evidence/limitations, and release boundary.
- Draft pull-request heads use **Iteration CI**. It always runs repository contracts and diff hygiene, plus applicable PHP syntax/PHPCS/PHPCompatibility, JavaScript contracts, targeted tests selected by Codex, and one representative WordPress/PHP integration environment for runtime PHP changes. Plugin Check and the full support-boundary matrix may be deferred.
- Non-draft pull-request heads, `main` pushes, and manual CI dispatches use **Final CI**. Before `TECHNICAL_REVIEW_REQUIRED`, convert the PR to ready-for-review and wait for the exact head's applicable Final CI, including all four supported WordPress/PHP boundary jobs, Plugin Check, coding standards, JavaScript contracts, repository contracts, and aggregate `CI Gate`.
- A correction pushed after technical review invalidates the target and must again pass applicable Final CI on the new exact head before re-handoff. Converting the PR back to draft is allowed for iteration, but no draft result is merge-ready.
- The stable required branch-protection signal is the aggregate `CI Gate`; Iteration CI emits `Iteration Gate` instead, so cheaper draft validation cannot satisfy branch protection. Individual conditional jobs may be skipped only when the classifier marks them irrelevant.
- CI classifiers and `CI Gate` must fail closed: a required job that fails, is cancelled, or is skipped cannot be treated as merge-ready.
- Cache only immutable dependency downloads keyed by their lockfile/runtime identity. Do not cache mutable WordPress branch sources or generated state in a way that can hide upstream drift.
- Do not bypass a failed required gate. Diagnose whether the failure is product code, tests, tooling, or infrastructure.
- Compatibility canary failures are future-compatibility signals and are not merge approval by themselves.

## Release boundary

- Normal feature/fix PRs do not publish to WordPress.org and do not create release tags.
- Keep version metadata unchanged unless version/release work is explicitly in scope.
- Release preparation and publishing are separate Human-gated actions.
- Do not delete release branches, create tags, publish to WordPress.org, or deploy production code unless explicitly requested.
