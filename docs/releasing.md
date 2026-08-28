# Releasing Thumbnail Manager

This runbook describes the Human-gated GitHub-to-WordPress.org release pipeline. It does not authorize a release. Version selection, Git tag creation, WordPress.org publication, Release Confirmation, and GitHub Release publication remain explicit Human actions mediated by the protected workflows.

## One-time production setup

Before the first production use, a repository administrator and WordPress.org plugin committer must deliberately configure and verify:

1. GitHub Environment `wordpress-org-production`:
   - required Human reviewer(s);
   - deployment branch policy allowing protected `main` only;
   - environment variable `WPORG_SVN_USERNAME=yoohw`;
   - environment secret `WPORG_SVN_PASSWORD` containing the WordPress.org SVN-specific password.
2. A repository tag ruleset that permits creation of numeric release tags but blocks their update, force-update, and deletion.
3. `release/wporg-policy.json`:
   - slug and SVN URL remain `thumbnail-manager`;
   - `assets_mode` remains `unchanged` for TM-WF-0002;
   - a WordPress.org plugin committer inspects Release Management and records `release_confirmation.mode` as `enabled`, `disabled`, or `unknown`, with observation provenance. Use `unknown` when uncertain.
4. The stale `Composer Lock Refresh` Actions workflow record is disabled if it remains visible after its YAML deletion.

Do not store WordPress.org email/tokenized Release Confirmation links in GitHub. They are Human-only WordPress.org credentials.

## Boundary 1: prepare an immutable candidate

1. Create `release/<version>` from reviewed `main`.
2. Update only the release metadata deliberately selected for that release:
   - plugin `Version` and `YOTM_VERSION`;
   - `readme.txt` Stable Tag, changelog and Upgrade Notice;
   - `changelog.txt` newest release;
   - POT `Project-Id-Version`;
   - `Tested up to` only when the exact stable WordPress version has supporting matrix evidence.
   Every active release field must use the selected exact numeric version string.
3. Open a normal reviewed PR. Merge only with the protected `CI Gate` green.
4. From the Actions UI on **main**, manually run `Prepare WordPress.org Release Candidate` with:
   - the exact 40-character merged candidate SHA;
   - the exact numeric version.
5. Review the `RC_PREPARED` summary and artifact evidence:
   - exact source SHA/version;
   - deterministic package SHA-256;
   - manifest and expanded-tree SHA-256;
   - release-control bundle SHA-256 covering the allowlist, Plugin Check baseline, builder/validators and preparation workflow;
   - manifest-expanded positive payload and the exact file count recorded in the release evidence;
   - Plugin Check result and reviewed warning-baseline delta;
   - successful exact-SHA `CI Gate`.

Release preparation is read-only. It cannot access WordPress.org credentials and cannot create a tag, Release, SVN commit, or publication.

## Boundary 2: publish after Human approval

From the Actions UI on **main**, manually run `Publish Thumbnail Manager to WordPress.org` with:

- `operation=publish`;
- the successful preparation run ID;
- the same exact candidate SHA and version;
- `dry_run=true` for rehearsal, or `dry_run=false` only for an explicitly authorized production release.

The workflow enforces this sequence:

```text
RC_PREPARED
  -> READ_ONLY_PUBLICATION_PREFLIGHT
  -> HUMAN_PUBLICATION_GATE
  -> FINAL_PRE_MUTATION_REMOTE_RECHECK
  -> TAG_SEALED
  -> SVN_COMMITTED (trunk + new numeric tag atomically)
  -> SVN_VERIFIED
  -> WORDPRESS_ORG_RELEASE_STATE
```

The preflight happens before the protected environment. Review its exact candidate, manifest, current SVN trunk/assets digests, target-tag absence, local SVN delta, and Release Confirmation mode. Approving the environment authorizes only that snapshot. Relevant SVN drift after approval invalidates the release before tag sealing.

The workflow definition and trusted helpers always execute from protected `main`. Candidate source/artifacts are treated as data and are never executed in the secret-bearing step. `WPORG_SVN_PASSWORD` is mapped only to the atomic SVN commit step.

`assets/` is always left unchanged. An existing Git or SVN version tag must match the exact recovery identity or the workflow fails closed; it never moves, deletes, overwrites, or recreates a released tag.

## WordPress.org Release Confirmation

After the SVN commit, the production path performs a fresh checkout and authenticates the exact SVN revision/log message, candidate SHA, manifest, trunk, tag and unchanged assets against the approved preflight. Only that verified result may enter WordPress.org confirmation/propagation handling or populate the durable publication record.

After authenticated SVN verification:

- `enabled` or `unknown` produces `WPORG_RELEASE_CONFIRMATION_PENDING`. A WordPress.org plugin committer must confirm through Release Management/tokenized email. Do not rerun publication and do not recommit SVN.
- `disabled` permits bounded public propagation checks. A timeout produces `WPORG_PROPAGATION_PENDING`; this is not an SVN failure.
- GitHub Release publication is allowed only after `WPORG_PUBLIC_RELEASE_VERIFIED`.

After Human confirmation or propagation delay, run the same publisher from **main** with:

- `operation=verify-only`;
- the original preparation run ID;
- the same candidate SHA/version;
- `original_publish_run_id` set to the run that committed SVN.

The verification-only path has no SVN password, tag-creation command, or SVN write command. It re-authenticates the preparation and original-publish artifacts, verifies the immutable Git tag, SVN revision/log/trunk/tag, unchanged assets, Plugins API version, and expanded public download tree. If the SVN commit response was lost before a publication record could be uploaded, it may reconstruct that record only from the authenticated preflight plus the exact SVN log/tree identity. It then creates, publishes, or idempotently reconciles the GitHub Release and its exact asset set.

## Recovery table

| Durable state | Safe continuation |
| --- | --- |
| Preflight failed | Correct inputs/state and start a new read-only preflight; no tag exists. |
| Human gate rejected/timed out | Candidate remains `RC_PREPARED`; no mutation occurred. |
| Final recheck failed | Re-run preflight and obtain a new approval for the new snapshot. |
| `TAGGED_NOT_PUBLISHED` | Continue only when the annotated tag resolves to the exact SHA and target SVN tag is absent. Never move the Git tag. |
| SVN commit outcome ambiguous | Run `verify-only`. Exact SVN tag/log/tree/assets evidence reconstructs the missing record and continues; a missing SVN tag may use an explicitly approved publish recovery; any mismatch requires `HUMAN_DECISION_REQUIRED`. |
| `WPORG_RELEASE_CONFIRMATION_PENDING` | Human confirms in WordPress.org, then runs `verify-only`. |
| `WPORG_PROPAGATION_PENDING` | Wait, then run `verify-only`; never recommit SVN. |
| SVN/public verified but GitHub Release missing | Run `verify-only`; exact existing Release is a no-op, mismatch requires Human review. |

Never interpret CDN/API delay, pending Release Confirmation, an existing tag, or an unexpected SVN delta as permission to overwrite production state.
