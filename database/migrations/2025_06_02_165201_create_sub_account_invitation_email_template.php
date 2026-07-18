<?php

    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {

            $checkExisting = DB::table('email_templates')->where('slug', 'subaccount_invitation_notification')->first();
            if ( ! $checkExisting) {
                DB::table('email_templates')->insert(
                    [
                        'uid'        => uniqid(),
                        'name'       => 'Subaccount Invitation Notification',
                        'slug'       => 'subaccount_invitation_notification',
                        'subject'    => 'You are invited to join as a Sub-Account on {app_name}',
                        'content'    => 'Hi {first_name} {last_name},<br><br>You have been invited to join as a sub-account. Please click the button below to accept the invitation and set your password:<br><br>{invitation_link}<br><br>If you didn’t expect this invitation, you can safely ignore this email.<br><br>',
                        'status'     => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

            }
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {

        }

    };
