<?php

    use App\Models\ContactGroups;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            $contactGroups = ContactGroups::all();

            // Update the customer_status for each group
            $contactGroups->each(function ($group) {
                // Assuming createDefaultFields is a method in your ContactGroups model
                $group->createUpdateFields();
                $group->updateCache();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            //
        }

    };
