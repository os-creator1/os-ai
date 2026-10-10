# Staging release acceptance

`staging` = preview `eb128385` (already contains the invoice UI branch `86914518`) + Website Builder acceptance `5171550b`
(clean merge, no conflicts). See `WEBSITE-BUILDER-ACCEPTANCE.md` for the Website Builder detail.

## Unresolved Website Builder issues (must be accepted or fixed before release)

1. **Real AI generation is untested.** Only deterministic fakes were run. Needs `OPENAI_ACTIVE=true` and a valid
   `OPENAI_API_KEY` in the environment, plus an approved cost ceiling.
2. **Legacy starter sites cannot enter guided setup.** A site with pages but no setup answers gets "No setup answers
   were found"; Health does not flag missing services, photos or package details.
3. **The setup wizard has no exit.** While an in-progress session exists, every Website entry redirects back into it.
4. **Published snapshots store absolute image URLs.** Images are only correct if `APP_URL` is right at publish time;
   a changed `APP_URL` or host requires re-publishing every site.

## Known baseline test failures (identical on the starting commit)

WebsiteTenancyTest suspended-plan; WebsiteDraftPublishTest rollback; WebsiteDraftPageServiceSeamTest (2, JSON key order);
WebsiteFormTest blacklisting; WebsiteMigrationsTest reverse/replay.
