# Legal Platform Review

Authorized Reviewer: Operator (private single-operator deployment)
Scope: Private, self-hosted, single-operator use only; no public offering and
  no multi-tenant data processing
Decision: approved
Evidence: Operator decision recorded for private deployment. IP-intelligence
  processing remains disabled (`IP_INTELLIGENCE_ENDPOINT` and
  `IP_INTELLIGENCE_API_KEY` are unset in the production configuration), so no
  third-party vendor receives visitor IP data. Fail-closed mode is kept
  (`IP_INTELLIGENCE_FAILURE_MODE=closed`). Visitor data is processed only on
  the operator's own infrastructure over TLS; `docs/IP_INTELLIGENCE.md`
  remains the binding contract if an IP-intelligence vendor is ever enabled,
  in which case this review must be reopened and a vendor/DPA approval
  recorded before activation.
Decision Date: 2026-09-05

Engineering note: this record approves the private, single-operator
deployment described in the Scope. It does not cover any public or
multi-tenant offering. If the deployment scope changes, this review must be
reopened and a new decision recorded.
