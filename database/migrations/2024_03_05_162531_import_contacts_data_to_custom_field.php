<?php

    use App\Models\Campaigns;
    use App\Models\Contacts;
    use App\Models\ContactsCustomField;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
//            Contacts::with('contactGroupFields')->chunk(1000, function ($contacts) {
//                $insertData = [];
//                foreach ($contacts as $contact) {
//                    $customFields = [
//                        'phone'            => $contact->phone,
//                        'first_name'       => $contact->first_name,
//                        'last_name'        => $contact->last_name,
//                        'email'            => $contact->email,
//                        'username'         => $contact->username,
//                        'company'          => $contact->company,
//                        'address'          => $contact->address,
//                        'birth_date'       => $contact->birth_date,
//                        'anniversary_date' => $contact->anniversary_date,
//                    ];
//
//                    foreach ($contact->contactGroupFields as $field) {
//                        $fieldName = strtolower($field->tag);
//                        if ($customFields[$fieldName] !== null) {
//                            $insertData[] = [
//                                'contact_id' => $contact->id,
//                                'field_id'   => $field->id,
//                                'value'      => $customFields[$fieldName] ?? null,
//                                'created_at' => now(),
//                                'updated_at' => now(),
//                            ];
//                        }
//                    }
//                }
//
//                ContactsCustomField::insert($insertData);
//            });

            Campaigns::query()->update(['status' => 'done']);

            DB::table('reports')
                ->where('status', 'LIKE', '%delivered%')
                ->update(['customer_status' => 'Delivered']);
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            // Rollback logic if needed
        }

    };
