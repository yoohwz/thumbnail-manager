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

Disk-only files discovered by thumbnail-style filename patterns are informational by default when they cannot be mapped safely to one authoritative attachment family. They must not silently become destructive candidates because of a broader scan, refactor, or recommendation change.

The sole reviewed exception is a `legacy_generated_v1` item produced under an explicit, versioned job policy. It must prove all of the following from raw authoritative state at scan, manifest, and delete time:

- the candidate is a regular non-symlink file inside uploads and its decoded dimensions and MIME agree exactly with its filename extension and strict terminal `-WxH` suffix;
- exactly one authoritative attachment family owns the current generation source stem and directory, with no source ambiguity or index dirtiness;
- the observed dimensions are projected by at least one currently registered explicitly removed size and by no kept size; a kept-size match is an unconditional veto;
- no metadata-generated owner or protected/source reference owns the candidate path;
- folder-scoped jobs remain bound to the frozen authoritative attached-file meta ID; and
- immutable source/candidate hashes and node fingerprints still match.

Legacy authority is job-local and cannot be inferred for an older job that lacks the exact policy version and policy hash. The job-level compatibility marker may remain `generated_file_v1`; item-level `ownership_schema` is the authority discriminator. A legacy item never authorizes attachment-metadata reconciliation, including when its file is already absent.

Any broader design that makes other unmapped disk-only files deletable is a product/safety contract change and requires explicit Human approval through Controlled Lane.

## 4. Review-first prune lifecycle

The prune lifecycle remains separated into discovery and deletion:

`scan -> manifest -> review -> explicit approval -> bounded deletion`

The item set must become immutable before review. Late scan batches or other requests must not append or mutate candidate payloads after manifest finalization begins.

The disabled-size cleanup bridge may enable legacy discovery and start the scan, but it must stop at this immutable review boundary. It must never approve a manifest or begin deletion automatically.

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

`legacy_generated_v1` is intentionally disk-only evidence. Its candidate payload must carry no metadata-removal instruction or generated metadata references, and every reconciliation path must reject any legacy payload that attempts to introduce either.

If the file is already absent, reconciliation may remove a stale matching metadata reference when the candidate contains enough evidence to identify it safely.

A deletion failure must not cause broad metadata cleanup unrelated to the exact candidate.

The site-wide reverse-reference index has a versioned semantic generation and completeness marker. Destructive reference decisions require a complete current generation and an empty mutation-dirty set. Source/companion reference kinds are blanket vetoes. Raw `sizes[*].file` entries are generated-owner tuples and are validated against exact candidate or regeneration evidence rather than treated as source vetoes. Here, raw authority means exact uncached rows read directly from the postmeta table with preserved row cardinality; short-circuitable metadata accessors cannot mint or hide destructive ownership or prove a Force metadata commit. Filtered aliases may add conservative source/protected vetoes only.

Writes and deletes of `_wp_attached_file`, `_wp_attachment_metadata`, and `_wp_attachment_backup_sizes` must remain fenced through regular and by-meta-ID mutation paths. Unknown/malformed rows, stale index/live disagreement, unsupported filtered by-meta-ID accessors, or an incomplete generation fail closed.

## 9. Bounded and resumable execution

Large scans, manifest construction, regeneration, and deletion must remain bounded. A single request must not assume it can process an unbounded Media Library safely within one PHP request.

Persistent cursors/queues must allow page reloads or network interruption without restarting destructive work from an ambiguous point.

Batch retry must be idempotent or otherwise protected from repeating already-completed destructive work.

Folder-scoped `attached_meta_v2` jobs freeze the maximum authoritative `_wp_attached_file.meta_id` at prepare time and scan that range by raw meta-ID keyset. A materialized regeneration item remains bound to the exact selected meta ID. A deleted/reinserted row, duplicate row, malformed value, database ambiguity, or raw path outside the literal selected subpath fails closed even if a filterable accessor reports an in-scope path. During selection the exact attachment total is unknown; API/UI state must distinguish that condition from a completed zero-result scan.

`item_v3` is limited to media operations with persisted recovery evidence. Prune arms an exact regular-file path/hash/byte/node-fingerprint journal before unlink and reconciles an armed path idempotently only when a node-aware inspection proves that the path is truly absent. Any still-present regular file must match the armed node fingerprint as well as its hash and byte count before a new unlink; missing or unprovable node identity fails closed. A directory, symlink (including a broken symlink), other non-regular node, or changed/replaced regular file at the armed path fails closed without metadata reconciliation. Force regeneration retains its transactional promotion journal. Their terminal item row and job counters advance together under the exact worker and claim generation. Normal and missing-only Core regeneration remain `item_v2` and retain their existing at-least-once retry contract.

Cancellation or expiry must not make an armed journal look safely terminal, including after a retry has requeued the item. The requested terminal intent remains persisted and authoritative while bounded recovery finishes. An ambiguous in-flight journal remains recovery-only/resumable; recovery may reconcile an already-achieved postcondition or roll back an existing Force transaction, but it must not use expiry or cancellation recovery to authorize a new delete or promotion. If the armed prune prestate regular file is still intact, recovery-only processing preserves it and terminates that item fail-closed rather than beginning the previously authorized unlink.

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

## 12. Deactivation and plugin deletion

Deactivation and plugin-file deletion do not authorize media or recovery cleanup. Thumbnail Manager retains its persistent database state when plugin files are deleted, including job/item recovery evidence and the derived media-source index.

Attachment rows and postmeta, original uploads, generated thumbnails, and `.yotm-regenerate-*` recovery paths remain untouched. The staging paths are plugin-created recovery state, but their ownership does not make them uninstall-delete candidates.

Network deactivation may clear only the `yotm_cleanup_jobs` scheduled hook for existing sites through bounded, best-effort iteration. It must not introduce lifecycle locks or change the runtime locking, recovery, or media-deletion contracts above.

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
- strict legacy filename/dimension/MIME proof, kept-size veto, one-family source ownership, policy/hash compatibility, and zero metadata reconciliation;
- scaled/original source-basename divergence and folder meta-ID replacement rejection for legacy candidates;
- cancellation/audit retention;
- resumable deletion and persisted cursors.
- raw meta-ID folder snapshots, execution-time scope drift, and delete/reinsert replacement rejection;
- prune/Force crash recovery between media side effects and item/job finalization;
- recommendation decision tables, stale/legacy compatibility projection, and conservative Apply behavior.

Use targeted runtime smoke evidence when the change affects actual filesystem/AJAX orchestration in a way PHPUnit does not adequately represent.

## Contract changes

Do not weaken this document incidentally during a feature or refactor. A deliberate change to a safety invariant requires:

1. Controlled-Lane task framing;
2. explicit identification of the invariant being changed;
3. ChatGPT plan review;
4. updated regression evidence;
5. Human decision when the change alters the product's safety posture or user-visible deletion guarantees.
