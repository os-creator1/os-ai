# Business Email: outbound SMTP gateway for "Other email"

## Audit: what already works (no new inbound or Google/Microsoft work)

| Provider flow | Receive | Send | Notes |
|---|---|---|---|
| Google / Gmail / Workspace | Gmail API (HTTPS, OAuth) | Gmail API | `GoogleBusinessEmailProvider`. Untouched. |
| Microsoft 365 / Outlook / GoDaddy MS | Graph (HTTPS, OAuth) | Graph `Mail.Send` | `MicrosoftBusinessEmailProvider`. Untouched. |
| Other (Namecheap Private Email, Zoho, Titan/Hostinger, generic) | IMAP 993/143 TLS, `ImapClient` + `ImapHostGuard` | **Direct SMTP 465/587 from the app host** | `ImapBusinessEmailProvider`, presets in `ImapPresets`. |

Credentials for Other email are one JSON blob (`ImapSettings`) in the account's existing encrypted credential column; mandatory TLS with certificate verification against the name; port allow-list; SSRF guard (public DNS names only, every resolved address public, connect-by-IP). IMAP inbound uses a UID cursor with UIDVALIDITY handling, Message-ID dedupe and RFC-header thread roots. Sending has idempotency, ambiguous-failure (`unconfirmed`, never auto-resent) and bounded retry handling in `BusinessEmailSender`.

**The only broken piece is outbound SMTP on DigitalOcean** (25/465/587 blocked), so Other-email sends and the SMTP half of "Test connection" cannot connect. This is repaired, not rebuilt: the existing `SmtpMailer` seam gets an HTTPS implementation.

## Design

```
app (DigitalOcean) --HTTPS, HMAC-signed--> gateway VPS --SMTP 465/587 + TLS--> customer's SMTP server
```

* `HttpSmtpGatewayMailer` (app side) is selected automatically when `BUSINESS_EMAIL_SMTP_GATEWAY_URL` is set. Unset = today's direct behaviour (local/dev).
* The gateway is **the same Laravel codebase** with `BUSINESS_EMAIL_SMTP_GATEWAY_SERVE=true`, exposing only `POST /internal/smtp-gateway/v1/{send,test}` (`routes/smtp_gateway.php`, `SmtpGatewayHandler`). It needs no database content, no queue and no mailbox data; it is stateless apart from Cache counters. A gateway deployment never forwards to another gateway.

### Security properties (all covered by `tests/Feature/BusinessEmail/SmtpGatewayTest.php`)

* **Authentication**: HMAC-SHA256 over `timestamp.nonce.body`, constant-time compare; 60 s clock skew; single-use nonce (replay rejected); `previous_secret` for zero-downtime rotation; secret ≥ 32 chars; app refuses a non-`https://` gateway URL (the body carries the mailbox password).
* **Not an open relay**: the credential is the customer's own and is used for one SMTP session; the SMTP server enforces who may send. Additionally the gateway requires `From` == the authenticated mailbox, exactly one validated recipient, plain text only, bounded sizes, header values re-sanitised (no CR/LF injection).
* **No internal-network access**: host must be a public DNS name (no IP literals / `localhost` / internal suffixes); the gateway **re-resolves** it, every address must be public (RFC1918, loopback, link-local/metadata `169.254.169.254`, CGNAT, mapped IPv6 refused); connection is by the vetted IP with SNI/verification on the name; ports 465/587 only; TLS mandatory.
* **Tenant isolation / secrecy**: stateless, credentials only in the request body, never stored or logged; logs contain only action, coarse failure code and a sha1 of `mailbox|host`. Rate limits are keyed per mailbox (`send` 120/h, `test` 20/h) plus a global ceiling (2000/h); one tenant exhausting its budget does not affect another.
* **Timeouts**: connect 8 s / IO 20 s on the SMTP leg (existing config), 45 s client HTTP timeout.
* **Failure fidelity**: the gateway returns only coarse codes (`auth`, `recipient`, `tls`, `connect`, `timeout`, `other`), mapped back to the exact messages the existing classifiers use, so categories match direct mode: bad credentials → auth expired (revokes grant), 55x → recipient invalid, TLS → permanent, connect/gateway-unreachable/429 → safe retry, SMTP timeout / gateway read timeout / gateway 5xx → ambiguous (`unconfirmed`, never re-sent).
* **Sender identity, sent tracking, threading**: unchanged — the provider still builds Message-ID, In-Reply-To/References and returns provider/thread ids exactly as before; the gateway only transports them.

### Environment

App (DigitalOcean):
```
BUSINESS_EMAIL_SMTP_GATEWAY_URL=https://smtp-gw.<domain>/internal/smtp-gateway/v1
BUSINESS_EMAIL_SMTP_GATEWAY_SECRET=<openssl rand -hex 32>
```
Gateway VPS:
```
BUSINESS_EMAIL_SMTP_GATEWAY_SERVE=true
BUSINESS_EMAIL_SMTP_GATEWAY_SECRET=<same>
CACHE_DRIVER=file   # or redis; single node is fine
```

### Infrastructure requirements (not provisioned here)

* One small Linux VPS (1 vCPU / 1 GB), nginx/Caddy + PHP-FPM with a valid TLS certificate for a dedicated hostname, only 443 inbound (restrict to the app's IP if static).
* Outbound **465 and 587 must be open** at the provider. Verify on the actual box before committing: `nc -vz smtp.gmail.com 465; nc -vz smtp.gmail.com 587`.
* Host firewall egress deny to RFC1918, link-local (`169.254.0.0/16`) and loopback-other as defence in depth beyond the app-level guard.
* Logs: never enable request-body logging in the web server.

### Provider port policy (unverified from first-party docs in this session)

DigitalOcean blocks 25/465/587 (confirmed by search result summarising its docs). Hetzner reportedly blocks 25 and 465 by default (587 open) — **unsuitable if 465 is needed**. Many VPS vendors (e.g. Vultr, reportedly) block only 25. First-party documentation could not be fetched here, so treat all of this as a hypothesis: the `nc` probe above on a trial instance is authoritative.

### Estimated monthly cost

~US$5–7 for one entry VPS (Vultr/OVH/Contabo class, subject to the port probe) + a domain/DNS record you already own. TLS via Let's Encrypt is free. No per-email fees; customers pay for their own mailboxes. A second VPS for HA would double that (not needed initially: failed sends are retried by the existing bounded retry).
