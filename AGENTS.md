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
- **ChatGPT** owns task framing, risk classification, architecture/product decisions, plan review for Controlled work, and independent technical review of the actual pull-request state.
- **Codex** owns repository discovery, implementation, tests, validation, commits, branch/PR maintenance, and correction of review findings.
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
- If durable state is missing, contradictory, stale, or genuinely ambiguous, stop and report the specific missing/ambiguous state instead of guessing.

At the end of a handoff/review response, include one concise copy-ready next-step hint whenever there is a clear next action:

`Next: <Target> - <short command>`

Examples:

- `Next: Codex - Continue TM-AUD-0001`
- `Next: ChatGPT - Review TM-AUD-0001`
- `Next: Human - Merge PR #19`
- `Next: Human - Decide TM-AUD-0008`

`Next:` is Human UX only. It is not a workflow status, does not replace the durable GitHub handoff, and must not introduce a new state outside the status protocol below.

## Task brief

For **Fast Lane**, use only the minimum useful brief:

- Goal
- Scope
- Acceptance Criteria
- Validation
- Risk: Fast

For **Controlled Lane**, also record the affected invariants, implementation constraints, material risks, and unresolved decisions when any exist.

Do not create repository planning documents merely to mirror conversation or pull-request history.

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

`Human -> ChatGPT brief -> Codex implementation -> draft PR/evidence -> ChatGPT technical review -> Human merge gate`

A separate plan-review gate is not required.

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

`Human -> ChatGPT brief -> Codex discovery/plan -> PLAN_REVIEW_REQUIRED -> ChatGPT plan review -> Codex implementation -> draft PR/evidence -> TECHNICAL_REVIEW_REQUIRED -> ChatGPT technical review -> Human merge gate`

If Fast-Lane discovery reveals a Controlled risk, stop before risky runtime implementation and promote the task to Controlled Lane.

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
- After implementation, Codex updates the PR body and posts `STATUS: TECHNICAL_REVIEW_REQUIRED` with the current head SHA.
- A commit pushed after that handoff invalidates the review target; Codex must post a new handoff for the new head.
- ChatGPT posts either `STATUS: TECHNICAL_CHANGES_REQUIRED` or `STATUS: READY_FOR_HUMAN_MERGE` to the same PR conversation.
- `Review` means recover the newest applicable durable handoff and route to plan review, technical review, correction re-review, or Human decision as appropriate.

For re-review after corrections, inspect the delta from the previously reviewed SHA first, verify the original blockers and affected invariants, then spot-check the final PR state. Do not repeat a full review from zero unless the change surface materially expanded.

## Review severity

Only blocking findings should create another correction cycle. Blocking findings include correctness, safety, security, regression, acceptance failure, broken required CI, or contract violation.

Naming preferences, optional cleanup, and future refactors are advisory. Advisory-only findings do not prevent `READY_FOR_HUMAN_MERGE`.

## Validation routing

Validation must be proportional to the change:

- documentation/governance: repository contracts and diff hygiene;
- PHP/runtime changes: coding standards plus the supported integration matrix;
- filesystem/destructive changes: integration tests plus targeted media-safety smoke evidence when automated tests alone do not exercise the runtime boundary adequately;
- AJAX/admin orchestration: targeted WordPress admin/runtime evidence;
- UI/CSS: targeted visual/manual evidence only for the changed surface;
- release candidate: full repository contracts, Plugin Check, relevant safety smoke tests, and exact package validation.

Do not claim a check passed if it did not run. Record unavailable checks as `NOT RUN — reason`.

A green CI run proves only the checks CI actually executes; it does not replace targeted runtime evidence when the changed behavior requires it.

## Pull requests and CI

- Keep the PR body current with goal, scope, risk lane, changes, validation, runtime evidence/limitations, and release boundary.
- The stable required branch-protection signal is the aggregate `CI Gate`; individual conditional jobs may be skipped when they are irrelevant to the diff.
- Do not bypass a failed required gate. Diagnose whether the failure is product code, tests, tooling, or infrastructure.
- Compatibility canary failures are future-compatibility signals and are not merge approval by themselves.

## Release boundary

- Normal feature/fix PRs do not publish to WordPress.org and do not create release tags.
- Keep version metadata unchanged unless version/release work is explicitly in scope.
- Release preparation and publishing are separate Human-gated actions.
- Do not delete release branches, create tags, publish to WordPress.org, or deploy production code unless explicitly requested.
