# Cloaking Release Notes

Release status: BLOCKED
Assessment date: 2026-09-05

This record describes the final production-hardening application candidate and
keeps unperformed release work explicitly blocked. It does not claim a tag,
published image, deployment, legal approval, or production recovery proof.

## Candidate provenance

- Candidate application commit: `def6a8eb7e34dd6fbeb599604fb4594dce8efe5f`
- Candidate release tag: not created
- Published image digest: not built or published
- Published release metadata artifact: not present under `release-artifacts/`
- Published SBOM artifact: not present under `release-artifacts/`
- Current migration target: schema version `3` (`001_initial_schema.sql`,
  `002_current_columns.sql`, `003_client_credentials.sql`)

The release-notes commit is intentionally a documentation-only successor to
the application commit above. A future immutable release tag must identify the
exact clean source commit that is built and must be paired with its real image
digest; this record does not invent either value.

## Verified local engineering evidence

- Full dependency-free PHP suite: `113` tests passed on 2026-09-05.
- PHP syntax lint: passed for every PHP source and test file.
- Shell syntax lint: passed for every tracked shell script.
- Workflow YAML parse: passed.
- `docker compose config --quiet`: passed.
- Digest-pinned image reference check: passed.
- `git diff --check`: passed before the candidate commit.
- Recovery regressions exercised authenticated manifest validation, private
  backup modes, corrupt/tampered/empty backup rejection, migration of a legacy
  backup, real administrator login, authenticated dashboard access, API `401`,
  active-link delivery, logical row checks, and source/image provenance fields.

The Docker daemon was unavailable on the review host. The Compose
install/migrate/backup/restart/restore lifecycle and Apache/Nginx/Caddy runtime
checks therefore emitted explicit `SKIP` results and were not executed. No live
Hetzner or staged endpoint, administrator credential, trusted CA bundle, or
restart command was available, so `ops/smoke_test.sh` was not run against a
deployment.

## Superseded historical rehearsal

The files under `ops/rehearsals/20260904T201950Z-v2/` remain historical only.
That rehearsal predates the authenticated backup manifest, source/image
binding, required logical-data checks, and actual login/dashboard verification
introduced by the candidate above. Its login evidence records only a `200`
login-page fetch. It must not be used to certify this candidate or satisfy the
new restore gate.

A qualifying release rehearsal still must be generated from a real backup of
the target deployment with the exact source commit and immutable image digest,
then retained with the release ticket.

## Approval and release blockers

- Legal/platform review record: `LEGAL_PLATFORM_REVIEW.md`
- Authorized reviewer: pending
- Protected legal approval attestation: not supplied
- Staged TLS smoke evidence: not available
- Docker-backed lifecycle evidence for this candidate: not available locally
- Qualifying deployment backup and restore rehearsal: not recorded
- Immutable release tag, image digest, SBOM, and published metadata: not present
- Previous and target deployment digests for rollback: not supplied

Do not mark this release approved, tagged, published, or deployed until every
blocked artifact is attached and `ops/validate_release_inputs.php` succeeds for
both the protected prepublish phase and the published metadata phase.
