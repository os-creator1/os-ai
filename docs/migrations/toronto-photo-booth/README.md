# Toronto Photo Booth — GHL migration

Source: GHL location `9Yjeolqf4nEkFxyPmqJY` ("The Photo Booth Company Of Toronto", smiles@pbto.ca, +1 647-654-7286, America/Toronto).
Verified as the `emphotobooth.site` location: its URL-redirect list holds `www.emphotobooth.site -> emphotobooth.site` and funnel
"Photo Booth #3" (domain `OQNBk8Rh8xG3u9GIkPfy`) serves the live site. The GHL profile website is `photoboothcompanyoftoronto.com`
(currently 404). Customer data, tokens and media are **never** committed; only `website-content.json` (public site copy) is.

## What is here
- `website-content.json` — public copy, SEO, image roles (by sha256) for 15 pages; consumed by `TorontoWebsiteBuilder`.
- `run-on-forge.sh` — guarded server script (dry run by default; `--apply` writes).
- Code: `app/Library/Migration/Toronto/*`, `app/Console/Commands/TorontoProductionProvisionCommand.php` (reuses `GhlImportPlanner`/`GhlImportWriter`).

## Safety properties
Guard requires `APP_ENV=production`, host `getmotiongrove.com`, DB `GetMotionGrove`, a validated backup path and an exact phrase.
Aborts if any business matching the email/domain/name exists outside the expected Workspace. Additive and idempotent (GHL ids are the ledger).
Sends nothing (queue/mail/notifications faked, outbound HTTP refused), no Stripe object, no phone/provider/DNS change, no workflow published,
nobody enrolled, no invitation. GHL auto-generated "(Example)" demo contacts/deals are skipped (kept in the archive; `--include-ghl-samples` to import).
The Photo Booth Business Pack is **not** installed (V3 overlaps V2, incl. the case-insensitive "Check Availability" form collision; no consolidated version exists yet).
The GHL "Marketing Pipeline" is recreated instead.

## Run (PowerShell, from the folder holding the downloaded bundle)
```powershell
scp .\toronto-prod-bundle.tgz .\run-on-forge.sh forge@162.243.175.79:~/
ssh forge@162.243.175.79 "bash ~/run-on-forge.sh"            # read-only: checks + backup + dry run
ssh -t forge@162.243.175.79 "bash ~/run-on-forge.sh --apply" # writes after you type the phrase
```
Prerequisite: this branch must be merged and deployed so `toronto:production-provision` exists (the script aborts otherwise).
Set `OWNER_EMAIL=...` (env var on the ssh line) to use a different agency-controlled login; the temporary password is printed once.

## Not done / blockers
- Photo Booth Business Pack: blocked on a consolidated (V2+V3) version. Existing GHL pipeline/forms/workflows are preserved in the archive only.
- GHL workflow step definitions are not exposed by the public API (list only); funnel pages are exposed as metadata only (content captured from the live site).
- Prices: the brochure shows $679/$779/$889 for template-labelled "Booth 1/2/3"; not tied to named packages, so the site says "Request a quote".
- Backdrop photos are website gallery assets; native `BusinessBackdrop` image rows were not created.
- Custom domain: not attached; DNS untouched.
