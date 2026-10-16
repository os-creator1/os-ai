<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Forms\FormContactResolution;
use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Support\Str;

/**
 * Forms and Surveys. This product has one builder for both, so the lead form,
 * the planning questionnaire and the post-event survey are all Forms.
 * Submissions are recorded history only: they are inserted directly, so no
 * contact is matched, no opportunity created and no automation fired.
 */
final class FormsStage implements DemoStage
{
    public function __construct(private readonly FormManager $forms)
    {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $pipeline = $context->refs['pipeline'];

        $definitions = [
            'Get a Quote' => [
                'intro' => 'Tell us about your event and we will send a custom quote within one business day.',
                'submit_label' => 'Request my quote',
                'success_message' => 'Thank you! A BrightFrame planner will text or email you within one business day.',
                'create_opportunity' => true,
                'opportunity_pipeline_id' => $pipeline->id,
                'fields' => [
                    ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['label' => 'Email', 'type' => 'email', 'required' => true],
                    ['label' => 'Event type', 'type' => 'select', 'required' => true, 'options' => "Wedding\nCorporate event\nBirthday\nProm or school event\nBrand activation"],
                    ['label' => 'Event date', 'type' => 'date', 'required' => true],
                    ['label' => 'Approximate guest count', 'type' => 'number', 'required' => false],
                    ['label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                ],
            ],
            'Event Planning Questionnaire' => [
                'intro' => 'Help us tailor your booth: a few quick questions about your event.',
                'submit_label' => 'Send my details',
                'success_message' => 'Thanks! We will use this to design your photo template.',
                'create_opportunity' => false,
                'fields' => [
                    ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['label' => 'Venue name', 'type' => 'text', 'required' => true],
                    ['label' => 'Preferred booth', 'type' => 'radio', 'required' => true, 'options' => "Classic Digital Booth\nGlam Mirror Booth\n360 Video Booth\nNot sure yet"],
                    ['label' => 'Is there power within 15 feet of the booth area?', 'type' => 'yes_no', 'required' => true],
                    ['label' => 'Photo template colors or theme', 'type' => 'text', 'required' => false],
                    ['label' => 'Setup access time', 'type' => 'text', 'required' => false],
                ],
            ],
            'Post-Event Feedback Survey' => [
                'intro' => 'How did we do? Your feedback helps us improve every event.',
                'submit_label' => 'Submit feedback',
                'success_message' => 'Thank you for your feedback!',
                'create_opportunity' => false,
                'fields' => [
                    ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['label' => 'Overall experience', 'type' => 'radio', 'required' => true, 'options' => "5 - Loved it\n4 - Great\n3 - Good\n2 - Fair\n1 - Poor"],
                    ['label' => 'Was your attendant friendly and professional?', 'type' => 'yes_no', 'required' => true],
                    ['label' => 'Would you recommend BrightFrame to a friend?', 'type' => 'yes_no', 'required' => true],
                    ['label' => 'What was the highlight of your booth experience?', 'type' => 'textarea', 'required' => false],
                ],
            ],
        ];

        $created = 0;

        foreach ($definitions as $name => $input) {
            $form = Form::query()->where('business_id', $business->id)->where('name', $name)->first();

            if ($form === null) {
                $form = $this->forms->create($business, ['name' => $name] + $input, $context->user->id);
                $created++;
            }

            $this->forms->activate($business, $form);
            $deployments = [];
            foreach ([FormDeploymentSource::DirectLink, FormDeploymentSource::Website] as $source) {
                $deployments[$source->value] = $this->forms->setDeployment($business, $form, $context->location, true, $source);
            }

            $context->refs['forms'][$name] = $form->fresh();
            $context->refs['form_deployments'][$name] = $deployments;
        }

        $this->submissions($context, 'Get a Quote', [
            [0, 'Wedding', 18, 150], [1, 'Corporate event', 14, 150], [2, 'Wedding', 17, 180], [4, 'Wedding', 1, 120], [7, 'Birthday', 0, 30],
            [10, 'Birthday', 2, 90], [11, 'Prom or school event', 3, 60], [9, 'Brand activation', 7, 120], [5, 'Corporate event', 21, 220], [20, 'Wedding', 5, 140],
        ], fn (array $person, array $row, array $keys, DemoContext $c) => [
            $keys['Your name'] => $person['first'] . ' ' . $person['last'],
            $keys['Phone'] => '+' . $person['phone'],
            $keys['Email'] => $person['email'],
            $keys['Event type'] => $row[1],
            $keys['Event date'] => $c->daysFromNow(30 + $row[2] * 5)->toDateString(),
            $keys['Approximate guest count'] => $row[3],
            $keys['Anything else we should know?'] => 'Found you online and would love a quote.',
        ]);

        $ratings = ['5 - Loved it', '5 - Loved it', '4 - Great', '5 - Loved it', '4 - Great', '5 - Loved it', '3 - Good', '5 - Loved it'];
        $highlights = [
            'The Glam booth lit everyone perfectly. Guests lined up all night.', 'Our attendant kept the line moving and everyone laughing.',
            'Sharing photos by text instantly was a huge hit.', 'The custom template matched our wedding colors exactly.',
            'The 360 clips were the best part of our launch party.', 'So easy to book and right on time.',
            'Great booth, would love a few more props.', 'The online gallery was a lovely surprise.',
        ];
        $this->submissions($context, 'Post-Event Feedback Survey', array_map(fn ($i) => [12 + $i, $ratings[$i], 8 + $i * 3, $highlights[$i]], range(0, 7)),
            fn (array $person, array $row, array $keys) => [
                $keys['Your name'] => $person['first'] . ' ' . $person['last'],
                $keys['Overall experience'] => $row[1],
                $keys['Was your attendant friendly and professional?'] => 'yes',
                $keys['Would you recommend BrightFrame to a friend?'] => str_starts_with($row[1], '3') ? 'no' : 'yes',
                $keys['What was the highlight of your booth experience?'] => $row[3],
            ], leadSourceIndexes: true);

        $context->note('forms', Form::query()->where('business_id', $business->id)->count());
        $context->note('form_submissions', FormSubmission::query()->where('business_id', $business->id)->count());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  [contact index, ..., days ago, ...]
     */
    private function submissions(DemoContext $context, string $formName, array $rows, callable $values, bool $leadSourceIndexes = false): void
    {
        $form = $context->refs['forms'][$formName];
        $version = $form->currentVersion();
        $keys = [];
        foreach ($version->fields as $field) {
            $keys[$field['label']] = $field['key'];
        }

        $deployment = $context->refs['form_deployments'][$formName][$leadSourceIndexes ? FormDeploymentSource::DirectLink->value : FormDeploymentSource::Website->value];
        $contacts = array_values($context->refs['contacts']);
        $people = array_values($context->refs['people']);

        foreach ($rows as $n => $row) {
            $index = $row[0];
            $nonce = substr(md5('brightframe-demo|' . $form->id . '|' . $n), 0, 32);

            if (FormSubmission::query()->where('form_deployment_id', $deployment->id)->where('operation_nonce', $nonce)->exists()) {
                continue;
            }

            $payload = $values($people[$index], $row, $keys, $context);
            $uid = (string) Str::uuid();
            $daysAgo = $leadSourceIndexes ? $row[2] : $row[2] + 1;

            $submission = new FormSubmission([
                'uid' => $uid,
                'business_id' => $context->business->id,
                'business_location_id' => $context->location->id,
                'form_id' => $form->id,
                'form_version_id' => $version->id,
                'form_deployment_id' => $deployment->id,
                'source' => $deployment->source,
                'operation_nonce' => $nonce,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'values' => $payload,
                'contact_resolution' => FormContactResolution::Matched->value,
                'occurrence_key' => 'form_submission:' . $uid,
            ]);
            $submission->contact_id = $contacts[$index]->id;
            $at = $context->daysFromNow(-$daysAgo, 9 + ($n * 3) % 9, ($n * 17) % 60);
            $submission->created_at = $at;
            $submission->updated_at = $at;
            $submission->save();
        }
    }
}
