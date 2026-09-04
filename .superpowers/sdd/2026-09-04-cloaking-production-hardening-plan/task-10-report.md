# Task 10 Report

Date: 2026-09-04
Task: Release tag, digest deployment, and final verification
Scope owner: Task 10 release notes, ops smoke script, and release-policy metadata only

## Outcome

Implemented:

- `ops/smoke_test.sh`
- `ops/RELEASE.md` staged-smoke and blocked-gate updates
- `RELEASE_NOTES.md`

Did not implement:

- annotated release tag creation
- immutable image digest publication
- staged deployment execution
- legal/platform approval completion

Those items remain explicitly blocked in the release notes and release policy.

## What changed

### `ops/smoke_test.sh`

Added a staged smoke script that requires:

- `--https-base-url`
- `--admin-username`
- `--admin-password`
- `--restart-command`

Behavior covered by the script:

- HTTP to HTTPS redirect
- Secure `cloaksess` cookie
- admin CSRF rejection and authenticated login
- unauthenticated `/api/links` returns `401`
- denial of config/source/runtime-adjacent files
- `421` rejection for a hostile `Host`
- temporary campaign and link creation/update persistence
- generated-client parity against staged `/api/verify`
- dashboard visibility for client verify hits
- persistence after an operator-supplied restart command

The script creates temporary campaign/link records through the admin UI, starts
a local PHP server for the generated client, and deletes the temporary records
on exit.

### `ops/RELEASE.md`

Updated the release policy to require:

- an explicit staged smoke gate before tagging or publishing
- a real restart command during smoke
- continued blocking when legal approval, tag metadata, or digest metadata are absent

### `RELEASE_NOTES.md`

Added a blocked release record for the current production-hardening candidate:

- candidate app commit: `e315f7e`
- no tag created
- no image digest published
- no `release-artifacts/release-metadata.json`
- no `release-artifacts/sbom.spdx.json`
- schema target recorded as version `3`
- restore evidence and checksum manifest copied from `ops/rehearsals/20260904T201950Z-v2`
- legal/platform review explicitly recorded as pending

## Verification

### Local checks that passed

1. `bash -n ops/smoke_test.sh`
2. `php -l ops/validate_release_inputs.php`
3. `php -l bin/migrate.php`
4. `php tests/run.php`
5. `rg --files -g '*.php' | xargs -n 1 php -l`
6. `bash tests/Integration/check_pinned_images.sh`
7. `bash ops/smoke_test.sh --help`

`php tests/run.php` passed the full custom suite, including:

- unit coverage for credentials, migrations, rules, and security
- integration coverage for admin auth/CSRF, host rejection, client parity, dashboard client hits, recovery, and release validation

### Docker-backed checks skipped with evidence

1. `bash tests/Integration/web_server_test.sh all`
   - result: `SKIP: Docker daemon unavailable; skipping all integration checks.`
2. `docker info`
   - result: Docker client present, server unavailable
   - exact failure: `failed to connect to the docker API at unix:///Users/nasir/.docker/run/docker.sock: connect: no such file or directory`

### Clean-baseline release-gate probe

To separate policy blockers from the in-progress workspace state, I cloned the
baseline candidate commit `e315f7e` into `/tmp/cloaking-release-clone.my5TFx`
and ran:

1. `php ops/validate_release_inputs.php --phase=published`

That probe failed as expected with the release still blocked by:

- missing `LEGAL_APPROVAL_ATTESTATION`
- missing `release-artifacts/release-metadata.json`
- pending fields in `LEGAL_PLATFORM_REVIEW.md`
- missing versioned git tag / exact commit / protected-ref provenance inputs

## Remaining blockers

1. `LEGAL_PLATFORM_REVIEW.md` is still pending and no protected attestation was supplied.
2. No immutable release tag exists for the candidate.
3. No published image digest or release metadata artifact exists.
4. No staged deployment URL, admin credentials, or restart command were supplied here, so `ops/smoke_test.sh` could not be executed against a real target.
5. Docker daemon is unavailable locally, so containerized integration/build checks could not be rerun in this workspace.

## Notes for release operator

When the external gates are ready, run:

```bash
bash ops/smoke_test.sh \
  --https-base-url=https://app.example.com \
  --http-base-url=http://app.example.com \
  --admin-username=owner \
  --admin-password='replace-me' \
  --restart-command='docker compose restart web edge'
```

Only after that staged smoke run, legal approval attestation, immutable tag,
and immutable digest metadata are all present should the release move from
blocked to publishable.

## Fix Round 1

Date: 2026-09-04

Addressed findings:

1. Extended `ops/smoke_test.sh` to accept an explicit staged generated client
   export via `--client-index-path` plus paired `--assigned-campaign-id` and
   `--other-campaign-id` inputs, while still generating a deterministic local
   fixture when those inputs are omitted.
2. Added client-artifact checks that:
   - assert the client artifact does not embed the administrator API key
   - assert the scoped client credential can verify its assigned campaign
   - assert cross-campaign `/api/verify` calls are rejected
   - assert management API calls with that client credential are rejected
3. Strengthened persistence smoke to create and verify:
   - a custom domain
   - an assigned campaign with create-state assertions across mutable fields
   - the same campaign with update-state assertions across mutable fields
   - a second campaign for cross-campaign credential rejection
   - a link with create-state assertions across mutable fields
   - the same link with update-state assertions across mutable fields, including
     campaign binding and domain clearing
4. Strengthened restart persistence to re-check the campaign and link edit forms
   after the operator-supplied restart command, not just name presence.
5. Corrected `ops/RELEASE.md` so the staged smoke gate is documented as a
   runtime-behavior check and the legal/tag/digest provenance remains a
   separate validator gate.
6. Added `tests/Integration/SmokeScriptContractTest.php` for the smoke-script
   argument/usage contract.

### Fix Round 1 Verification

Fresh commands run after the round-1 edits:

1. `bash -n ops/smoke_test.sh`
2. `php tests/run.php`
3. `rg --files -g '*.php' | xargs -n 1 php -l`
4. `bash tests/Integration/check_pinned_images.sh`
5. `bash ops/smoke_test.sh --help`
6. `bash tests/Integration/web_server_test.sh all`
7. `php ops/validate_release_inputs.php --phase=published` after committing the round-1 fixes

Results:

- `php tests/run.php` passed, including:
  - `SmokeScriptContractTest::test_smoke_script_help_mentions_explicit_client_scope_inputs`
  - `SmokeScriptContractTest::test_smoke_script_rejects_explicit_client_artifact_without_scope_ids`
- Full PHP lint passed across all tracked PHP files.
- Digest pinning validation passed.
- Docker-backed integration checks still skipped with:
  `SKIP: Docker daemon unavailable; skipping all integration checks.`
- The clean-checkout published-phase validator still failed exactly on the
  intended external release blockers:
  - missing `LEGAL_APPROVAL_ATTESTATION`
  - missing `release-artifacts/release-metadata.json`
  - pending `LEGAL_PLATFORM_REVIEW.md`
  - missing explicit tag / commit / protected-ref provenance inputs

### Remaining external blockers after Fix Round 1

The release is still blocked for the same external reasons:

1. `LEGAL_PLATFORM_REVIEW.md` remains pending and no protected attestation was supplied.
2. No immutable release tag exists for the candidate.
3. No published image digest or release metadata artifact exists.
4. No real staged URL, admin credentials, restart command, or explicit staged
   client artifact were supplied here, so the live staged smoke command still
   has not been executed against a deployment target.
5. Docker daemon remains unavailable locally, so containerized deployment-path
   checks still cannot run in this workspace.
