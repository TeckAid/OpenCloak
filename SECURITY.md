# Security Policy

## Reporting a vulnerability

Do not file public issues for suspected security problems, leaked secrets, or
platform-enforcement findings.

Send a private report to the repository owner or security contact with:

- a short description of the issue and the affected endpoint, page, script, or image tag
- reproduction steps, proof-of-concept requests, and expected impact
- the exact commit, release tag, and image digest if the issue is release-specific
- any log excerpts with secrets, IPs, API keys, cookies, debug tokens, and app keys redacted

Acknowledgement should happen within two business days, with an initial triage
update within five business days. Do not share exploit details publicly until a
fix, mitigation, or risk decision has been communicated to affected operators.

## Secret handling

- Treat `config.local.php`, `app.key`, SQLite backups, API keys, session cookies, debug tokens, visitor IPs, and referrers as sensitive.
- Keep runtime secrets and mutable state outside the deployed app tree in a root-owned directory such as `/srv/cloaking/runtime`.
- Never commit live secrets, reusable runtime configs, SQLite databases, or raw backup payloads to git.
- Use the `Authorization` header for API credentials; never place tokens in URLs, query strings, screenshots, or release notes.
- Redact secrets from CI logs, restore evidence, legal review evidence, and support tickets before sharing them.

## Release expectations

- Release from a clean checkout only.
- Address published images by immutable digest, not mutable tags alone.
- Keep the previous release digest, the current backup checksum set, and the latest restore-rehearsal evidence together for rollback.
- Treat `LEGAL_PLATFORM_REVIEW.md` as an external approval gate. Engineering may prepare the template and supporting evidence, but an authorized reviewer must supply the approval decision and evidence before release.
