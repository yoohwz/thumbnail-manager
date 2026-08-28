# Thumbnail Manager Media Safety Contract

This document defines the durable safety boundary for destructive and stateful media operations. It is a contract for implementation and review, not a user-facing feature specification.

## Scope

The contract applies to:

- prune candidate discovery and orphan classification;
- file deletion and filesystem path validation;
- attachment metadata reconciliation;
- regeneration source selection and obsolete generated-file cleanup;
- persistent jobs, manifests, approval, cancellation, expiry, and resume;
- destructive-operation locking and concurrency;
- AJAX authorization around destructive operations.

Changes that alter these semantics use Controlled Lane.

## 1. Source-media protection

The original/full-size source for an attachment must not be queued as a prune candidate or treated as an obsolete generated file merely because its filename matches a thumbnail-like pattern.

Regeneration cleanup may remove generated files that are no longer referenced by the new metadata, but it must preserve:

- the source used to regenerate;
- the current full-size attachment file;
- files still referenced by the resulting attachment metadata.

Filename heuristics alone are never sufficient evidence that a file is safe to delete.

Known Core image companions are protected references, including `original_image`, `source_image`, legacy `thumb`, `animated_video`, `animated_video_poster`, and `_wp_attachment_backup_sizes[*].file`. `source_image` is protect-only and is not automatically selected as a regeneration input. Force regeneration selects a valid `original_image` when declared; otherwise it uses the current full image.

Force regeneration replaces sub-sizes transactionally rather than replaying the initial-upload pipeline against live files. It generates into same-filesystem private staging, evaluates the two metadata update filter surfaces once, validates the exact final destination map, journals promotion before live mutation, persists the exact filtered metadata, and performs exact obsolete cleanup only after commit. An existing unreferenced destination is disk-only content and must never be overwritten; only an absent unreferenced destination or a path owned exclusively by the attachment's exact old generated-size evidence is replaceable.

## 2. Filesystem containment

A destructive candidate must resolve inside the current WordPress uploads base before deletion.

The boundary must account for canonical filesystem paths. A symlink, traversal, alternate separator, or equivalent representation must not allow a candidate that resolves outside uploads to pass containment checks.

If containment cannot be established confidently, deletion fails closed.

## 3. Metadata-backed versus disk-only candidates

Metadata-backed generated files may be considered for deletion when the corresponding attachment/size relationship is established and all other safety checks pass.

Disk-only files discovered by thumbnail-style filename patterns are informational by default when they cannot be mapped safely to attachment metadata. They must not silently become destructive candidates because of a broader scan, refactor, or recommendation change.

Any future design that makes currently unmapped disk-only files deletable is a product/safety contract change and requires explicit Human approval through Controlled Lane.

## 4. Review-first prune lifecycle

The prune lifecycle remains separated into discovery and deletion:

`scan -> manifest -> review -> explicit approval -> bounded deletion`

The item set must become immutable before review. Late scan batches or other requests must not append or mutate candidate payloads after manifest finalization begins.

The persisted manifest hash identifies the reviewed candidate set. Approval and deletion must require the same hash; a missing or mismatched hash fails closed and requires a new review cycle.

Approval does not authorize an arbitrary future delete. The delete grant is short-lived and bound to the persisted job state.

## 5. Authorization

Destructive AJAX entry points require all applicable controls:

- an administrator-level capability appropriate to the existing plugin contract;
- a valid nonce for the operation;
- a job owned by the current user/site where ownership is part of the job contract;
- an allowed job type/status/phase;
- the reviewed manifest identity for prune deletion.

A browser-provided token or manifest hash is an identifier, not authorization by itself.

## 6. Persistent job ownership and state

Job state is authoritative on the server. Browser state must not be able to advance a job through an invalid transition.

Active jobs remain scoped to the site and creating user where the current contract requires that ownership.

Cancellation must prevent future batches from resuming destructive work. Late requests must not overwrite a cancelled terminal state with running/completed state.

Expired approval or active-state windows fail closed. Resume logic may continue a valid persisted state but must not recreate authorization that has expired.

## 7. Destructive-operation concurrency

`prune` and `regenerate` are mutually exclusive destructive maintenance classes for a site. Starting one must not allow another active destructive job to operate concurrently on the same media library.

Locking must be race-resistant at job creation. A UI-only check is insufficient; the authoritative lock must be enforced server-side.

Finishing, cancelling, or expiring a job must eventually release the logical destructive-operation lock without allowing overlapping execution during the transition.

## 8. Metadata reconciliation

When a generated file is successfully removed, attachment metadata may be updated only for the references represented by that approved candidate.

If the file is already absent, reconciliation may remove a stale matching metadata reference when the candidate contains enough evidence to identify it safely.

A deletion failure must not cause broad metadata cleanup unrelated to the exact candidate.

The site-wide reverse-reference index has a versioned semantic generation and completeness marker. Destructive reference decisions require a complete current generation and an empty mutation-dirty set. Source/companion reference kinds are blanket vetoes. Raw `sizes[*].file` entries are generated-owner tuples and are validated against exact candidate or regeneration evidence rather than treated as source vetoes. Here, raw authority means exact uncached rows read directly from the postmeta table with preserved row cardinality; short-circuitable metadata accessors cannot mint or hide destructive ownership or prove a Force metadata commit. Filtered aliases may add conservative source/protected vetoes only.

Writes and deletes of `_wp_attached_file`, `_wp_attachment_metadata`, and `_wp_attachment_backup_sizes` must remain fenced through regular and by-meta-ID mutation paths. Unknown/malformed rows, stale index/live disagreement, unsupported filtered by-meta-ID accessors, or an incomplete generation fail closed.

## 9. Bounded and resumable execution

Large scans, manifest construction, regeneration, and deletion must remain bounded. A single request must not assume it can process an unbounded Media Library safely within one PHP request.

Persistent cursors/queues must allow page reloads or network interruption without restarting destructive work from an ambiguous point.

Batch retry must be idempotent or otherwise protected from repeating already-completed destructive work.

## 10. Recommendation boundary

Recommendation scans are advisory and must not become implicit prune or delete authorization.

Missing generated metadata or content-reference matches is an inconclusive observation, not proof that a registered size is unused. Dynamic theme/plugin calls, custom fields, external consumers, and other runtime paths may not be visible to a bounded scan.

Unknown or uncertain usage must preserve the Human's current enabled/disabled setting. Applying recommendations may enable a size supported by positive keep/protect evidence, but must not silently disable a size because evidence is absent, legacy, malformed, or stale.

Persisted recommendation results must identify their schema and registered-size snapshot. Browser-facing legacy compatibility data must be projected against the currently registered set so an old client cannot disable a size introduced after the scan. Recommendation output never creates, approves, or mutates a prune manifest.

## 11. Failure behavior

Safety-sensitive ambiguity fails closed. Examples include:

- unresolved/outside path;
- symlink escape;
- wrong user/site job;
- invalid status or phase;
- changed manifest;
- expired approval;
- missing required source metadata;
- inability to acquire the destructive-operation lock.

Partial failures should be recorded per item when possible and must not erase the audit state of successful/failed/skipped items.

## Required regression evidence

A Controlled change touching this contract must preserve or deliberately update automated coverage for the affected invariants. Existing high-value regression areas include:

- outside-upload and symlink rejection;
- original/full-size preservation;
- exact thumbnail metadata reconciliation;
- regeneration cleanup preserving current/source files;
- transactional Force staging, exact filtered payload commit, promotion rollback/recovery, and existing-unreferenced destination preservation;
- current-generation reverse-reference completeness, known companion protection, and cross-attachment generated ownership;
- job ownership;
- destructive-job locking;
- manifest immutability and hash matching;
- cancellation/audit retention;
- resumable deletion and persisted cursors.
- recommendation decision tables, stale/legacy compatibility projection, and conservative Apply behavior.

Use targeted runtime smoke evidence when the change affects actual filesystem/AJAX orchestration in a way PHPUnit does not adequately represent.

## Contract changes

Do not weaken this document incidentally during a feature or refactor. A deliberate change to a safety invariant requires:

1. Controlled-Lane task framing;
2. explicit identification of the invariant being changed;
3. ChatGPT plan review;
4. updated regression evidence;
5. Human decision when the change alters the product's safety posture or user-visible deletion guarantees.
