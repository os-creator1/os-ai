# Website creation flow

Branch `agent/website-creation-flow-v1-fix`. Behaviour note for the Website
entry journey; the wizard, generation pipeline and Studio themselves are
unchanged (see `WEBSITE-GUIDED-GENERATION-CONTRACT.md`).

## The rule

**A `websites` row is not a created website.** The wizard deliberately creates
a shell row when setup starts (with the niche's default template — since Website V1
final there is no up-front style question; the look is chosen on the Review
screen, see `WEBSITE-TEMPLATES-V1.md`), long before any question is answered or any
page generated, and a blank/legacy shell can exist with nothing at all. Before
this change every entry point treated "a Website row exists" as "created" and
dropped the owner into Website Studio / the Pages checklist with 0 pages.

`App\Library\Website\Setup\WebsiteCreationStateResolver` is now the single
authority. It derives the stage purely from existing rows (no new table,
column or state machine):

| Stage | Derived from | Website entry lands on |
| --- | --- | --- |
| `InProgress` | an `in_progress` response (fresh setup or reopened edit) not at its final question | the saved question |
| `ReadyToGenerate` | an `in_progress` first-time response whose answers are complete and sits on its final question; **or** a `completed` response whose pages no longer exist | `setup.review` (review / generate / retry) |
| `Generated` | the Website has pages or a published revision | Website Studio |
| `NotStarted` | everything else, including a shell with 0 pages | the "Create my website" landing |

Active sessions are checked first, so "Edit setup answers" on a generated site
still resumes the wizard exactly as before.

## Click path

Sidebar **Website** -> landing "Create my website" -> choose style ->
one question per screen (progress, Back, Continue, autosave) -> **Review your
answers** (grouped summary, **Back and edit**, **Generate my website**) -> draft
preview -> Website entry now opens Studio.

## Generation failure / AI unavailable

* The review screen says so up front when AI is switched off for the
  environment, and a refusal produces "Website generation isn't available in
  this environment right now. Your answers are saved - try again once it is."
* A failed attempt never marks the response `completed`, never creates pages,
  and never enters Studio. The review screen offers **Try again**.
* Retrying after every page was deleted works: a succeeded attempt only
  short-circuits a new request while its pages still exist.

## Where the pre-generation controls went

Preview, Publish, Connect a domain, History, Manage pages, Edit setup answers
and the rebuild action live in Studio, which is only reachable once pages
exist. `pages.index` and `preview` with 0 pages redirect to the Website entry;
`publish` with 0 pages is refused with a plain message (and a `website`
validation error). The rebuild button is labelled **Rebuild from setup
answers**; Publish is hidden when there are 0 pages.

## Tests

`tests/Feature/Website/WebsiteWizardControllerTest.php` (entry-state
regressions at the end of the file) and `WebsiteTenancyTest.php` (fixtures now
give the website a home page, since a 0-page shell is no longer Studio).
