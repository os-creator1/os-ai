<?php

    namespace App\Jobs;

    use App\Library\Traits\Trackable;
    use App\Models\ContactGroups;
    use App\Models\ContactsCustomField;
    use Illuminate\Contracts\Queue\ShouldQueue;

    class ReplicateContacts implements ShouldQueue
    {
        use Trackable;

        public int    $timeout = 7200;
        protected     $group_id;
        protected     $contact;
        protected int $total;

        /**
         * Delete the job if its models no longer exist.
         *
         * @var bool
         */
        public bool $deleteWhenMissingModels = true;

        /**
         * Create a new job instance.
         *
         * @param $group_id
         * @param $contact
         * @param $total
         */
        public function __construct($group_id, $contact, $total)
        {
            $this->group_id = $group_id;
            $this->contact  = $contact;
            $this->total    = $total;

            $this->afterDispatched(function ($thisJob, $monitor) {
                $monitor->setJsonData([
                    'percentage' => 0,
                    'total'      => 0,
                    'processed'  => 0,
                    'failed'     => 0,
                    'message'    => __('locale.contacts.import_being_queued_for_processing'),
                    'logfile'    => null,
                ]);
            });
        }

        /**
         * Execute the job.
         *
         * @return void
         */
        public function handle(): void
        {

            $processed = 0;
            $failed    = 0;
            $total     = $this->total;

            foreach ($this->contact as $contact) {
                $new_contact             = $contact->replicate();
                $new_contact->uid        = uniqid();
                $new_contact->group_id   = $this->group_id;
                $new_contact->created_at = now()->toDateTimeString();
                $new_contact->updated_at = now()->toDateTimeString();

                $new_contact->save();

                if ($contact->value) {
                    ContactsCustomField::create([
                        'contact_id' => $new_contact->id,
                        'field_id'   => $contact->field_id,
                        'value'      => $contact->value,
                    ]);
                }

                $processed++;
            }

            ContactGroups::where('id', $this->group_id)->first()->updateCache();

            $percentage = ($total && $processed) ? (int) ($processed * 100 / $total) : 0;

            $this->monitor->updateJsonData([
                'percentage' => $percentage,
                'total'      => $total,
                'processed'  => $processed,
                'failed'     => $failed,
                'message'    => sprintf('Processed: %s/%s, Skipped: %s', $processed, $total, $failed),
            ]);

        }

    }
