# Website Review page (pre-generation)

Branch `agent/website-review-page-redesign`. Presentation-only redesign of the screen shown after the last setup question
(`setup.review`). The wizard, generation pipeline, photo-publishing policy and templates are unchanged.

## What the page is

One dashboard, two columns on desktop (main + sticky sidebar), one column on mobile (the "Ready to generate" card leads):

1. **Your website at a glance** - business, contact, pricing, booking button, six counts (booths, event types, packages,
   backdrops, review quotes, uploaded photos). Guided (Photo Booth) setups only.
2. **Choose your website's look** - the four real template previews (Bold Event, Classic Gold, Midnight Gold, Party Luxe),
   brand colour, logo, hero image, privacy/terms links.
3. **About your business**, 4. **Services & packages**, 5. **Website content**, 6. **Contact & other** - compact rows of
   *saved* values with an Edit link each.
7. Sidebar: **Ready to generate**, **Photos needed to publish** (live checklist), **On this page** (smooth-scroll nav).

Other questionnaires (non-guided) keep the same layout but render the generic saved-answer summary
(`WebsiteWizardController::answerSummary`) in the same cards; they have no at-a-glance card or photo checklist.

## How saving works (no new write paths)

- **Edit** links go to `setup.step/{screen}?return=review`. The question view adds a hidden `return_to=review` and a
  "Back to review" link; `autosaveAnswer` then redirects to the Review page once that one screen is saved (only when the
  answers are complete - otherwise the wizard continues as before). All answers are preserved; nothing restarts.
- **Look** (design, brand colour, logo, hero, legal links) posts to the existing `website.look.update`
  (`WebsiteLookController`). Choosing a design never touches an answer. After the site is built the layout can only change
  through the rebuild flow, so "Use this design" is disabled with an explanation.
- **Generate** - the sidebar, the bottom button (and the retry button in the failure alert) are the same form to the same
  `setup.generate` route with the same `answers_revision`; the shared progress script disables all of them once one is
  used. Rebuild/overwrite protections live in `generate()` and are untouched.

## Draft vs publish readiness

A draft can always be generated. Missing hero / booth / backdrop photos are shown as "needed to publish (not to
generate)" and never disable generation. The checklist comes from `GuidedReviewSnapshot::forResponse()['checklist']`
(same rules as `missing`, which is unchanged).

## Code

- `App\Library\Website\Setup\WebsiteReviewPresenter` - read-only presenter for the section rows.
- `wizard/steps/_review-*.blade.php` - page, look, sections (guided / generic), generate button, styles.
- Removed: `_look.blade.php`, `_guided-review.blade.php` (superseded); `_plan.blade.php` is now card-less and shown inside
  Website content.

## Limitations

- "Preview" opens the real preview (`website.template-preview`) in a new tab, not an in-page modal.
- A "primary website button text" field does not exist in the backend; the booking action is edited through its
  questionnaire step (Contact & other).
- Package photos are optional and not shown; only booths and backdrops carry photos.
