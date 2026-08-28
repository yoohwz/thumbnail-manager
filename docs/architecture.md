# Thumbnail Manager Architecture Contract

This document defines the smallest useful target architecture for incremental extraction after the hardened `1.4.0` baseline. It assigns responsibility and dependency direction; it does not require a framework, a class hierarchy, or a big-bang rewrite.

`docs/media-safety-contract.md` remains the authority for destructive and stateful media behavior. If this document appears to conflict with that contract, the media-safety contract wins.

## Goals and non-goals

The architecture must:

- give persistent Jobs and media-safety behavior one clear owner each;
- keep Prune, Regenerate, Recommendations, and Size Management understandable as application use cases;
- let WordPress/AJAX/admin/JavaScript code adapt requests and responses without owning business decisions;
- preserve existing `yotm_*` functions, hooks, AJAX actions, persisted records, and user-visible behavior while implementation moves incrementally;
- reduce duplicated and cross-cutting logic without replacing it with more abstraction than the plugin needs.

It does not prescribe Composer autoloading, namespaces, a service container, an event bus, a repository layer, a dependency-injection framework, a domain framework, or a SPA. Those require a separate concrete justification and review.

## Current hardened shape

The current procedural layout contains stable behavior but places several responsibilities in the same files:

| Current area | Responsibilities and coupling |
| --- | --- |
| `thumbnail-manager.php` | Loads every module in dependency order and registers activation/deactivation. Include order currently acts as dependency wiring. |
| `inc/job-storage.php` | Owns schema readiness, job/item persistence, ownership, workers, claims, counters, transitions, cancellation, expiry, cleanup, public projections, four AJAX endpoints, and the table-name/error helpers also used by the media-source index. |
| `inc/helper.php`, `inc/upload-subpaths.php`, `inc/sizes-patterns.php` | Mix reusable uploads/path and attachment-query primitives with WordPress query details and registered-size discovery. |
| `inc/media-source-index.php` | Owns authoritative source evidence, raw metadata access, source-index state, metadata mutation fences, and attachment/path/source locks, while directly using `$wpdb` and storage helpers. |
| `inc/regenerate-transaction.php` | Owns Force regeneration staging, promotion, rollback, recovery, and obsolete-file cleanup. Its crash-safety ordering currently calls both Media primitives and Jobs journal/claim functions. |
| `inc/handle-prune.php`, `inc/handle-delete.php`, `inc/handle-regenerate.php`, `inc/handle-recommendations.php` | Combine AJAX authorization and input parsing with application sequencing, job transitions, Media decisions, batching, and response projection. |
| `inc/admin-menu.php` | Combines asset/localization setup, settings form handling, WordPress admin rendering, and initial application state. |
| `js/admin.js` | Combines HTTP transport, browser token persistence, resume/cancel loops, use-case workflow sequencing, DOM rendering, and accessibility state. |
| `inc/filter-disabled-sizes.php` | Is a small WordPress hook adapter for the saved Size Management decision. |

This coupling map is a migration input, not an instruction to split every file. A file moves or splits only when doing so establishes an owned boundary used by current behavior or a concrete next task.

## Target boundaries

### Jobs

**Problem solved:** Prune, Regenerate, and Recommendations currently share a hardened persistent execution engine, but feature handlers can reach its storage and transition details directly.

Jobs owns:

- job and item schema, installation/readiness, storage and serialization;
- site/user ownership, job type identity, status/phase transitions, and conditional updates;
- worker leases and generations, item claims, retry/recovery eligibility, counters, cursors, progress, cancellation, expiry, resume, and cleanup;
- site-level destructive-job mutual exclusion;
- generic immutable-item/manifest identity mechanics and short-lived persisted deadlines;
- public job/item projections that do not contain use-case policy.

Jobs treats feature payloads and recovery journals as opaque versioned data except for generic mechanics explicitly required to claim, resume, cancel, expire, or retain recovery-bearing items safely. It must not decide which media file is safe, which image source is authoritative, or which size should be recommended.

### Media

**Problem solved:** uploads containment, authoritative metadata/source evidence, file ownership, metadata reconciliation, and regeneration safety are spread across helpers, handlers, the source index, and the transaction module.

Media owns:

- WordPress attachment and registered-image-size semantics;
- uploads path normalization, canonical containment, subpath scope, and node/symlink safety;
- authoritative raw attachment metadata/source evidence and the media-source index;
- attachment, source-fence, and file-path locking required by media mutation;
- generated-file ownership/protection decisions and exact metadata reconciliation;
- safe file inspection/deletion primitives;
- regeneration source selection, staging, promotion, rollback, recovery, and obsolete-file cleanup;
- every destructive/media invariant in `docs/media-safety-contract.md`.

Media does not authorize a request, own a browser workflow, or decide the overall Prune/Regenerate/Recommendations sequence. The `yotm_media_sources` table is logically Media-owned even while schema installation remains temporarily colocated with Jobs during migration.

### Application use cases

**Problem solved:** each `handle-*` file currently mixes transport with the workflow it invokes, making business sequencing difficult to reuse outside AJAX.

Application owns the orchestration and policy for:

- **Prune:** prepare scope, discover candidates, build/finalize the manifest, review/approval progression, and bounded delete processing;
- **Regenerate:** prepare selection, choose normal/missing/Force flow, coordinate source indexing, queueing, bounded work, and terminal results;
- **Recommendations:** scan phases, conservative classification/evidence, result compatibility, and Apply rules;
- **Size Management:** validate enabled/disabled size choices, decide when the validated setting change must be persisted, and coordinate any explicit follow-on regeneration request. Concrete WordPress option storage belongs to the narrow outbound WordPress/options seam when that seam is extracted.

Application coordinates Jobs and Media through their owned capabilities. It defines use-case-valid sequences but does not bypass Jobs transitions or reimplement Media safety checks. It returns transport-neutral results/errors; it does not read `$_POST`, call `wp_send_json_*()`, render HTML, or manipulate the browser.

### Admin, AJAX, hook, and JavaScript adapters

**Problem solved:** inbound transport and presentation code currently contains state-machine and media decisions that must remain server-authoritative and reusable.

Adapters own:

- WordPress hook and AJAX action registration;
- capability and nonce checks, request parsing/sanitization, input/output status mapping, and JSON transport;
- admin asset/localization setup, page rendering, authenticated form request mapping, notices, and accessibility markup;
- browser HTTP calls, DOM rendering, progress display, focus/live-region behavior, local token hints, and retry/resume presentation;
- legacy compatibility facades that preserve current public procedural entry points.

Browser state is advisory. Adapters must not grant approval, infer ownership, validate a destructive manifest, choose a media source, or advance persisted job state without the server-side Application/Jobs/Media checks.

### Infrastructure and WordPress adapters

**Problem solved:** direct `$wpdb`, WordPress metadata/query/image APIs, filesystem calls, and named locks make safety-sensitive code harder to test when a real seam is needed.

Infrastructure is the narrow outbound edge for concrete integration with:

- `$wpdb`, schema installation, transactions where supported, and named locks;
- WordPress attachment/postmeta/query/options/cron APIs;
- uploads/filesystem/node inspection and the WordPress image editor;
- clock/UUID facilities only when deterministic tests require a seam.

Do not create one generic repository or wrapper for every WordPress function. Keep a direct call until extraction has a second consumer, duplicated policy, or a proven safety/test seam. Infrastructure contains no use-case policy and never calls Application or presentation adapters.

## Dependency direction

```text
WordPress hooks / admin requests / browser
                    |
        Admin, AJAX, hook, JS adapters
                    |
             Application use cases
               /               \
            Jobs               Media
               \               /
        narrow WordPress/infrastructure seams
```

The rules are:

1. Inbound adapters call Application. Generic job status/history/cancel adapters may call a Jobs application-facing capability directly when no media/use-case decision is involved.
2. Application may call Jobs and Media. It must not call their database, global lock, raw metadata, or filesystem internals.
3. Jobs and Media are sibling owners. Neither may depend on the other's internal storage or implementation.
4. Infrastructure depends only on WordPress/PHP facilities and data passed to it. It does not invoke inward layers.
5. Bootstrap wires modules and hooks; it contains no feature policy.

Crash-safe media operations are the deliberate cross-boundary case. Application coordinates them using a narrow recovery-checkpoint capability owned by Jobs and a transaction capability owned by Media. Persist-journal-before-side-effect ordering, claim freshness, and recovery-only behavior must remain unchanged. Current direct calls may remain behind compatibility facades until a Controlled plan proves a safer extraction; a cosmetic wrapper is not sufficient reason to change this boundary.

### Forbidden coupling

- AJAX or JavaScript directly writing job rows, choosing transitions, or authorizing deletion.
- Application or Jobs constructing paths and deleting/reconciling media without Media validation.
- Media reading `$_POST`, sending JSON, rendering admin HTML, or owning browser tokens.
- Feature-specific candidate, source, recommendation, or UI fields becoming generic Jobs policy.
- Jobs or Media calling another subsystem's private functions/table details instead of an application-facing capability.
- Infrastructure selecting product policy, interpreting a recommendation, or advancing a workflow.
- A new interface, class, or layer whose only effect is to rename one call without creating ownership, reuse, or an already-proven test/safety seam.

## Invariant ownership

Ownership means the invariant cannot depend on every caller remembering to enforce it.

| Invariant | Owner and enforcement |
| --- | --- |
| Job/site/user ownership; status/phase validity; leases, claims and generations; cancellation, expiry, resume and counters | Jobs. Application requests allowed operations; Jobs performs conditional enforcement. |
| Destructive-job mutual exclusion and late/replayed batch rejection | Jobs owns authoritative exclusion and persisted generations; Application selects the destructive job class. |
| Immutable item set, manifest hash persistence, and approval deadline mechanics | Jobs owns atomic persistence/conditions; Prune Application owns the `scan -> manifest -> review -> approval -> delete` protocol. |
| Capability and nonce validation | Server-side AJAX/hook adapter. It is additive to, never a replacement for, Jobs ownership and Application state validation. |
| Original/current/source protection, canonical uploads containment, symlink/node checks, generated-file ownership and metadata reconciliation | Media, governed by `docs/media-safety-contract.md`. |
| Media-source index generation/completeness, mutation dirty state, raw metadata authority, attachment/path/source fences | Media. Application may require readiness but cannot weaken it. |
| Force regeneration journal ordering, promotion/rollback/recovery and obsolete-file cleanup | Media owns the transaction; Jobs owns durable checkpoints/claim freshness; Application coordinates the exact reviewed sequence. |
| Recommendation conservatism and stale/legacy projection | Recommendations Application, using read-only Media evidence and Jobs persistence. |
| Browser resume tokens and progress state | JavaScript adapter only as hints; server Jobs/Application state remains authoritative. |
| Deactivation/plugin-file deletion retention | Existing lifecycle contract: adapters clear only the exact cron; Jobs/Media persistent state is retained. No extraction task may introduce purge behavior implicitly. |

## Compatibility during migration

Incremental extraction keeps compatibility by default:

- Existing `yotm_*` functions remain callable facades until all in-repository callers and explicit compatibility tests authorize removal. A facade delegates to one owner; it does not retain a second business implementation.
- Existing WordPress hook names, priorities, AJAX action names, nonce/capability requirements, request fields, response shapes/statuses, localized data, and browser storage keys remain stable unless a separately reviewed compatibility change is required.
- Existing table names, columns, option names, job/item payload schemas, journal formats, status/phase values, counter modes, manifest hashes, and persisted tokens remain readable. A schema/payload migration is a Controlled decision, not a side effect of moving code.
- Existing filters around image generation and attachment metadata retain timing and fail-closed behavior.
- Characterization and boundary tests are added before or with each move. Compatibility aliases are temporary migration tools and must have an identified removal condition.

## Incremental extraction sequence

Each roadmap task may use several reviewable PRs. Every PR must preserve behavior, keep the repository runnable, and offer a clean rollback to its parent without requiring later PRs.

### TM-AUD-0010 — Jobs first

Bounded objective: establish one owner for generic job persistence/lifecycle behind the current `yotm_job_*` facades.

- Inventory generic versus feature-specific fields and calls before moving them.
- Extract schema/readiness, job/item store, transitions, workers/claims, counters, cancellation/expiry/cleanup, and generic projections in small slices.
- Keep feature handlers and AJAX names stable; move only enough endpoint code to separate generic transport from Jobs behavior.
- Preserve all schemas and serialized contracts by default. Do not move or redesign Media behavior; leave `yotm_media_sources` installation colocated temporarily if separating it would broaden the tranche.
- Stop at a reusable Jobs capability that a hypothetical bounded non-media job can use without copying lifecycle code. Do not create a generic job-handler framework until at least two current handlers require the same dispatch seam.

### TM-AUD-0011 — Media and Application next

Bounded objective: centralize media invariants and separate existing use-case sequencing from transport.

- Extract path/scope, attachment/source evidence, media-source index, file ownership/reconciliation, and regeneration transaction capabilities in safety-focused slices.
- Assign `yotm_media_sources` access to Media while preserving physical schema/version compatibility.
- Move Prune, Regenerate, and Recommendations sequencing into transport-neutral Application capabilities; keep current handlers as facades/adapters.
- Replace Jobs/Media internal cross-calls only through an approved crash-recovery coordination plan with exact ordering and rollback evidence.
- Do not combine file moves with changes to candidate classification, source selection, recommendation decisions, metadata semantics, or deletion authority.

### TM-AUD-0012 — Admin, AJAX, and JavaScript last

Bounded objective: make transport and presentation thin after stable server capabilities exist.

- Reduce AJAX callbacks to authorization, request mapping, one Application/Jobs call, and response mapping.
- Route authenticated/mapped settings requests from the inbound admin adapter to Size Management Application; when extracted, keep concrete `get_option()`/`update_option()` calls in the narrow outbound WordPress/options seam rather than the inbound adapter.
- Split JavaScript by current workflow/resume/rendering responsibilities only when it reduces the present monolith; retain the jQuery/WordPress admin delivery model unless a separate product task changes it.
- Preserve server authority, AJAX/localized/storage contracts, reload/network resume behavior, manifest review/approval, cancellation, and accessibility.

## Stop conditions

Pause extraction and return for plan amendment or Human decision when any of these occurs:

- a proposed move changes a media-safety invariant, product decision, deletion authority, recommendation result, regeneration source, or user-visible workflow;
- persisted schema, payload/journal format, locking, ownership, expiry, cancellation, recovery, or compatibility semantics must change;
- one PR must alter Jobs, Media, transport, and browser behavior together to remain viable;
- a boundary cannot name current duplicated/cross-cutting behavior or a concrete near-term consumer;
- an abstraction has one implementation and one consumer without a proven safety/test seam;
- a compatibility facade becomes a second source of truth rather than a delegate;
- tests cannot bind the before/after behavior or required runtime evidence cannot be produced safely;
- the work requires a broad namespace/autoload migration, framework, container, event bus, or speculative extension model;
- moving files is being used as a substitute for reducing coupling or clarifying ownership.

## Placement examples

These examples test the boundaries; they do not authorize the features.

| Example | Placement |
| --- | --- |
| A read-only, resumable media inventory/export | Application defines the inventory/export use case; Jobs provides bounded progress/resume; Media provides attachment/source evidence; adapters expose admin/AJAX/download transport. |
| A new protected attachment companion | Media adds and tests the authoritative source/protection rule; existing Application flows consume the result without adding handler-specific checks. |
| Exporting recent job audit data | Jobs provides a bounded application-facing query/projection; an Application formatter is added only if product-specific output is needed; adapters authorize and stream it. |
| Importing a named image-size preset | Size Management Application validates the decision and decides that it is persisted; the inbound admin adapter authenticates/maps the request; the narrow WordPress/options infrastructure seam performs concrete storage when extracted. Jobs is used only if an explicitly requested follow-on operation is bounded/asynchronous. |
| Previewing regeneration impact without mutation | Regenerate Application coordinates a read-only Media analysis; adapters render it. The preview cannot create delete/promotion authority. |

For any future feature, place policy in Application, persistent execution in Jobs, attachment/filesystem truth in Media, and transport/presentation at the edges. If that division adds more coordination than the feature contains, keep the implementation local until a real shared boundary appears.

## Extraction completion test

An extraction is useful only when all answers are yes:

1. Does one boundary clearly own the moved responsibility and its invariants?
2. Did the change remove a current cross-cutting dependency or enable a concrete roadmap consumer?
3. Are legacy entry points delegating to the same implementation with unchanged persisted and user-visible behavior?
4. Can the PR be reverted independently?
5. Do existing safety/runtime tests and new boundary tests prove equivalence?
6. Is the resulting system no harder to trace than before?

If not, stop at the last proven boundary rather than completing a target folder diagram.
