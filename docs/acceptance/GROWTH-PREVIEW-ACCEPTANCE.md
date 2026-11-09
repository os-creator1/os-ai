# Growth V1 — isolated preview and browser acceptance (PR #567)

Status: **BLOCKED** until an isolated preview of the exact commit under test is approved and running. Nothing here has been deployed.

## 1. Isolated preview (separate from staging and production)
- New Forge site, e.g. `growth-preview.<test domain>` — never `staging.getmotiongrove.com`, never a production host.
- Its own empty `utf8mb4` database (name contains `preview`, never `testing`), its own DB user, its own `storage/` and `.env`.
- Test credentials only: Stripe `STRIPE_MODE=test`, sandbox SMTP, no live provider keys, `APP_DEBUG=false`, fresh `APP_KEY`.
- `release-build.sh` hard-requires `FORGE_SITE_BRANCH=staging` (line 60) and the existing site already tracks `staging`. A second `staging` branch cannot exist in this repository.
- Safe options (no change weakens a production safeguard — production uses `deploy/forge/production/*`, which requires `main`):
  1. **No code change:** a separate preview repository (mirror) whose `staging` branch is the commit under test.
  2. **Reviewed one-line change (needs approval):** in `deploy/forge/release-build.sh` accept `staging` OR `preview/<name>` and, for `preview/*`, additionally require `APP_URL` host not equal to the staging/production hosts and `DB_DATABASE` to contain `preview`.
- First install is separate (`platform:install`, FORGE-STAGING §7); seed the four accounts below from the Platform Owner UI or tinker on the preview database only.

## 2. Accounts
| Account | Setup | Expect on Home |
|---|---|---|
| New | Growth plan, onboarding just completed, no website, no booking type, no contacts | Setup layout; onboarding Finish step named the biggest need (website first) |
| Partially configured | Essentials done (location, primary service, website choice) but booking type has no staff | Growth Home; "Finish your booking setup" is the need; "Online booking" NOT under already set up |
| Active | Published website, ready booking type, contacts, a growth snapshot, 2–4 open recommendations with evidence | Growth Home: one primary with "Why MotionGrove suggests this", ≤2 secondary, Already set up list, Results with real figures |
| Needs attention | Active + a waiting customer and/or a failed read | Primary is the attention item; failed reads shown as unavailable, never zero |

## 3. Checklist — run at desktop (≥1280px), 768px, 360px; capture a screenshot per account × width
- [ ] No horizontal scroll (`document.documentElement.scrollWidth <= innerWidth`).
- [ ] Primary CTA and "Explain this with MotionGrove" visible without overlap; ≥44px tall on mobile.
- [ ] "Why MotionGrove suggests this" wraps long text, no clipped evidence.
- [ ] Secondary "What's next" rows wrap; links open this Business's detail pages.
- [ ] Ask card opens the conversation fully on screen and can be closed; keyboard reachable.
- [ ] Explain button sends the prompt; answer cites the same recommendation as the card; nothing is sent/published/charged.
- [ ] Navigation/menu reachable; no overlapping fixed elements.
- [ ] Recommendation detail link opens the right page for an authorized user; a restricted user cannot open another Location's.
- [ ] Core and Agency accounts still show the standard Home.
