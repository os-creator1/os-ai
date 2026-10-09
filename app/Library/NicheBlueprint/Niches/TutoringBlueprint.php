<?php

namespace App\Library\NicheBlueprint\Niches;

use App\Library\NicheBlueprint\Adapters\AcquisitionPurposeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BookingTypeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;

/**
 * The "Tutoring & Exam Preparation" niche Blueprint.
 *
 * TWO funnels, kept apart end to end (contract: tutoring niche):
 *
 *   Student Enrollment   pipeline + Student Inquiry form + economics for recurring lessons
 *   Teacher Recruitment  pipeline + Teacher Application form + recruitment economics
 *
 * CONFIGURATION ONLY. This file holds the funnel SHAPE, the wording of the
 * questions the Business is asked, strategy guidance and website intent. It
 * contains NO price, cost, rate or target: every such number is a Business
 * answer (acquisition_purposes.economics), edited under Ads > Goals, and every
 * formula is code (App\Library\Acquisition\Economics).
 *
 * Teacher recruitment exists ONLY here; the ceramics Blueprint has no teacher
 * funnel.
 */
final class TutoringBlueprint
{
    public const KEY = 'tutoring_exam_prep';

    public const NAME = 'Tutoring & Exam Preparation';

    public const BROAD_INDUSTRY = 'tutoring_education';

    public const STUDENT_PIPELINE = 'tutoring_student_pipeline';

    public const TEACHER_PIPELINE = 'tutoring_teacher_pipeline';

    public const STUDENT_FORM = 'tutoring_form_student_inquiry';

    public const TEACHER_FORM = 'tutoring_form_teacher_application';

    /**
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function components(): array
    {
        return [
            self::c('tutoring_tags', TagSetComponentAdapter::TYPE, 'crm', [
                'tags' => ['Parent', 'Student', 'Teacher applicant', 'Trial lesson', 'Exam preparation', 'Review requested'],
            ]),
            self::c('tutoring_cf_student_grade', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Student grade', 'type' => 'text']),
            self::c('tutoring_cf_subjects_taught', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Subjects taught', 'type' => 'text']),

            // Canonical CRM semantics: the first stage is `new_inquiry`; "enrolled" and "hired"
            // are the Opportunity's WON status and "lost"/"rejected" its LOST status, exactly as
            // every other pipeline — they are not extra stages.
            self::c(self::STUDENT_PIPELINE, CrmPipelineComponentAdapter::TYPE, 'crm', [
                'template_key' => 'blueprint_tutoring',
                'template_version' => 1,
                'pipeline_key' => 'student_enrollment',
                'name' => 'Student Enrollment',
                'stages' => [
                    ['name' => 'New inquiry', 'semantic_key' => 'new_inquiry'],
                    ['name' => 'Contacted', 'semantic_key' => 'contacted'],
                    ['name' => 'Consultation or trial booked', 'semantic_key' => 'trial_booked'],
                    ['name' => 'Consultation or trial attended', 'semantic_key' => 'trial_attended'],
                ],
            ]),
            self::c(self::TEACHER_PIPELINE, CrmPipelineComponentAdapter::TYPE, 'crm', [
                'template_key' => 'blueprint_tutoring',
                'template_version' => 1,
                'pipeline_key' => 'teacher_recruitment',
                'name' => 'Teacher Recruitment',
                'stages' => [
                    ['name' => 'New applicant', 'semantic_key' => 'new_inquiry'],
                    ['name' => 'Screened', 'semantic_key' => 'screened'],
                    ['name' => 'Interview booked', 'semantic_key' => 'interview_booked'],
                    ['name' => 'Interviewed', 'semantic_key' => 'interviewed'],
                    ['name' => 'Trial lesson or assessment', 'semantic_key' => 'trial_assessment'],
                ],
            ]),

            self::c(self::STUDENT_FORM, FormComponentAdapter::TYPE, 'forms', [
                'name' => 'Student inquiry',
                'intro' => 'Tell us about the student and what they need help with, and we will get back to you about the right lessons.',
                'submit_label' => 'Send inquiry',
                'success_message' => 'Thank you! We will contact you shortly.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Parent or student name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ['key' => 'student_grade', 'label' => "Student's grade", 'type' => 'text', 'required' => true, 'custom_field_label' => 'Student grade'],
                    ['key' => 'subject', 'label' => 'Subject', 'type' => 'select', 'required' => true,
                        'options' => ['Mathematics', 'Native language', 'English', 'Sciences', 'Exam preparation', 'Other']],
                    ['key' => 'goal', 'label' => 'Main challenge or goal', 'type' => 'textarea', 'required' => false],
                    ['key' => 'preferred_contact', 'label' => 'Preferred way to be contacted', 'type' => 'select', 'required' => false,
                        'options' => ['Phone call', 'Message', 'Email']],
                    ['key' => 'format', 'label' => 'Lessons in person or online', 'type' => 'select', 'required' => false,
                        'options' => ['In person', 'Online', 'Either']],
                ],
                'design' => ['accent' => '#2563eb', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => self::STUDENT_PIPELINE,
            ]),
            self::c(self::TEACHER_FORM, FormComponentAdapter::TYPE, 'forms', [
                'name' => 'Teacher application',
                'intro' => 'Tell us about your teaching background and we will contact you about the next step.',
                'submit_label' => 'Send application',
                'success_message' => 'Thank you for applying! We will be in touch.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['key' => 'subjects_taught', 'label' => 'Subjects you teach', 'type' => 'text', 'required' => true, 'custom_field_label' => 'Subjects taught'],
                    ['key' => 'qualification', 'label' => 'Education or qualification summary', 'type' => 'textarea', 'required' => true],
                    ['key' => 'experience', 'label' => 'Teaching experience', 'type' => 'select', 'required' => true,
                        'options' => ['Less than 1 year', '1 to 3 years', '3 to 5 years', 'More than 5 years']],
                    ['key' => 'availability', 'label' => 'Availability', 'type' => 'textarea', 'required' => false],
                    ['key' => 'format', 'label' => 'Preferred format', 'type' => 'select', 'required' => false,
                        'options' => ['In person', 'Online', 'Either']],
                    ['key' => 'motivation', 'label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                ],
                'design' => ['accent' => '#2563eb', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => self::TEACHER_PIPELINE,
            ]),

            self::c('tutoring_booking_consultation', BookingTypeComponentAdapter::TYPE, 'calendar', [
                'name' => 'Free consultation',
                'description' => 'A short call or visit to understand the student and recommend lessons.',
                'duration_minutes' => 30,
                'minimum_notice_minutes' => 240,
                'buffer_after_minutes' => 10,
                'slot_interval_minutes' => 30,
                'booking_window_days' => 30,
            ]),

            self::c('tutoring_purpose_student', AcquisitionPurposeComponentAdapter::TYPE, 'crm', [
                'purpose_key' => 'student_enrollment',
                'name' => 'Student Enrollment',
                'outcome_type' => 'student',
                'calculator' => 'recurring_lessons',
                'sort_order' => 1,
                'pipeline_component_key' => self::STUDENT_PIPELINE,
                'form_component_key' => self::STUDENT_FORM,
                'labels' => [
                    'person' => 'student',
                    'lead' => 'qualified student inquiry',
                    'leads' => 'qualified student inquiries',
                    'outcome' => 'enrolled student',
                    'outcomes' => 'enrolled students',
                    'cost_per_lead' => 'Cost / qualified lead',
                    'cost_per_outcome' => 'Student CAC',
                    'pipeline_cta' => 'Open Student Enrollment pipeline',
                ],
                'guidance' => [
                    ['title' => 'Start where parents already look', 'body' => 'Meta (Facebook and Instagram) is a reasonable first channel for student enrolment. It is a starting default, not a rule: let your own results decide.'],
                    ['title' => 'Do not guess the audience', 'body' => 'Start with a broad local adult audience and let parent-focused creative do the selecting, rather than assuming one age or gender. If you want to test, run two audiences with the same creative: broad adults in the area you serve, and parent or education interests where the platform offers them. Judge them by qualified students, not clicks.'],
                    ['title' => 'Study competitors in the Meta Ad Library', 'body' => 'Look at tutoring businesses and education providers near you. An ad that has run for a long time is a clue that someone keeps paying for it, not proof it is profitable. Do not copy their words; borrow the structure: problem, promise, evidence, offer, call to action, using your own claims and your own real results.'],
                    ['title' => 'Keep teacher hiring separate', 'body' => 'Teacher recruitment is a different goal with a different audience. Never mix its campaigns or results with student enrolment.'],
                ],
                'website_intent' => [
                    'audience' => 'Parents and students',
                    'cta' => 'Student inquiry form',
                    'pages' => [
                        ['page_key' => 'student-enrollment', 'title' => 'Lessons and exam preparation', 'summary' => 'A dedicated page for parents and students whose call to action is the Student inquiry form.'],
                    ],
                    'emphasis' => [
                        'Subjects and grades you teach',
                        'Exam preparation',
                        'Small groups or individual attention (only if true)',
                        'Teacher quality and how lessons work',
                        'Outcomes without unsupported promises; real testimonials only',
                    ],
                    'content_prompts' => [
                        'Describe the subjects, grades and exam preparation you offer, and how a first lesson works.',
                        'Explain who the teachers are and how you choose them, without promising specific grades.',
                    ],
                ],
            ]),
            self::c('tutoring_purpose_teacher', AcquisitionPurposeComponentAdapter::TYPE, 'crm', [
                'purpose_key' => 'teacher_recruitment',
                'name' => 'Teacher Recruitment',
                'outcome_type' => 'hire',
                'calculator' => 'recruitment',
                'sort_order' => 2,
                'pipeline_component_key' => self::TEACHER_PIPELINE,
                'form_component_key' => self::TEACHER_FORM,
                'labels' => [
                    'person' => 'teacher',
                    'lead' => 'qualified applicant',
                    'leads' => 'qualified applicants',
                    'outcome' => 'hired teacher',
                    'outcomes' => 'hired teachers',
                    'cost_per_lead' => 'Cost / qualified applicant',
                    'cost_per_outcome' => 'Cost / hire',
                    'pipeline_cta' => 'Review teacher applicants',
                ],
                'guidance' => [
                    ['title' => 'Speak to teachers, not parents', 'body' => 'Recruitment ads and pages should address people who teach: the subjects you need, the schedule, whether lessons are remote or in person, and what you expect from them.'],
                    ['title' => 'A separate campaign for a separate audience', 'body' => 'Run teacher recruitment as its own campaign with its own goal so its cost per qualified applicant and cost per hire are never averaged with student results.'],
                ],
                'website_intent' => [
                    'audience' => 'Teachers and tutors',
                    'cta' => 'Teacher application form',
                    'pages' => [
                        ['page_key' => 'teach-with-us', 'title' => 'Teach with us', 'summary' => 'A dedicated recruitment page whose call to action is the Teacher application form.'],
                    ],
                    'emphasis' => [
                        'Subjects you need teachers for',
                        'Who you are looking for and the qualifications you expect',
                        'Schedule and flexibility; remote or in person',
                        'What teaching with you is like',
                    ],
                    'content_prompts' => [
                        'Say which subjects you are hiring for, what you expect from a teacher and how the application works.',
                    ],
                ],
            ]),

            self::c('tutoring_seo', SeoStrategyComponentAdapter::TYPE, 'seo_module', [
                'keyword_patterns' => [
                    ['pattern' => 'tutoring {city}', 'intent' => 'transactional'],
                    ['pattern' => 'math tutor {city}', 'intent' => 'transactional'],
                    ['pattern' => 'exam preparation classes {city}', 'intent' => 'transactional'],
                    ['pattern' => 'private tutor near me', 'intent' => 'local'],
                    ['pattern' => 'online tutoring', 'intent' => 'commercial'],
                    ['pattern' => 'become a tutor {city}', 'intent' => 'commercial'],
                ],
                'faq_topics' => ['How do lessons work?', 'How do you choose your teachers?', 'Do you offer a trial lesson?', 'Are lessons in person or online?', 'How do you prepare students for exams?'],
                'schema_types' => ['LocalBusiness', 'FAQPage'],
                'schema_notes' => 'LocalBusiness on the home page; FAQPage on the lessons page. Do not mark up reviews you cannot show.',
                'internal_links' => [
                    ['from' => 'home', 'to' => 'student-enrollment', 'anchor' => 'lessons and exam preparation'],
                    ['from' => 'home', 'to' => 'teach-with-us', 'anchor' => 'teach with us'],
                ],
            ]),
        ];
    }

    public static function positionFor(string $type, int $ordinal): int
    {
        return NicheBlueprintPositions::for($type, $ordinal);
    }

    /** @return array{key: string, type: string, feature: string, payload: array<string, mixed>} */
    private static function c(string $key, string $type, string $feature, array $payload): array
    {
        return ['key' => $key, 'type' => $type, 'feature' => $feature, 'payload' => $payload];
    }
}
