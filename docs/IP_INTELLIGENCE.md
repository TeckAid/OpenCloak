# IP Intelligence Security and Data-Processing Contract

The application does not call a public plaintext IP lookup service. Operators
must provide either a locally hosted adapter or a reviewed vendor endpoint in
`IP_INTELLIGENCE_ENDPOINT`. The endpoint must use HTTPS and bearer
authentication. Requests are JSON POST bodies containing only the normalized
visitor IP; credentials and IPs are never placed in URLs.

The adapter response is accepted only when it is JSON with this exact semantic
shape:

```json
{
  "ip": "203.0.113.10",
  "asn": "AS64500",
  "country_code": "US",
  "is_proxy": false,
  "is_hosting": false
}
```

The response IP must equal the requested IP. ASN, country, and boolean fields
are schema-validated before they can influence a decision. TLS peer and host
verification are mandatory. The default `IP_INTELLIGENCE_FAILURE_MODE` is
`closed`: when a rule needs network intelligence and the adapter is missing,
times out, or returns invalid data, the request is denied with
`ip_intelligence_unavailable`. An explicit `open` setting is an operator risk
acceptance and must be recorded in the release ticket.

Before production use, an authorized privacy/legal reviewer must record the
lawful basis, vendor and DPA/subprocessor approval, allowed processing regions,
retention/deletion period, access controls, incident notification obligations,
and any user disclosure or consent requirement. Raw vendor responses must not
be logged. The application cache contains only the normalized decision fields
for up to 24 hours in the service temporary directory; infrastructure cleanup
and regional placement must enforce the approved retention policy. Production
release remains blocked while `LEGAL_PLATFORM_REVIEW.md` is pending.
