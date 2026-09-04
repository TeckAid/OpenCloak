# Cloaking Release Notes

Release status: BLOCKED
Assessment date: 2026-09-04

This record captures the current engineering evidence for the production
hardening release candidate without fabricating any unpublished tag, digest,
deployment, or legal approval.

## Candidate provenance

- Candidate application commit: `e315f7e`
- Candidate release tag: not created
- Published image digest: not published
- Published release metadata artifact: not present under `release-artifacts/`
- Published SBOM artifact: not present under `release-artifacts/`
- Current migration target: schema version `3` (`001_initial_schema.sql`, `002_current_columns.sql`, `003_client_credentials.sql`)

## Recovery evidence

- Restore rehearsal: `ops/rehearsals/20260904T201950Z-v2/restore-evidence/rehearsal-summary.json`
- Restore smoke evidence: `ops/rehearsals/20260904T201950Z-v2/restore-evidence/smoke-results.json`
- Backup checksum manifest: `ops/rehearsals/20260904T201950Z-v2/backup-artifacts/SHA256SUMS`

Restore rehearsal results:

- `integrity_check`: `ok`
- `migration_exit`: `0`
- `rpo_seconds`: `0`
- `rto_seconds`: `0.499`
- `restored_db_sha256`: `3813b774c36b368737eea5c8644823df0084e109d1293641aacec6b8ca0f2dbb`

Backup checksum manifest:

- `9f28946eb1461c9c6c5fde6874220b478ad17b3dc11719c6ecca1c853f9949ad  cloaking.sqlite`
- `694ff60b8fa08c3df3bc8acf79dd84ea9da54b66cf1597d9ecc4bd6d02d446c8  app.key`
- `36f56783842b6ae1986708d3149bea379b0b9ae9c5e8f8b21518950561c2a9a4  config.local.php`
- `047cb8cf738eb60e192653e8cb6716a5fac7f73086062cdba9a25763db3e52cd  backup-metadata.json`

## Approval artifacts

- Legal/platform review record: `LEGAL_PLATFORM_REVIEW.md`
- Protected approval attestation: not supplied

`LEGAL_PLATFORM_REVIEW.md` is still intentionally pending:

- `Authorized Reviewer: PENDING`
- `Scope: PENDING`
- `Decision: pending`
- `Evidence: PENDING`
- `Decision Date: PENDING`

## Release gate status

- Clean-checkout application baseline available at `e315f7e`: yes
- Full local PHP tests/lint on this docs-and-ops update: see task report
- Docker-backed integration build checks: blocked locally because the Docker daemon is unavailable
- Staged smoke run via `ops/smoke_test.sh`: not executed because no staged deployment URL, admin credentials, or restart command were supplied in this workspace
- Annotated immutable release tag: blocked until smoke, legal attestation, and published metadata all exist
- Immutable deployment digest: blocked until the approved image is built and published
- Deployment / rollback handoff: blocked until the exact previous digest and target digest are both known

Do not mark this release approved, tagged, or deployed until the missing legal
artifact, staged smoke evidence, immutable digest metadata, and final rollout
operator evidence are attached.
