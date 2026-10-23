# 05 · Domain / DNS plan — luminaauraevents.com

**No DNS has been modified.** Findings are from public resolvers (Cloudflare DoH), 2026-10-23.

| Record | Value | Meaning |
|---|---|---|
| NS | `ns-cloud-c1…c4.googledomains.com` | Zone served by **Google Cloud DNS under the legacy Google Domains name servers** (Google Domains was sold to Squarespace; such zones are now managed in the owner's Squarespace Domains / Google Domains account). Registrar and panel login: **owner must confirm**. |
| SOA | `cloud-dns-hostmaster.google.com`, TTL 300 | |
| A (apex) | `162.159.140.166` | Cloudflare-fronted, where GHL serves (`x-ghl-serve-region: us`) |
| CNAME www | `sites.ludicrous.cloud` → 104.18.35.90 / 172.64.152.166 | GHL (white-label "ludicrous") custom-domain host |
| MX | **none** | The domain receives **no email**; the customer's mail is a Gmail address |
| TXT apex | `v=spf1 -all` | Domain sends no email (hard fail everything) |
| `_dmarc` | `v=DMARC1; p=reject; sp=reject; adkim=s; aspf=s` | Strict reject policy |
| DKIM | none found at `default`, `google`, `selector1`, `k1` | None published at common selectors |
| CAA / AAAA / DS | none | No CAA restriction on SSL issuance; no DNSSEC |
| `http://` | 301 → https | |

## Records to preserve

Everything except the two GHL web records is preserved untouched: **SPF `-all`, DMARC `reject`**, and the absence of MX. There is no mail to break.

**Consequence for MotionGrove email:** with SPF `-all` + DMARC `p=reject` + `aspf=s`, any email MotionGrove sends *as @luminaauraevents.com* will be rejected by recipients until a MotionGrove sending record is added. Do **not** change SPF/DMARC as part of the website cutover. Send from MotionGrove's own domain (or the Gmail reply-to) until the owner approves a separate, deliberate email-domain change (add MotionGrove to SPF + DKIM CNAMEs, then loosen DMARC only after test).

## Records to change at cutover (on approval only)

| Host | Today | Target | Notes |
|---|---|---|---|
| `@` (apex) | A 162.159.140.166 | MotionGrove's published A/ALIAS target for custom domains | Value to be taken from the Domains module at cutover time — **not guessed here** |
| `www` | CNAME sites.ludicrous.cloud | CNAME to MotionGrove's custom-domain host | |
| `_acme-challenge` / validation TXT | – | Only if MotionGrove's issuer requires DNS-01 | Additive |

Lower TTLs on `@` and `www` to 300 s **48 h before** the change (SOA minimum is already 300).

## Sequence

1. Add the domain to the Lumina Aura Business in MotionGrove's Domains module (isolated preview first); capture the exact targets and verification record it issues.
2. Add the verification TXT (additive, no impact on live traffic).
3. Wait for MotionGrove to report DNS-verified; issue SSL (no CAA conflict). Verify HTTPS on the preview hostname.
4. Pre-cutover checks on the preview: every old URL redirects (see 03); form → contact + opportunity; sitemap/robots; legal pages; mobile.
5. **Approval gate.** Change `@` and `www` in one edit. Keep GHL site published.
6. Verify: HTTPS valid, apex + www, form test with an owner-controlled contact, thank-you page.
7. **Rollback** (any failure within 24 h): restore `@` A `162.159.140.166` and `www` CNAME `sites.ludicrous.cloud`; TTL 300 s so reversal propagates in ≈5 min. Do not delete the GHL site/domain connection until 7 days of clean traffic.
8. Remove the domain from GHL only after the above, and only on approval.

## Owner action
Provide Squarespace/Google Domains access (or add MotionGrove as a delegated manager) **or** make the record edits at cutover under our direction.
