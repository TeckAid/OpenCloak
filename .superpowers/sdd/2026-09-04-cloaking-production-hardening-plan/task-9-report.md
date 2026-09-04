# Task 9 Report

## Status

Implemented the CI, provenance, and release-gate scope in this checkout:

- `.github/workflows/ci.yml`
- `.github/dependabot.yml`
- `SECURITY.md`
- `LEGAL_PLATFORM_REVIEW.md`
- `README.md`
- `ops/validate_release_inputs.php`
- `tests/Integration/ReleaseValidationTest.php`

The release gate is intentionally still blocked pending an authorized
legal/platform approval artifact. Engineering-added repository changes do not
self-approve that review.

## CI and Provenance Changes

- Replaced the single-job workflow with pinned-SHA GitHub Actions jobs for:
  - clean checkout verification
  - PHP setup, lint, and full test execution
  - no-cache production image build
  - Apache, Nginx, and Caddy integration coverage
  - SBOM generation and image vulnerability scanning
  - protected release-tag-only GHCR digest publication plus metadata upload
- Added `.github/dependabot.yml` for weekly GitHub Actions and Docker digest refresh PRs.
- Added `ops/validate_release_inputs.php` to enforce:
  - clean git checkout
  - completed `LEGAL_PLATFORM_REVIEW.md`
  - versioned git tag and exact 40-character commit SHA
  - immutable digest-backed image reference
  - present SBOM artifact path in release metadata
- Added `tests/Integration/ReleaseValidationTest.php` to cover dirty inputs,
  pending legal review, mutable image references, missing digest metadata, and
  the success case.
- Added `SECURITY.md` with private vulnerability reporting and secret-handling
  expectations.
- Added a pending `LEGAL_PLATFORM_REVIEW.md` template that explicitly requires
  an external authorized reviewer before release.
- Updated `README.md` with the release validator command, the legal gate, and
  the CI release expectations.

## Verification Evidence

Verified on September 4, 2026:

- `php tests/run.php` passed, including all `ReleaseValidationTest` coverage.
- PHP lint across tracked `.php` files passed.
- `tests/Integration/check_pinned_images.sh` passed.
- `docker compose config --quiet` passed.
- Manual release-validator failure probe on a clean temporary git repo failed as expected for:
  - missing `release-artifacts/release-metadata.json`
  - pending `LEGAL_PLATFORM_REVIEW.md` fields
- Manual release-validator success probe on a clean temporary git repo passed with:
  - approved legal review fields
  - digest-pinned image reference
  - present SBOM artifact and metadata JSON
- `.github/workflows/ci.yml` loaded successfully via Ruby YAML parsing.

## Local Verification Limits

- `docker build --pull --no-cache --file Dockerfile --tag cloaking:local-verify .` failed locally because the Docker daemon socket was unavailable at `/Users/nasir/.docker/run/docker.sock`.
- `tests/Integration/web_server_test.sh apache`
- `tests/Integration/web_server_test.sh nginx`
- `tests/Integration/web_server_test.sh caddy`

Each integration script exited with the built-in skip message: Docker daemon
unavailable. That means the workflow logic is in place, but I could not finish
local image-build, integration-container, SBOM, or vulnerability-scan
verification on this host.

## Concerns

- The protected release-tag job can now publish digest metadata, but the repo
  intentionally remains non-releasable until an authorized reviewer completes
  `LEGAL_PLATFORM_REVIEW.md`.
- Docker-based verification is still outstanding on a host with a running
  Docker daemon. The workflow and scripts are ready, but I do not have fresh
  local build/scan evidence from this machine.

## Fix Round 1

- Reordered the release workflow so the protected `legal-approval` environment
  injects `LEGAL_APPROVAL_ATTESTATION` and the prepublish legal/git provenance
  gate runs before any GHCR login or push.
- Split release validation into two phases:
  - `prepublish` validates clean checkout, external legal attestation, and real
    git tag/commit provenance.
  - `published` validates the pushed digest metadata and SBOM artifact in
    addition to the same legal/provenance gates.
- Hardened `ops/validate_release_inputs.php` so legal approval now requires a
  JSON attestation with `authorized_by`, `decision=approved`, `review_sha256`,
  and `issued_at`, and the digest must match `LEGAL_PLATFORM_REVIEW.md`.
- Hardened git provenance checks so the claimed tag and commit must both resolve
  to real git objects and must match the current release `HEAD`.
- Updated `tests/Integration/ReleaseValidationTest.php` so the happy-path cases
  create real temporary commits and tags rather than synthetic SHA strings, and
  added regressions for:
  - missing protected attestation
  - mismatched attestation digest
  - pending review record even with an attestation
  - tag not pointing at the claimed release commit
- Updated `README.md` so it no longer implies that editing the markdown record
  alone can satisfy the release gate.

### Fix Round 1 Verification

Verified on September 4, 2026:

- `ReleaseValidationTest` passed with all new attestation and tag-provenance cases.
- `php tests/run.php` passed.
- PHP lint across tracked `.php` files passed.
- `.github/workflows/ci.yml` parsed successfully via Ruby YAML loading.

### Remaining Concern

- The checked-in `LEGAL_PLATFORM_REVIEW.md` remains pending by design, so this
  repo still does not claim legal/platform approval.
