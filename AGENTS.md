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

- **Human** owns product direction, unresolved product/scope/safety trade-offs, merge approval, release/version decisions, production actions, and WordPress.org publication.
- **Codex** owns task framing, risk classification, technical architecture, planning, implementation, testing, review, corrections, work coordination, and branch/PR maintenance. One lead Codex remains accountable for the complete result and evidence, including delegated work.
- **Subagents** perform bounded discovery, implementation, testing, or independent review delegated by the lead Codex. They report evidence and findings to the lead; they do not replace Human authority or create another approval stage.
- **GitHub** is the durable record for plans, commits, pull requests, CI, review evidence, and Human decisions.

Human can give a goal directly to Codex. Codex resolves technical choices within the authorized scope and continues through validation and PR preparation without an external agent handoff. Ask Human only for missing information that blocks safe progress or a decision reserved to Human.

## Human command shorthand and routing

Keep Human commands short. Resolve `Chạy task này`, `Tiếp tục`, `Review`, and `Sửa tiếp` from the current conversation and durable GitHub state rather than requiring Human to restate known context.

- `Codex - Continue <TASK-ID>` recovers the latest applicable plan, decisions, PR head, CI, and findings, then performs the next Codex-owned action.
- `Codex - Review <TASK-ID>` reviews the current task/PR with the required independence and risk profile, fixes blocking findings within scope, and continues toward the Human gate.
- `Human - ...` identifies a Human-owned product decision, merge, release, or production action.

When a task ID or PR/issue reference is supplied, recover known context from GitHub. Inspect both top-level PR conversation comments and PR review submissions, ordered by time and exact-head applicability. If a review and its top-level mirror represent the same decision, use the top-level comment as the actionable handoff. A newer applicable review must not be hidden by an older handoff. Missing, contradictory, or genuinely ambiguous state must be reported specifically rather than guessed.

Legacy `PLAN_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_REQUIRED`, and `TECHNICAL_CHANGES_REQUIRED` records are historical inputs, not active gates. On continuation, Codex recovers the original scope, plan, decisions, and unresolved findings, then performs the outstanding planning/review/correction work internally under this workflow. Preserve prior Human decisions; never infer approval of a safety-contract change from migration. Revalidate the current head before a new merge-ready handoff.

### Mandatory `Next:` hint

Every final user-visible workflow response with a clear next action must end with exactly one concise copy-ready hint:

`Next: <Target> - <short command>`

A durable issue/PR handoff must end with the same hint as the user-visible response:

- `READY_FOR_HUMAN_MERGE` -> `Next: Human - Merge PR #<N>`
- `HUMAN_DECISION_REQUIRED: <TASK-ID>` -> `Next: Human - Decide <TASK-ID>`
- Unfinished Codex work -> `Next: Codex - Continue <TASK-ID>`

`Next:` is Human UX, not a workflow status. Progress updates do not need a hint. Do not stop autonomous work merely to emit a hint. If there is genuinely no clear next action, no hint is required.

## Task brief

For **Fast Lane**, use only the minimum useful brief:

- Goal
- Scope
- Acceptance Criteria
- Validation
- Risk: Fast

For **Controlled Lane**, also record the affected invariants, implementation constraints, material risks, and unresolved decisions when any exist.

When product semantics could be ambiguous, the Controlled brief/plan must freeze a compact set of user-visible and edge-case scenarios before implementation. Keep the scenario table in the existing issue/plan; do not create another artifact or workflow status. Each row should identify the scenario, expected behavior, and whether it is in scope so distinctions such as current-disabled versus historical-unregistered cleanup are resolved before code changes.

Controlled plans must also state a proportional complexity budget and stop-loss. Record the expected runtime surfaces and whether DDL, a new lock domain, a new recovery coordinator, or another destructive authority is expected. If implementation unexpectedly requires a new persistent schema/migration, lock/transaction/recovery domain, cross-subsystem destructive authority, or repeated blockers make the architecture poor value, stop the affected implementation and revise the plan with proportional independent subagent review before resuming. Use `HUMAN_DECISION_REQUIRED` only when the revision requires a Human-owned product/scope/safety decision. Do not silently expand scope or weaken an invariant.

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

`Human goal -> Codex brief + implementation + validation + proportional review -> PR + applicable Final CI -> Human merge gate`

No separate plan approval or mandatory subagent is required. Use a subagent when a bounded parallel task or independent check materially improves confidence; a small, well-understood change can use the lead Codex's own verification.

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

`Human goal -> Codex discovery + recorded plan -> implementation + tests/runtime evidence + independent review where required + corrections -> PR + applicable Final CI -> Human merge gate`

Codex records the Controlled plan before runtime implementation. Use the existing issue or draft PR; do not create a separate issue solely for plan approval. Resolve technical planning and review internally. Obtain Human decisions before implementing changes to product scope, safety posture, or other Human-owned choices.

For safety-critical changes or a new destructive/persistence/locking architecture, have a fresh subagent challenge the plan's scenarios, invariants, complexity, and validation strategy before implementation. This is a focused technical check, not a cross-agent workflow gate. Record material findings and corrections with the plan. Later implementation review checks the actual diff and evidence, not a repetition of the planning exercise.

Complete `implement -> tests/runtime evidence -> proportional adversarial review -> fix blocking findings -> exact-head Final CI -> Human handoff`. Codex remains responsible for plan/diff alignment, architecture and invariants, safety boundaries, scope, compatibility, acceptance criteria, and evidence sufficiency.

If Fast-Lane discovery reveals a Controlled risk, stop before risky runtime implementation and promote the task to Controlled Lane.

### Validation/review profiles

Profiles tune evidence intensity inside the existing Fast/Controlled lanes; they are not a third risk lane and do not add workflow statuses:

- **Structural** — behavior-preserving move, extraction, or refactor. Prove source/body/API equivalence where practical, characterize dependency boundaries, and run targeted tests.
- **Functional** — product behavior changes without destructive or safety-critical authority. Exercise the changed behavior and affected integration/browser boundaries.
- **Safety-critical** — deletion, metadata transaction/reconciliation, persistence/schema, locking/concurrency, authorization/security, crash recovery, or an equivalent irreversible state. Use the strongest relevant integration/runtime evidence and the mandatory fresh independent review rules below.
- **Release** — package, publisher, SVN, tag, or release-control work. Validate the exact package, Plugin Check, and release contracts without crossing the Human publication gate.

Record the selected profile in the PR evidence. Risk-lane classification remains authoritative if a profile and lane appear to conflict.

### Review intensity: fresh independent Codex review

A fresh independent Codex review is a **review intensity**, not a separate workflow stage or status. It runs inside the Codex execution cycle before the Human merge handoff.

A fresh independent Codex review is mandatory for safety-critical Controlled changes that materially touch any of these surfaces:

- file deletion, destructive filesystem work, or path-containment enforcement;
- metadata transactional integrity or destructive metadata reconciliation;
- persistent schema or migration behavior;
- concurrency, locks, worker ownership, cancellation, or replay safety;
- authorization, nonce/capability enforcement, or other security boundaries;
- crash recovery or transactional recovery logic;
- irreversible migration or similarly hard-to-undo state transition.

The independent review must use a fresh subagent context and a separate worktree or clean checkout, bind to the exact PR head, and produce findings that the lead Codex resolves before posting `READY_FOR_HUMAN_MERGE`. Give the reviewer the task, plan, invariants, diff, and evidence without the implementer's private reasoning. Record the reviewer, reviewed SHA, findings, and corrections in the PR. If the head changes, review the delta and affected invariants against the new head; do not reuse stale approval. If independent review is unavailable, report the missing evidence and do not claim merge readiness. Human may require independent review at any time.

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

## Subagent coordination

- Delegate only bounded work with a clear objective, scope, inputs, and expected evidence. Use parallel agents for independent work when it reduces effort or risk; do not require a fixed roster or agent count.
- Lead Codex owns integration and resolves conflicting recommendations using repository evidence. Subagents do not approve their own implementation as independent review.
- Concurrent implementation tasks use separate worktrees or clean checkouts. Avoid overlapping file ownership; integrate changes deliberately and validate the final head.
- Separate plan critique from implementation verification. Re-review corrections as a delta unless the scope materially expands. Do not add serial review rounds for advisory-only findings.

### Autonomous model and effort selection

- Human authorizes the lead Codex to proactively spawn and assign subagents using **GPT-6.1 Sol** (`gpt-6.1-sol`) or **GPT-6 Astra** (`gpt-6-astra`), and to choose or revise each subtask's model and supported reasoning effort without asking for approval. This authority covers subagents; it does not require changing the main agent's model.
- Choose per work item using observed complexity, ambiguity, risk, context needs, evidence quality, and progress. Prefer Sol for well-scoped discovery, implementation, and routine verification; consider Astra for difficult architecture, ambiguous cross-subsystem problems, persistent blockers, or demanding independent review. These are defaults, not mandatory model assignments by role.
- Use low/medium effort for straightforward bounded work and high or higher supported effort when deeper reasoning is justified. Increase or decrease model/effort as evidence changes; do not default every subtask to the highest setting or create extra agents for trivial work.
- Apply model/effort changes through the available agent controls. If an active agent cannot be reconfigured, stop or finish its bounded work and reassign the remaining work with the chosen settings. Preserve relevant findings and avoid concurrent duplicate implementation; never claim an unavailable runtime switch occurred.
- When explicit model/effort overrides require a fresh context (such as `fork_turns="none"`), provide the objective, scope, repository/worktree, binding decisions, and expected evidence explicitly. Independent review must still meet the fresh-context, separate-checkout, and exact-head requirements above.
- Model/effort choice does not change scope, safety invariants, validation obligations, or Human authority. Record material delegation changes only when they explain the result, a blocker, or evidence limitations; no additional approval gate or per-agent reporting ceremony is required.

## Status protocol

Use only these statuses when handing work back to Human:

- `HUMAN_DECISION_REQUIRED`
- `READY_FOR_HUMAN_MERGE`

Planning, implementation, testing, internal findings, and CI progress belong in normal PR evidence; they do not require workflow statuses or Human relays between agents. Missing evidence or failed CI means the work is unfinished, not merge-ready.

## Durable handoffs and review routing

- The PR is the primary execution record. Reuse an existing task issue when useful; a separate issue or planning document is not mandatory.
- Record the brief, Controlled plan when applicable, binding Human decisions, semantic changes, affected invariants, a PASS/NOT RUN evidence table, blocking findings and corrections, and the release boundary. Keep detailed logs in linked artifacts where practical.
- Before a merge-ready handoff, complete required local verification, independent review where required, and applicable Final CI on the current exact head. Update the PR body and post one canonical top-level comment with `STATUS: READY_FOR_HUMAN_MERGE`, task/PR identity, head SHA, evidence, and `Next: Human - Merge PR #<N>`.
- For a blocking Human-owned decision, post `STATUS: HUMAN_DECISION_REQUIRED` to the existing issue or PR with the concrete decision, options, recommendation, and matching `Next: Human - Decide <TASK-ID>`. Continue independent authorized work where possible.
- A commit after a merge-ready handoff invalidates that handoff. Codex completes affected checks/review and applicable Final CI, then posts a new exact-head handoff. Do not merge based on stale evidence.
- Subagent findings and optional GitHub review submissions are supporting evidence. The lead resolves blockers and produces the single Human handoff; do not duplicate every internal review as a workflow transition.

For corrections, inspect the delta from the previously reviewed SHA first, verify the original blockers and affected invariants, then spot-check the final PR state. Do not repeat a full review from zero unless the change surface materially expanded.

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
- Non-draft pull-request heads, `main` pushes, and manual CI dispatches use **Final CI**. Before `READY_FOR_HUMAN_MERGE`, convert the PR to ready-for-review and wait for the exact head's applicable Final CI, including all four supported WordPress/PHP boundary jobs, Plugin Check, coding standards, JavaScript contracts, repository contracts, and aggregate `CI Gate`.
- A correction pushed after technical review invalidates the target and must again pass applicable Final CI on the new exact head before re-handoff. Converting the PR back to draft is allowed for iteration, but no draft result is merge-ready.
- The stable required branch-protection signal is the aggregate `CI Gate`; Iteration CI emits `Iteration Gate` instead, so cheaper draft validation cannot satisfy branch protection. Individual conditional jobs may be skipped only when the classifier marks them irrelevant.
- CI classifiers and `CI Gate` must fail closed: a required job that fails, is cancelled, or is skipped cannot be treated as merge-ready.
- A Final CI change to the CI control plane (`.github/workflows/ci.yml`, its classifier, or its aggregate-gate helper) must self-test the complete validation graph: quality, all supported WordPress/PHP boundary jobs, Plugin Check, JavaScript contracts, repository contracts, and `CI Gate`. Ordinary governance-only files do not trigger this self-test.
- Cache only immutable dependency downloads keyed by their lockfile/runtime identity. Do not cache mutable WordPress branch sources or generated state in a way that can hide upstream drift.
- Do not bypass a failed required gate. Diagnose whether the failure is product code, tests, tooling, or infrastructure.
- Compatibility canary failures are future-compatibility signals and are not merge approval by themselves.

## Release boundary

- Normal feature/fix PRs do not publish to WordPress.org and do not create release tags.
- Keep version metadata unchanged unless version/release work is explicitly in scope.
- Release preparation and publishing are separate Human-gated actions.
- Do not delete release branches, create tags, publish to WordPress.org, or deploy production code unless explicitly requested.
