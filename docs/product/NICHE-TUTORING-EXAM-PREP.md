# Niche Blueprint — Tutoring & Exam Preparation

Key `tutoring_exam_prep`, broad industry `tutoring_education`. Seeded with `php artisan blueprint:seed-niche tutoring_exam_prep`
(idempotent; goes through the one `NicheBlueprintPublisher`/Workspace authoring path – there is no second niche framework).
Definition: `App\Library\NicheBlueprint\Niches\TutoringBlueprint`. Contracts: 25 (goals, economics, Ads decisions), 26 (external website).

**Two funnels, never mixed:** Student Enrollment and Teacher Recruitment. Teacher recruitment exists **only** in this Blueprint.

## Components

| Key | Type | What it installs for a Business |
|---|---|---|
| `tutoring_student_pipeline` | `crm_pipeline` | **Student Enrollment**: New inquiry (`new_inquiry`) → Contacted → Consultation or trial booked → Consultation or trial attended. |
| `tutoring_teacher_pipeline` | `crm_pipeline` | **Teacher Recruitment**: New applicant (`new_inquiry`) → Screened → Interview booked → Interviewed → Trial lesson or assessment. |
| `tutoring_form_student_inquiry` | `form` | Student inquiry (parent/student name, phone, email, student's grade, subject, main challenge, preferred contact, in person/online). `create_opportunity` into **Student Enrollment**. Installed as a draft. |
| `tutoring_form_teacher_application` | `form` | Teacher application (name, phone, email, subjects taught, qualification summary, experience, availability, preferred format, motivation). `create_opportunity` into **Teacher Recruitment**. Installed as a draft. |
| `tutoring_purpose_student` / `_teacher` | `acquisition_purpose` | The two goals (contract 25): pipeline + form references, economics questions, strategy guidance, website intent. |
| `tutoring_tags`, `tutoring_cf_*` | tags / custom fields | Parent, Student, Teacher applicant, Trial lesson…; "Student grade", "Subjects taught" (mapped from the forms). |
| `tutoring_booking_consultation` | `booking_type` | Free consultation (inactive on install; needs a Location). |
| `tutoring_seo` | `seo_strategy` | Keyword patterns (`tutoring {city}`, `math tutor {city}`, `exam preparation classes {city}`, `private tutor near me`, `online tutoring`, `become a tutor {city}`), FAQ topics, schema types, internal links to the student and teacher pages. |

**CRM semantics.** The only canonical stage vocabulary is `new_inquiry` (first stage). *Enrolled* and *Hired* are the Opportunity's **won** status and
*Lost* / *Rejected* its **lost** status – exactly as every other pipeline – so they are not extra stages.
**Forms** reuse the existing `FormManager`: a new optional `pipeline_component_key` on the Form component resolves the pipeline this same Blueprint
installed (through the installation records) and passes it as the existing `opportunity_pipeline_id`. Nothing in Forms was copied or changed; a form whose pipeline
did not install simply routes to the first pipeline like a hand-made form.

## Student Enrollment goal (calculator `recurring_lessons`)

Questions: average price per paid lesson · average direct cost of one lesson (teacher pay and directly variable costs) · typical/median paid lessons per student ·
approximate % of qualified inquiries that become paying students · target student cost (CAC) · hard maximum CAC · target qualified-lead cost (CPL) · hard maximum CPL.
Formulas (code): `contribution_per_lesson = price − cost`; `student_contribution_ltv = contribution_per_lesson × median_paid_lessons`; a CAC / CPL
suggestion (30% / 50% of life contribution, and the CPL it implies at the owner's rate) is labelled a suggestion and never used until the owner enters a target.
Every question accepts **"I don't know yet"**. No price, cost, rate or target is stored in the Blueprint.

Labels: qualified student inquiries · enrolled students · **Student CAC** · *Open Student Enrollment pipeline*.

## Teacher Recruitment goal (calculator `recruitment`)

Questions: teachers currently needed · subjects needed (context only) · target / hard cost per qualified applicant · target / hard cost per hired teacher ·
application→screened, screened→interview, interview→hire rates (each optional). The Business outcome is a **hired teacher**, not a lead. No revenue ⇒ no LTV ⇒
**no profitability claim, ever**. Labels: qualified applicants · hired teachers · **Cost / hire** · extra KPI **Interviews** (stage `interviewed`) · *Review teacher applicants*.
Student and teacher answers, targets, campaigns and decisions are isolated (tested).

## Website intent

For a **MotionGrove-hosted** site the goals' intents guide generation (`WebsiteBlueprintDefaults::generationHints`), one audience at a time:
a dedicated **student** page (subjects, grades, exam preparation, small groups / individual attention *if true*, teacher quality, how lessons work, outcomes without
unsupported promises, real testimonials only; CTA = Student inquiry form) and a dedicated **Teach with us** page (subjects needed, who you are looking for,
schedule/flexibility, remote or in person, qualification expectations; CTA = Teacher application form).
For an **external** site the same intents become *acquisition recommendations* (contract 26 §2.1) and each goal's destination URL is stored separately.
*Known limit:* the hosted generator's template/questionnaire set is still Photo Booth-specific, so these intents influence content prompts, not a dedicated tutoring template;
a tutoring template + questionnaire is future work.

## Ads strategy defaults (guidance, not provider settings)

* Student Enrollment: Meta-first is a reasonable default. **Do not** hard-code a demographic: start with a broad local adult audience and let parent-focused creative
  self-select; optionally run an experiment (A: broad adults in the area served, B: available parent/education targeting, same creatives) and judge by **qualified students**, not clicks.
* Teacher Recruitment: its **own** campaign and goal; creative speaks to teachers (e.g. teaching a subject to motivated secondary-school students; looking for tutors in named subjects).
* Competitor research: use the **Meta Ad Library** to study tutoring businesses and education providers. A long-running ad is a clue, **not proof of profitability**. Do not copy words;
  borrow the structure — problem → promise → evidence → offer → CTA — with the Business's own claims.

## Deferred

A tutoring-specific hosted website template and questionnaire; Content Autopilot topics for tutoring; automations (the existing form-submitted trigger would fire for
both forms, so none were shipped); campaign creation (contract 25 §10).
