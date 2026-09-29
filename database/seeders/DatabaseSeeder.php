<?php

    namespace Database\Seeders;


    use Illuminate\Database\Seeder;

    class DatabaseSeeder extends Seeder
    {
        /**
         * Seed the application's database.
         *
         * @return void
         */
        public function run()
        {
            $this->call(AppConfigSeeder::class);
            $this->call(Countries::class);
            $this->call(LanguageSeeder::class);
            $this->call(UserSeeder::class);
            $this->call(CurrenciesSeeder::class);
            $this->call(EmailTemplateSeeder::class);
            $this->call(PaymentMethodsSeeder::class);
            $this->call(PlanSeeder::class);
            $this->call(SenderIdPlanSeeder::class);
            $this->call(PlatformThemePresetSeeder::class);
            // Website Generator + Local SEO Completion — normal deployment
            // path installation (correction: these must not depend on an
            // operator knowing to run two hidden class-specific seeders).
            // Both seeders are idempotent (updateOrCreate), matching every
            // other catalog seeder in this list.
            $this->call(WebsiteTemplateSeeder::class);
            $this->call(QuestionPackSeeder::class);
            //  $this->call(BlacklistSeeder::class);
            //  $this->call(KeywordsSeeder::class);
            //  $this->call(PhoneNumberSeeder::class);
            //  $this->call(SenderIDSeeder::class);
            //  $this->call(SendingServerSeeder::class);
            //  $this->call(SpamWordSeeder::class);


        }

    }
