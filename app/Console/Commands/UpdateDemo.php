<?php

    namespace App\Console\Commands;

    use App\Library\Tool;
    use App\Models\Announcements;
    use App\Models\AppConfig;
    use App\Models\Automation;
    use App\Models\Blacklists;
    use App\Models\BlockSenderId;
    use App\Models\Campaigns;
    use App\Models\CampaignsList;
    use App\Models\CampaignsSenderid;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\Currency;
    use App\Models\Customer;
    use App\Models\Invoices;
    use App\Models\Keywords;
    use App\Models\Notifications;
    use App\Models\PhoneNumbers;
    use App\Models\Plan;
    use App\Models\PlansCoverageCountries;
    use App\Models\PlanSendingCreditPrice;
    use App\Models\Plugins;
    use App\Models\Reports;
    use App\Models\Role;
    use App\Models\Senderid;
    use App\Models\SenderidPlan;
    use App\Models\SendingServer;
    use App\Models\SpamWord;
    use App\Models\Subscription;
    use App\Models\SubscriptionLog;
    use App\Models\SubscriptionTransaction;
    use App\Models\Templates;
    use App\Models\TrackingLog;
    use App\Models\User;
    use App\Repositories\Eloquent\EloquentSendingServerRepository;
    use Carbon\Carbon;
    use Codeglen\Usupport\Models\SupportAgent;
    use Codeglen\Usupport\Models\SupportArticle;
    use Codeglen\Usupport\Models\SupportCategory;
    use Codeglen\Usupport\Models\SupportTicket;
    use Codeglen\Usupport\Models\TicketAttachment;
    use Codeglen\Usupport\Models\TicketReply;
    use Codeglen\Usupport\Models\TicketTags;
    use Database\Seeders\ChatBoxSeeder;
    use Database\Seeders\SupportCategorySeeder;
    use Faker\Factory;
    use Faker\Factory as Faker;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;

    class UpdateDemo extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'demo:update';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Update Demo Database in every day';

        /**
         * Create a new command instance.
         *
         * @return void
         */
        public function __construct()
        {
            parent::__construct();
        }

        /**
         * Execute the console command.
         */
        public function handle(): void
        {

            AppConfig::where('setting', 'customer_permissions')->update([
                'value' => json_encode(
                    [
                        'access_backend',
                        'view_reports',
                        'automations',
                        'view_contact_group',
                        'create_contact_group',
                        'update_contact_group',
                        'delete_contact_group',
                        'view_contact',
                        'create_contact',
                        'update_contact',
                        'delete_contact',
                        'view_numbers',
                        'buy_numbers',
                        'buy_numbers_using_api',
                        'release_numbers',
                        'view_keywords',
                        'create_keywords',
                        'buy_keywords',
                        'update_keywords',
                        'release_keywords',
                        'view_sender_id',
                        'create_sender_id',
                        'delete_sender_id',
                        'view_blacklist',
                        'create_blacklist',
                        'delete_blacklist',
                        'sms_campaign_builder',
                        'sms_quick_send',
                        'sms_bulk_messages',
                        'voice_campaign_builder',
                        'voice_quick_send',
                        'voice_bulk_messages',
                        'mms_campaign_builder',
                        'mms_quick_send',
                        'mms_bulk_messages',
                        'whatsapp_campaign_builder',
                        'whatsapp_quick_send',
                        'whatsapp_bulk_messages',
                        'viber_campaign_builder',
                        'viber_quick_send',
                        'viber_bulk_messages',
                        'otp_campaign_builder',
                        'otp_quick_send',
                        'otp_bulk_messages',
                        'sms_template',
                        'chat_box',
                        'developers',
                    ]
                ),
            ]);

            if (config('app.env') == 'local') {
                AppConfig::where('setting', 'license')->update([
                    'value' => Str::uuid(),
                ]);
            }

            AppConfig::setEnv('PRIVACY_POLICY', true);
            AppConfig::setEnv('TERMS_OF_USE', true);

            AppConfig::updateOrCreate(
                ['setting' => 'privacy_policy'],
                ['value' => '
<h1>Privacy Policy – Ultimate SMS</h1>
<p><strong>Effective Date:</strong> 10-08-25</p>
<p><strong>Last Updated:</strong> 10-08-25</p>

<p>Ultimate SMS (“we,” “our,” “us”) provides a cloud-based messaging platform to help businesses communicate with their customers via SMS, MMS, and other channels. We are committed to protecting the privacy of our users and complying with applicable data protection laws, including the General Data Protection Regulation (GDPR), the California Consumer Privacy Act (CCPA), and relevant telecommunications and anti-spam regulations.</p>

<p>By using our Services, you agree to the practices described in this Privacy Policy.</p>

<h2>1. Information We Collect</h2>

<h3>a. Account & Billing Information</h3>
<p>Name, email address, phone number, company name, billing address, and payment details.</p>

<h3>b. Messaging Data</h3>
<p>SMS/MMS content, recipient phone numbers, delivery reports, and timestamps. Contact lists you upload to send messages.</p>

<h3>c. Technical & Usage Data</h3>
<p>IP addresses, browser type, operating system, access logs, and usage metrics.</p>

<h3>d. Consent & Compliance Data</h3>
<p>Opt-in/opt-out preferences of message recipients, including unsubscribe requests, as required by law and carrier policies.</p>


<h2>2. Legal Basis for Processing (GDPR)</h2>
<ul>
  <li>Contractual necessity – to provide you with the Services you requested.</li>
  <li>Legitimate interests – to improve our platform, prevent fraud, and ensure security.</li>
  <li>Consent – for marketing communications where required.</li>
  <li>Legal obligation – to comply with applicable laws.</li>
</ul>


<h2>3. How We Use Your Information</h2>
<ul>
  <li>Deliver SMS/MMS messages and process delivery reports.</li>
  <li>Operate, maintain, and improve our Services.</li>
  <li>Process payments and manage subscriptions.</li>
  <li>Provide customer support and account management.</li>
  <li>Ensure compliance with anti-spam, carrier, and telecommunications regulations.</li>
  <li>Detect, prevent, and address fraud or security issues.</li>
  <li>Send important updates, security alerts, and service notifications.</li>
</ul>


<h2>4. How We Share Your Information</h2>
<ul>
  <li>SMS Gateway Providers – to route and deliver your messages.</li>
  <li>Payment Processors – to process transactions securely.</li>
  <li>Cloud Hosting & Security Providers – to store and protect your data.</li>
  <li>Regulatory Bodies – if required by law or lawful request.</li>
  <li>Business Partners – only with your consent or during a business acquisition/merger.</li>
</ul>
<p>We do not sell personal data to third parties.</p>


<h2>5. Data Retention</h2>
<p>Message content and logs are stored only as long as necessary for operational, billing, or compliance purposes, and then securely deleted. Account and billing data are retained for legal and tax obligations. You may request deletion of your account and associated data (subject to legal requirements).</p>


<h2>6. Data Security</h2>
<p>We implement industry-standard security measures including encryption, firewalls, access controls, and secure data storage. While we take reasonable steps to protect your information, no method of transmission or storage is completely secure.</p>


<h2>7. Your Rights</h2>
<ul>
  <li>Access, correct, or delete your personal data.</li>
  <li>Request data portability.</li>
  <li>Restrict or object to processing.</li>
  <li>Opt out of marketing communications.</li>
  <li>For California residents (CCPA): request disclosure of collected personal information, request deletion, and opt out of data sales (we do not sell data).</li>
</ul>
<p>Requests can be submitted to: <strong>akasham67@gmail.com</strong></p>


<h2>8. SMS & Messaging Compliance</h2>
<ul>
  <li>You are responsible for obtaining proper consent before sending messages.</li>
  <li>We automatically process STOP/UNSUBSCRIBE keywords.</li>
  <li>You must comply with all laws (TCPA, CAN-SPAM, GDPR) and carrier rules.</li>
  <li>Sending unsolicited or unlawful messages may result in suspension or termination.</li>
</ul>


<h2>9. International Data Transfers</h2>
<p>If you are located outside your country, your data may be transferred to other jurisdictions that may not have the same data protection laws. We use GDPR-compliant safeguards (e.g., Standard Contractual Clauses).</p>


<h2>10. Cookies & Tracking Technologies</h2>
<p>We use cookies to authenticate users, store preferences, and analyze site usage. You can manage cookies through your browser settings.</p>


<h2>11. Changes to This Policy</h2>
<p>We may update this Privacy Policy periodically. Updated versions will be posted with a revised “Last Updated” date.</p>


<h2>12. Contact Us</h2>
<p>Email: <strong>akasham67@gmail.com</strong></p>
<p>Address: House no: 42, Block - J, Road No - 12, 3 South, Dhaka 1219</p>
<p>Data Protection Officer (if applicable): Codeglen & Email: <strong>akasham67@gmail.com</strong></p>
'],
                [
                    'setting' => 'terms_of_use',
                    'value'   => ['
<h1>Terms of Service – Ultimate SMS</h1>
<p><strong>Effective Date:</strong> 10-08-25</p>
<p><strong>Last Updated:</strong> 10-08-25</p>

<p>These Terms of Service (“Terms”) govern your use of Ultimate SMS (“we,” “our,” “us”) and our website, applications, and related services (collectively, the “Services”). By creating an account or using our Services, you agree to these Terms. If you do not agree, you must stop using the Services.</p>

<h2>1. Eligibility</h2>
<p>You must:</p>
<ul>
  <li>Be at least 18 years old or the legal age in your jurisdiction.</li>
  <li>Have the authority to enter into a binding agreement on behalf of yourself or your organization.</li>
  <li>Not be prohibited from using our Services under applicable laws.</li>
</ul>

<h2>2. Account Registration</h2>
<ul>
  <li>You must provide accurate, current, and complete information when registering.</li>
  <li>You are responsible for maintaining the confidentiality of your login credentials.</li>
  <li>You are responsible for all activities under your account.</li>
</ul>

<h2>3. Permitted Use</h2>
<p>You may use our Services only for lawful, authorized purposes, including:</p>
<ul>
  <li>Sending SMS/MMS messages to recipients who have opted in.</li>
  <li>Storing and managing your contact lists in compliance with data protection and anti-spam laws.</li>
</ul>

<h2>4. Prohibited Activities</h2>
<p>You may not:</p>
<ul>
  <li>Send unsolicited, spam, or fraudulent messages.</li>
  <li>Use our Services for illegal, abusive, harassing, or deceptive purposes.</li>
  <li>Infringe on intellectual property rights.</li>
  <li>Interfere with or disrupt our systems.</li>
  <li>Circumvent usage or account limits.</li>
</ul>
<p>Violation of these rules may result in immediate suspension or termination of your account.</p>

<h2>5. Compliance With Laws</h2>
<p>You agree to:</p>
<ul>
  <li>Comply with all applicable laws, including GDPR, CCPA, TCPA, CAN-SPAM, and local telecom regulations.</li>
  <li>Obtain valid consent from recipients before sending messages.</li>
  <li>Include clear opt-out instructions in your messages.</li>
  <li>Honor all opt-out requests promptly.</li>
</ul>

<h2>6. Fees &amp; Payment</h2>
<ul>
  <li>Fees for the Services are as described at the time of purchase.</li>
  <li>All charges are billed in advance and are non-refundable unless stated otherwise.</li>
  <li>We may suspend your account for overdue payments.</li>
</ul>

<h2>7. Message Delivery</h2>
<ul>
  <li>We route messages through third-party SMS gateway providers.</li>
  <li>We do not guarantee delivery times or successful message delivery.</li>
  <li>Delivery may depend on factors beyond our control, such as carrier network availability.</li>
</ul>

<h2>8. Data Privacy &amp; Security</h2>
<ul>
  <li>Your use of the Services is subject to our Privacy Policy.</li>
  <li>You retain ownership of your customer data; however, you grant us the right to process it for providing the Services.</li>
  <li>We use commercially reasonable security measures to protect your data.</li>
</ul>

<h2>9. Suspension &amp; Termination</h2>
<p>We may suspend or terminate your account if:</p>
<ul>
  <li>You violate these Terms.</li>
  <li>You use the Services in a way that causes legal risk or harm to others.</li>
  <li>We are required by law or regulation.</li>
</ul>
<p>Upon termination, your access to the Services will cease, but legal obligations and certain rights will survive.</p>

<h2>10. Limitation of Liability</h2>
<p>To the maximum extent permitted by law:</p>
<ul>
  <li>We are not liable for indirect, incidental, or consequential damages.</li>
  <li>Our total liability will not exceed the amount paid by you in the 12 months prior to the claim.</li>
</ul>

<h2>11. Indemnification</h2>
<p>You agree to indemnify and hold harmless Ultimate SMS, its affiliates, employees, and partners from any claims, damages, or liabilities arising from:</p>
<ul>
  <li>Your use of the Services.</li>
  <li>Your violation of these Terms.</li>
  <li>Your violation of any applicable law or third-party rights.</li>
</ul>

<h2>12. Changes to the Terms</h2>
<p>We may update these Terms from time to time. Continued use of the Services after changes means you accept the updated Terms.</p>

<h2>13. Governing Law</h2>
<p>These Terms are governed by and construed in accordance with the laws of [Insert Jurisdiction], without regard to conflict of law principles.</p>

<h2>14. Contact Information</h2>
<p>If you have any questions about these Terms:</p>
<p>Email: <strong>akasham67@gmail.com</strong></p>
<p>Address: House no: 42, Block - J, Road No - 12, 3 South, Dhaka 1219</p>
<p>Data Protection Officer (if applicable): Codeglen &amp; Email: <strong>akasham67@gmail.com</strong></p>
                    '],
                ]
            );

            $defaultPassword = '12345678';

            // Create super admin user
            $user     = new User();
            $role     = new Role();
            $customer = new Customer();

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $user->truncate();
            $role->truncate();
            $customer->truncate();
            DB::table('role_user')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            /*
         * Create roles
         */

            $superAdminRole = $role->create([
                'name'   => 'administrator',
                'status' => true,
            ]);

            foreach (config('permissions') as $key => $name) {
                $superAdminRole->permissions()->create(['name' => $key]);
            }

            $authorRole = $role->create([
                'name'   => 'author',
                'status' => true,
            ]);

            foreach (
                [

                    'access backend',
                    'view customer',
                    'create customer',
                    'edit customer',
                    'delete customer',
                    'view subscription',
                    'new subscription',
                    'manage subscription',
                    'delete subscription',
                    'manage plans',
                    'create plans',
                    'edit plans',
                    'delete plans',
                    'manage currencies',
                    'create currencies',
                    'edit currencies',
                    'delete currencies',
                    'view sending_servers',
                    'create sending_servers',
                    'edit sending_servers',
                    'delete sending_servers',
                    'view keywords',
                    'create keywords',
                    'edit keywords',
                    'delete keywords',
                    'view sender_id',
                    'create sender_id',
                    'edit sender_id',
                    'delete sender_id',
                    'view blacklist',
                    'create blacklist',
                    'edit blacklist',
                    'delete blacklist',
                    'view spam_word',
                    'create spam_word',
                    'edit spam_word',
                    'delete spam_word',
                    'view invoices',
                    'create invoices',
                    'edit invoices',
                    'delete invoices',
                    'view sms_history',
                    'view block_message',
                    'manage coverage_rates',
                ] as $name) {
                $authorRole->permissions()->create(['name' => $name]);
            }

            $superAdmin = $user->create([
                'first_name'        => 'Super',
                'last_name'         => 'Admin',
                'image'             => 'app/profile/avatar-1.jpg',
                'email'             => 'admin@codeglen.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => true,
                'is_customer'       => true,
                'sms_unit'          => '6000',
                'active_portal'     => 'admin',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
            ]);

            $superAdmin->customer()->create([
                'user_id'            => $user->id,
                'company'            => 'Codeglen',
                'phone'              => '8801721970168',
                'address'            => 'House # 01, Road # 01, Block # C, Gulshan 1',
                'city'               => 'Dhaka',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'postcode'           => '1216',
                'financial_address'  => 'House # 01, Road # 01, Block # C, Gulshan 1',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1216',
                'tax_number'         => '21-4330267',
                'website'            => config('app.url'),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'yes',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'yes',
                    'profile'      => 'yes',
                ]),
                'permissions'        => json_encode([
                    'access_backend',
                    'view_reports',
                    'automations',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'buy_numbers_using_api',
                    'release_numbers',
                    'view_keywords',
                    'create_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'delete_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'voice_campaign_builder',
                    'voice_quick_send',
                    'voice_bulk_messages',
                    'mms_campaign_builder',
                    'mms_quick_send',
                    'mms_bulk_messages',
                    'whatsapp_campaign_builder',
                    'whatsapp_quick_send',
                    'whatsapp_bulk_messages',
                    'viber_campaign_builder',
                    'viber_quick_send',
                    'viber_bulk_messages',
                    'otp_campaign_builder',
                    'otp_quick_send',
                    'otp_bulk_messages',
                    'sms_template',
                    'chat_box',
                    'developers',
                    'read_ticket',
                    'create_ticket',
                    'manage_ticket',
                    'delete_ticket',
                    'create_ticket_replies',
                    'update_ticket_replies',
                    'delete_ticket_replies',
                    'view_articles',
                ]),
            ]);

            $superAdmin->api_token = $superAdmin->createToken('admin@codeglen.com')->plainTextToken;
            $superAdmin->save();

            $superAdmin->roles()->save($superAdminRole);

            $supervisor = $user->create([
                'first_name'        => 'Shamim',
                'last_name'         => 'Rahman',
                'image'             => 'app/profile/avatar-3.JPG',
                'email'             => 'shamim97@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => true,
                'is_customer'       => false,
                'sms_unit'          => '6000',
                'active_portal'     => 'admin',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
            ]);

            $supervisor->api_token = $supervisor->createToken('shamim97@gmail.com')->plainTextToken;
            $supervisor->save();

            $supervisor->roles()->save($authorRole);

            $customers = $user->create([
                'first_name'        => 'Codeglen',
                'last_name'         => null,
                'image'             => 'app/profile/avatar-3.jpeg',
                'email'             => 'customer@codeglen.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'sms_unit'          => 6000,
                'is_admin'          => false,
                'is_customer'       => true,
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
            ]);

            $customers->api_token = $customers->createToken('customer@codeglen.com')->plainTextToken;
            $customers->save();

            $customer->create([
                'user_id'            => $customers->id,
                'company'            => 'Codeglen',
                'website'            => 'https://codeglen.com',
                'address'            => 'Banasree, Rampura',
                'city'               => 'Dhaka',
                'postcode'           => '1219',
                'financial_address'  => 'Banasree, Rampura',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1219',
                'tax_number'         => '21-4330267',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'phone'              => '8801621970168',
                'permissions'        => json_encode([
                    'access_backend',
                    'view_reports',
                    'automations',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'buy_numbers_using_api',
                    'release_numbers',
                    'view_keywords',
                    'create_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'delete_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'voice_campaign_builder',
                    'voice_quick_send',
                    'voice_bulk_messages',
                    'mms_campaign_builder',
                    'mms_quick_send',
                    'mms_bulk_messages',
                    'whatsapp_campaign_builder',
                    'whatsapp_quick_send',
                    'whatsapp_bulk_messages',
                    'viber_campaign_builder',
                    'viber_quick_send',
                    'viber_bulk_messages',
                    'otp_campaign_builder',
                    'otp_quick_send',
                    'otp_bulk_messages',
                    'sms_template',
                    'chat_box',
                    'developers',
                    'read_ticket',
                    'create_ticket',
                    'manage_ticket',
                    'delete_ticket',
                    'create_ticket_replies',
                    'update_ticket_replies',
                    'delete_ticket_replies',
                    'view_articles',
                ]),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'yes',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'yes',
                    'profile'      => 'yes',
                ]),
            ]);

            $customer_two = $user->create([
                'first_name'        => 'DLT',
                'last_name'         => 'User',
                'image'             => 'app/profile/avatar-4.jpg',
                'email'             => 'dlt@codeglen.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => false,
                'is_customer'       => true,
                'sms_unit'          => 5000,
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
                'created_at'        => Carbon::now()->subMonths(3),
                'updated_at'        => Carbon::now()->subMonths(3),
            ]);

            $customer_two->api_token = $customer_two->createToken('dlt@codeglen.com')->plainTextToken;
            $customer_two->save();

            $customer->create([
                'user_id'            => $customer_two->id,
                'company'            => 'Codeglen',
                'phone'              => '8801721970000',
                'address'            => 'House # 01, Road # 01, Block # C, Gulshan 1',
                'city'               => 'Dhaka',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'postcode'           => '1216',
                'financial_address'  => 'House # 01, Road # 01, Block # C, Gulshan 1',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1216',
                'tax_number'         => '21-4330267',
                'website'            => config('app.url'),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'yes',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'yes',
                    'profile'      => 'yes',
                ]),
                'permissions'        => json_encode([
                    'access_backend',
                    'view_reports',
                    'automations',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'buy_numbers_using_api',
                    'release_numbers',
                    'view_keywords',
                    'create_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'delete_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'voice_campaign_builder',
                    'voice_quick_send',
                    'voice_bulk_messages',
                    'mms_campaign_builder',
                    'mms_quick_send',
                    'mms_bulk_messages',
                    'whatsapp_campaign_builder',
                    'whatsapp_quick_send',
                    'whatsapp_bulk_messages',
                    'viber_campaign_builder',
                    'viber_quick_send',
                    'viber_bulk_messages',
                    'otp_campaign_builder',
                    'otp_quick_send',
                    'otp_bulk_messages',
                    'sms_template',
                    'chat_box',
                    'developers',
                    'read_ticket',
                    'create_ticket',
                    'manage_ticket',
                    'delete_ticket',
                    'create_ticket_replies',
                    'update_ticket_replies',
                    'delete_ticket_replies',
                    'view_articles',
                ]),
            ]);

            $customer_three = $user->create([
                'first_name'        => 'Abul Kashem',
                'last_name'         => 'Shamim',
                'image'             => 'app/profile/avatar-5.jpg',
                'email'             => 'kashem97@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => false,
                'sms_unit'          => '5',
                'is_customer'       => true,
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
                'created_at'        => Carbon::now()->subMonths(2),
                'updated_at'        => Carbon::now()->subMonths(2),
            ]);

            $customer_three->parent_id = $customer_two->id;
            $customer_three->api_token = $customer_three->createToken('kashem97@gmail.com')->plainTextToken;
            $customer_three->save();

            $customer->create([
                'user_id'            => $customer_three->id,
                'company'            => 'Codeglen',
                'website'            => 'https://codeglen.com',
                'address'            => 'Banasree, Rampura',
                'city'               => 'Dhaka',
                'postcode'           => '1219',
                'financial_address'  => 'Banasree, Rampura',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1219',
                'tax_number'         => '21-4330267',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'phone'              => '8801700000000',
                'created_at'         => Carbon::now()->subMonths(2),
                'updated_at'         => Carbon::now()->subMonths(2),
                'permissions'        => json_encode([
                    'view_reports',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'release_numbers',
                    'view_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'access_backend',
                ]),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'no',
                    'profile'      => 'yes',
                ]),
            ]);

            $customer_four = $user->create([
                'first_name'        => 'Jhon',
                'last_name'         => 'Doe',
                'image'             => 'app/profile/avatar-6.jpg',
                'email'             => 'jhon@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => false,
                'is_customer'       => true,
                'sms_unit'          => '15000',
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
                'created_at'        => Carbon::now()->subMonth(),
                'updated_at'        => Carbon::now()->subMonth(),
            ]);


            $customer_four->api_token = $customer_four->createToken('jhon@gmail.com')->plainTextToken;
            $customer_four->save();

            $customer->create([
                'user_id'            => $customer_four->id,
                'company'            => 'Codeglen',
                'website'            => 'https://codeglen.com',
                'address'            => 'Banasree, Rampura',
                'city'               => 'Dhaka',
                'postcode'           => '1219',
                'financial_address'  => 'Banasree, Rampura',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1219',
                'tax_number'         => '21-4330267',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'phone'              => '8801700000000',
                'created_at'         => Carbon::now()->subMonth(),
                'updated_at'         => Carbon::now()->subMonth(),
                'permissions'        => json_encode([
                    'view_reports',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'release_numbers',
                    'view_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'access_backend',
                ]),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'no',
                    'profile'      => 'yes',
                ]),
            ]);

            $customer_five = $user->create([
                'first_name'        => 'Sara',
                'last_name'         => 'Doe',
                'image'             => null,
                'email'             => 'sara@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => false,
                'is_customer'       => true,
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
                'created_at'        => Carbon::now()->subMonth(),
                'updated_at'        => Carbon::now()->subMonth(),
            ]);

            $customer_five->api_token = $customer_five->createToken('sara@gmail.com')->plainTextToken;
            $customer_five->save();

            $customer->create([
                'user_id'            => $customer_five->id,
                'company'            => 'Codeglen',
                'website'            => 'https://codeglen.com',
                'address'            => 'Banasree, Rampura',
                'city'               => 'Dhaka',
                'postcode'           => '1219',
                'financial_address'  => 'Banasree, Rampura',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1219',
                'tax_number'         => '21-4330267',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'phone'              => '8801700000000',
                'created_at'         => Carbon::now()->subMonth(),
                'updated_at'         => Carbon::now()->subMonth(),
                'permissions'        => json_encode([
                    'view_reports',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'release_numbers',
                    'view_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'access_backend',
                ]),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'no',
                    'profile'      => 'yes',
                ]),
            ]);


            $customer_six = $user->create([
                'first_name'        => 'Sub',
                'last_name'         => 'Account',
                'image'             => null,
                'email'             => 'subaccount@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => false,
                'is_customer'       => true,
                'active_portal'     => 'customer',
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
                'created_at'        => Carbon::now()->subMonth(),
                'updated_at'        => Carbon::now()->subMonth(),
            ]);


            $customer_six->parent_id = 3;
            $customer_six->api_token = $customer_six->createToken('sara@gmail.com')->plainTextToken;
            $customer_six->save();

            $customer->create([
                'user_id'            => $customer_six->id,
                'company'            => 'Codeglen',
                'website'            => 'https://codeglen.com',
                'address'            => 'Banasree, Rampura',
                'city'               => 'Dhaka',
                'postcode'           => '1219',
                'financial_address'  => 'Banasree, Rampura',
                'financial_city'     => 'Dhaka',
                'financial_postcode' => '1219',
                'tax_number'         => '21-43560267',
                'state'              => 'Dhaka',
                'country'            => 'Bangladesh',
                'phone'              => '8801800000000',
                'created_at'         => Carbon::now()->subMonth(),
                'updated_at'         => Carbon::now()->subMonth(),
                'permissions'        => json_encode([
                    'view_reports',
                    'view_contact_group',
                    'create_contact_group',
                    'update_contact_group',
                    'delete_contact_group',
                    'view_contact',
                    'create_contact',
                    'update_contact',
                    'delete_contact',
                    'view_numbers',
                    'buy_numbers',
                    'release_numbers',
                    'view_keywords',
                    'buy_keywords',
                    'update_keywords',
                    'release_keywords',
                    'view_sender_id',
                    'create_sender_id',
                    'view_blacklist',
                    'create_blacklist',
                    'delete_blacklist',
                    'sms_campaign_builder',
                    'sms_quick_send',
                    'sms_bulk_messages',
                    'access_backend',
                ]),
                'notifications'      => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'no',
                    'profile'      => 'yes',
                ]),
            ]);


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('blacklists')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $blacklists = [
                [
                    'user_id' => 1,
                    'number'  => '8801721970156',
                    'reason'  => null,
                ],
                [
                    'user_id' => 1,
                    'number'  => '8801921970156',
                    'reason'  => strtoupper('stop promotion'),
                ],
                [
                    'user_id' => 1,
                    'number'  => '8801520970156',
                    'reason'  => strtoupper('stop promotion'),
                ],
                [
                    'user_id' => 1,
                    'number'  => '8801781970156',
                    'reason'  => strtoupper('stop promotion'),
                ],
                [
                    'user_id' => 3,
                    'number'  => '8801621970156',
                    'reason'  => 'SPAMMING',
                ],
                [
                    'user_id' => 3,
                    'number'  => '8801721970156',
                    'reason'  => null,
                ],
                [
                    'user_id' => 3,
                    'number'  => '8801821970156',
                    'reason'  => strtoupper('stop promotion'),
                ],
                [
                    'user_id' => 3,
                    'number'  => '8801741970156',
                    'reason'  => null,
                ],
                [
                    'user_id' => 3,
                    'number'  => '8801851970156',
                    'reason'  => strtoupper('stop promotion'),
                ],
            ];

            foreach ($blacklists as $blacklist) {
                Blacklists::create($blacklist);
            }


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('block_sender_id')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $block_sender_id = [
                [
                    'sender_id' => 'Police',
                    'reason'    => 'SPAMMING',
                ],
                [
                    'sender_id' => 'Ambulance',
                    'reason'    => 'SPAMMING',
                ],
                [
                    'sender_id' => 'FireBrigade',
                    'reason'    => 'SPAMMING',
                ],
                [
                    'sender_id' => 'GOVT',
                    'reason'    => 'SPAMMING',
                ],
                [
                    'sender_id' => 'NYPD',
                    'reason'    => 'SPAMMING',
                ],
                [
                    'sender_id' => 'PayPal',
                    'reason'    => '',
                ],
            ];

            foreach ($block_sender_id as $block) {
                BlockSenderId::create($block);
            }


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('currencies')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $currency_data = [
                [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'US Dollar',
                    'code'    => 'USD',
                    'format'  => '${PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'EURO',
                    'code'    => 'EUR',
                    'format'  => '€{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'British Pound',
                    'code'    => 'GBP',
                    'format'  => '£{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Japanese Yen',
                    'code'    => 'JPY',
                    'format'  => '¥{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Russian Ruble',
                    'code'    => 'RUB',
                    'format'  => '‎₽{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Vietnam Dong',
                    'code'    => 'VND',
                    'format'  => '{PRICE}₫',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Brazilian Real',
                    'code'    => 'BRL',
                    'format'  => '‎R${PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Bangladeshi Taka',
                    'code'    => 'BDT',
                    'format'  => '‎৳{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Canadian Dollar',
                    'code'    => 'CAD',
                    'format'  => '‎C${PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Indian rupee',
                    'code'    => 'INR',
                    'format'  => '‎₹{PRICE}',
                    'status'  => true,
                ], [
                    'uid'     => uniqid(),
                    'user_id' => 1,
                    'name'    => 'Nigerian Naira',
                    'code'    => 'CBN',
                    'format'  => '‎₦{PRICE}',
                    'status'  => true,
                ],
            ];

            foreach ($currency_data as $data) {
                Currency::create($data);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('keywords')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $keywords = [
                [
                    'user_id'          => 1,
                    'currency_id'      => 1,
                    'title'            => '50% OFF',
                    'keyword_name'     => '50OFF',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'you will get 50% from our next promotion.',
                    'reply_voice'      => 'you will get 50% from our next promotion.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'status'           => 'available',
                ],
                [
                    'user_id'          => 1,
                    'currency_id'      => 2,
                    'title'            => 'CR7',
                    'keyword_name'     => 'CR7',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'Thank you for voting Cristiano Ronaldo.',
                    'reply_voice'      => 'Thank you for voting Cristiano Ronaldo.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'validity_date'    => Carbon::now()->add(1, 'year'),
                    'status'           => 'assigned',
                ],
                [
                    'user_id'          => 1,
                    'currency_id'      => 3,
                    'title'            => 'MESSI10',
                    'keyword_name'     => 'MESSI10',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'Thank you for voting Leonel Messi.',
                    'reply_voice'      => 'Thank you for voting Leonel Messi.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'validity_date'    => Carbon::yesterday(),
                    'status'           => 'expired',
                ],
                [
                    'user_id'          => 3,
                    'currency_id'      => 1,
                    'title'            => '999',
                    'keyword_name'     => '999',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'You will receive all govt facilities from now.',
                    'reply_voice'      => 'You will receive all govt facilities from now.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'status'           => 'assigned',
                ],
                [
                    'user_id'          => 3,
                    'currency_id'      => 1,
                    'title'            => 'PROMO50',
                    'keyword_name'     => 'PROMO50',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'You will get 50% from our next promotion.',
                    'reply_voice'      => 'You will get 50% from our next promotion.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'validity_date'    => Carbon::yesterday(),
                    'status'           => 'expired',
                ],
                [
                    'user_id'          => 4,
                    'currency_id'      => 10,
                    'title'            => 'BlackFriday',
                    'keyword_name'     => 'BFOFF50',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'You will get 50% from our next black friday promotion.',
                    'reply_voice'      => 'You will get 50% from our next black friday promotion.',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'status'           => 'assigned',
                ],
                [
                    'user_id'          => 4,
                    'currency_id'      => 10,
                    'title'            => 'CodeglenDeal',
                    'keyword_name'     => 'CODEGLENDEAL',
                    'sender_id'        => 'Codeglen',
                    'reply_text'       => 'Use voucher CODEGLENDEAL and get 34% off upto INR 120 on orders over INR 349',
                    'reply_voice'      => 'Use voucher CODEGLENDEAL and get 34% off upto INR 120 on orders over INR 349',
                    'price'            => 10,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'validity_date'    => Carbon::yesterday(),
                    'status'           => 'expired',
                ],
            ];

            foreach ($keywords as $keyword) {
                Keywords::create($keyword);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('phone_numbers')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $phone_numbers = [
                [
                    'user_id'          => 1,
                    'number'           => '8801721970168',
                    'status'           => 'available',
                    'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp', 'viber', 'otp']),
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'user_id'          => 1,
                    'number'           => '8801526970168',
                    'status'           => 'assigned',
                    'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp', 'viber', 'otp']),
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 1,
                    'number'           => '8801626980168',
                    'status'           => 'expired',
                    'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp', 'viber', 'otp']),
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'user_id'          => 3,
                    'number'           => '8801921970168',
                    'status'           => 'assigned',
                    'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp', 'viber', 'otp']),
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 3,
                    'number'           => '8801621970168',
                    'status'           => 'expired',
                    'price'            => 5,
                    'capabilities'     => json_encode(['voice', 'mms', 'whatsapp']),
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => 6,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 3,
                ],
                [
                    'user_id'          => 4,
                    'number'           => '8801521970168',
                    'status'           => 'assigned',
                    'price'            => 5,
                    'capabilities'     => json_encode(['sms', 'whatsapp', 'otp']),
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'year',
                    'currency_id'      => 10,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 4,
                    'number'           => '8801821970168',
                    'status'           => 'assigned',
                    'price'            => 5,
                    'capabilities'     => json_encode(['sms', 'otp']),
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 6,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 10,
                    'validity_date'    => Carbon::now()->add('month', 6),
                ],
                [
                    'user_id'          => 4,
                    'number'           => '8801920000168',
                    'status'           => 'expired',
                    'price'            => 5,
                    'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp', 'viber', 'otp']),
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => 6,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 10,
                ],
            ];

            foreach ($phone_numbers as $number) {
                PhoneNumbers::create($number);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('senderid')->truncate();
            DB::table('senderid_plans')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $sender_ids = [
                [
                    'user_id'          => 1,
                    'sender_id'        => 'USMS',
                    'status'           => 'active',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addYear(),
                ],
                [
                    'user_id'          => 1,
                    'sender_id'        => 'Apple',
                    'status'           => 'payment_required',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addYear(),
                ],
                [
                    'user_id'          => 1,
                    'sender_id'        => 'Info',
                    'status'           => 'expired',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->subDay(),
                ],
                [
                    'user_id'          => 1,
                    'sender_id'        => 'Police',
                    'status'           => 'block',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 1,
                    'sender_id'        => 'SHAMIM',
                    'status'           => 'pending',
                    'price'            => 5,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => 6,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->add('month', 6),
                ],
                [
                    'user_id'          => 1,
                    'sender_id'        => 'Codeglen',
                    'status'           => 'active',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'Codeglen',
                    'status'           => 'active',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addYear(),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'Apple',
                    'status'           => 'payment_required',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addYear(),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'Expo',
                    'status'           => 'expired',
                    'price'            => 5,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->subDay(),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'NDP',
                    'status'           => 'block',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'Saad',
                    'status'           => 'pending',
                    'price'            => 5,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => 6,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->add('month', 6),
                ],
                [
                    'user_id'          => 3,
                    'sender_id'        => 'CoderPixel',
                    'status'           => 'active',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                    'validity_date'    => Carbon::now()->addMonth(),
                ],
                [
                    'user_id'          => 4,
                    'sender_id'        => 'DLT',
                    'status'           => 'active',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 10,
                    'validity_date'    => Carbon::now()->addMonth(),
                    'description'      => 'Description for DLT',
                    'entity_id'        => '0x4a8d2c8Fd8e18F5E46d5b2e9D0B25B43eCD0fC21',
                ],
                [
                    'user_id'          => 4,
                    'sender_id'        => 'DLTTRAI',
                    'status'           => 'pending',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 10,
                    'validity_date'    => Carbon::now()->addMonth(),
                    'description'      => 'Description for DLT',
                    'entity_id'        => '0x4a8d2c8Fd8e18F5E46d5b2e9D0B25B43eCD0fC21',
                ],
                [
                    'user_id'          => 4,
                    'sender_id'        => 'TRAI',
                    'status'           => 'expired',
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'currency_id'      => 10,
                    'validity_date'    => Carbon::now()->subDay(),
                    'description'      => 'Description for TRAI',
                    'entity_id'        => '0x4a8d2c8Fd8e18F5E46d5b2e9D0B25B43eC67fC21',
                ],
            ];

            foreach ($sender_ids as $senderId) {
                Senderid::create($senderId);
            }
            $sender_ids_plan = [
                [
                    'price'            => 5,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 12,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '3',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 20,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '6',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 35,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 65,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '2',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 115,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '3',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
            ];

            foreach ($sender_ids_plan as $plan) {
                SenderidPlan::create($plan);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('sending_servers')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $sendingServersRepo = new EloquentSendingServerRepository(new SendingServer());
            $sendingServers     = collect($sendingServersRepo->allSendingServer());

            foreach ($sendingServers->reverse() as $server) {
                $server['user_id'] = 1;
                $server['status']  = true;

                SendingServer::create($server);
            }

            SpamWord::truncate();

            $spam_words = [
                [
                    'word' => 'POLICE',
                ],
                [
                    'word' => 'RAB',
                ],
                [
                    'word' => 'GOVT',
                ],
                [
                    'word' => 'NYPD',
                ],
                [
                    'word' => 'CIA',
                ],
                [
                    'word' => 'NDP',
                ],
                [
                    'word' => 'FBI',
                ],
            ];

            foreach ($spam_words as $word) {
                SpamWord::create($word);
            }


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('plans')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $plans = [
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Free',
                    'description'          => 'A simple start for everyone',
                    'price'                => '0',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'options'              => '{"sms_max":"5","list_max":"5","subscriber_max":"500","subscriber_per_list_max":"100","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"100","sending_quota_time":"1","sending_quota_time_unit":"hour","max_process":"1","list_import":"yes","list_export":"yes","api_access":"no","create_sub_account":"no","delete_sms_history":"no","add_previous_balance":"no","sender_id_verification":"yes","send_spam_message":"no"}',
                    'status'               => true,
                    'tax_billing_required' => false,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Standard',
                    'description'          => 'For small to medium businesses',
                    'price'                => '49',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'is_popular'           => true,
                    'options'              => '{"sms_max":"6000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"5000","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"1","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"no","send_spam_message":"no"}',
                    'status'               => true,
                    'tax_billing_required' => true,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 10,
                    'name'                 => 'DLT Enabled',
                    'description'          => 'TRAI - Distributed Ledger Technology (DLT)',
                    'price'                => '1300',
                    'billing_cycle'        => 'yearly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'year',
                    'options'              => '{"sms_max":"5000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"5000","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"1","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"yes","send_spam_message":"no"}',
                    'status'               => true,
                    'is_dlt'               => true,
                    'tax_billing_required' => true,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Enterprise',
                    'description'          => 'Solution for big organizations',
                    'price'                => '99',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'options'              => '{"sms_max":"15000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"10000","segment_per_list_max":"-1","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"3","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"yes","send_spam_message":"yes"}',
                    'status'               => true,
                    'tax_billing_required' => true,
                ],
            ];

            foreach ($plans as $plan) {
                Plan::create($plan);
            }


            PlanSendingCreditPrice::truncate();

            $plans = [
                1 => 'free',
                2 => 'standard',
                4 => 'premium',
            ];

            foreach ($plans as $planId => $planType) {
                $planCreditPricing = [];

                for ($i = 1; $i <= 8; $i++) {
                    $unitFrom = ($i - 1) * 150000 + 1;
                    $unitTo   = $i * 150000;

                    $planCreditPricing[] = [
                        'uid'             => uniqid(),
                        'plan_id'         => $planId,
                        'unit_from'       => $unitFrom,
                        'unit_to'         => $unitTo,
                        'per_credit_cost' => 0.0079 - ($i * 0.0002), // Example calculation, adjust as needed
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }

                PlanSendingCreditPrice::insert($planCreditPricing);
            }


            $dltCreditPricing = [
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 1,
                    'unit_to'         => 2999,
                    'per_credit_cost' => 0.24,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 3000,
                    'unit_to'         => 9999,
                    'per_credit_cost' => 0.22,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 10000,
                    'unit_to'         => 24999,
                    'per_credit_cost' => 0.19,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 25000,
                    'unit_to'         => 49999,
                    'per_credit_cost' => 0.18,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 50000,
                    'unit_to'         => 99999,
                    'per_credit_cost' => 0.17,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 100000,
                    'unit_to'         => 499999,
                    'per_credit_cost' => 0.16,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 500000,
                    'unit_to'         => 999999,
                    'per_credit_cost' => 0.14,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 1000000,
                    'unit_to'         => 9999999,
                    'per_credit_cost' => 0.13,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ];

            PlanSendingCreditPrice::insert($dltCreditPricing);

            $plan_coverage = [
                /*Default/Standard or Plan 2*/
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 16,
                    'plan_id'                 => 2,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"2","receive_voice_sms":"1","mms_sms":"3","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 92,
                    'plan_id'                 => 2,
                    'sending_server'          => 218,
                    'voice_sending_server'    => 212,
                    'mms_sending_server'      => 211,
                    'whatsapp_sending_server' => 46,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 87,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","mms":"true","whatsapp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 147,
                    'plan_id'                 => 2,
                    'sending_server'          => 217,
                    'voice_sending_server'    => 211,
                    'mms_sending_server'      => 209,
                    'whatsapp_sending_server' => 81,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 193,
                    'plan_id'                 => 2,
                    'sending_server'          => 216,
                    'voice_sending_server'    => 199,
                    'mms_sending_server'      => 187,
                    'whatsapp_sending_server' => 74,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 27,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 220,
                    'plan_id'                 => 2,
                    'sending_server'          => 213,
                    'voice_sending_server'    => 198,
                    'mms_sending_server'      => 196,
                    'whatsapp_sending_server' => 58,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 221,
                    'plan_id'                 => 2,
                    'sending_server'          => 211,
                    'voice_sending_server'    => 196,
                    'mms_sending_server'      => 198,
                    'whatsapp_sending_server' => 46,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 27,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 122,
                    'plan_id'                 => 2,
                    'sending_server'          => 206,
                    'voice_sending_server'    => 187,
                    'mms_sending_server'      => 16,
                    'whatsapp_sending_server' => 41,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 146,
                    'plan_id'                 => 2,
                    'sending_server'          => 207,
                    'voice_sending_server'    => 209,
                    'mms_sending_server'      => 212,
                    'whatsapp_sending_server' => 19,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 192,
                    'plan_id'                 => 2,
                    'sending_server'          => 212,
                    'voice_sending_server'    => 167,
                    'mms_sending_server'      => 199,
                    'whatsapp_sending_server' => 12,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 190,
                    'plan_id'                 => 2,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"0","receive_voice_sms":"0","mms_sms":"0","receive_mms_sms":"0","whatsapp_sms":"0","receive_whatsapp_sms":"0","viber_sms":"0","receive_viber_sms":"0","otp_sms":"0","receive_otp_sms":"0","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],

                /*Free Plan ID 1*/
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 16,
                    'plan_id'                 => 1,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"2","receive_voice_sms":"1","mms_sms":"3","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 92,
                    'plan_id'                 => 1,
                    'sending_server'          => 218,
                    'voice_sending_server'    => 212,
                    'mms_sending_server'      => 211,
                    'whatsapp_sending_server' => 46,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 87,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 193,
                    'plan_id'                 => 1,
                    'sending_server'          => 216,
                    'voice_sending_server'    => 199,
                    'mms_sending_server'      => 187,
                    'whatsapp_sending_server' => 74,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 27,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 220,
                    'plan_id'                 => 1,
                    'sending_server'          => 213,
                    'voice_sending_server'    => 198,
                    'mms_sending_server'      => 196,
                    'whatsapp_sending_server' => 58,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 146,
                    'plan_id'                 => 1,
                    'sending_server'          => 207,
                    'voice_sending_server'    => 209,
                    'mms_sending_server'      => 212,
                    'whatsapp_sending_server' => 19,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 192,
                    'plan_id'                 => 1,
                    'sending_server'          => 212,
                    'voice_sending_server'    => 167,
                    'mms_sending_server'      => 199,
                    'whatsapp_sending_server' => 12,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 190,
                    'plan_id'                 => 1,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"0","receive_voice_sms":"0","mms_sms":"0","receive_mms_sms":"0","whatsapp_sms":"0","receive_whatsapp_sms":"0","viber_sms":"0","receive_viber_sms":"0","otp_sms":"0","receive_otp_sms":"0","plain":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],

                /*Enterprise Plan ID 4*/
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 16,
                    'plan_id'                 => 4,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"0","receive_plain_sms":"0","voice_sms":"0","receive_voice_sms":"0","mms_sms":"3","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 92,
                    'plan_id'                 => 4,
                    'sending_server'          => 218,
                    'voice_sending_server'    => 212,
                    'mms_sending_server'      => 211,
                    'whatsapp_sending_server' => 46,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 87,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 193,
                    'plan_id'                 => 4,
                    'sending_server'          => 216,
                    'voice_sending_server'    => 199,
                    'mms_sending_server'      => 187,
                    'whatsapp_sending_server' => 74,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 27,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 220,
                    'plan_id'                 => 4,
                    'sending_server'          => 213,
                    'voice_sending_server'    => 198,
                    'mms_sending_server'      => 196,
                    'whatsapp_sending_server' => 58,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 146,
                    'plan_id'                 => 4,
                    'sending_server'          => 207,
                    'voice_sending_server'    => 209,
                    'mms_sending_server'      => 212,
                    'whatsapp_sending_server' => 19,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 192,
                    'plan_id'                 => 4,
                    'sending_server'          => 212,
                    'voice_sending_server'    => 167,
                    'mms_sending_server'      => 199,
                    'whatsapp_sending_server' => 12,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 29,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"1","receive_voice_sms":"1","mms_sms":"1","receive_mms_sms":"1","whatsapp_sms":"1","receive_whatsapp_sms":"1","viber_sms":"1","receive_viber_sms":"1","otp_sms":"1","receive_otp_sms":"1","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 190,
                    'plan_id'                 => 4,
                    'sending_server'          => 219,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"0","receive_voice_sms":"0","mms_sms":"0","receive_mms_sms":"0","whatsapp_sms":"0","receive_whatsapp_sms":"0","viber_sms":"0","receive_viber_sms":"0","otp_sms":"0","receive_otp_sms":"0","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],

                /*DLT Plan ID 3*/
                [
                    'uid'                     => uniqid(),
                    'country_id'              => 92,
                    'plan_id'                 => 3,
                    'sending_server'          => 206,
                    'voice_sending_server'    => 219,
                    'mms_sending_server'      => 219,
                    'whatsapp_sending_server' => 219,
                    'viber_sending_server'    => 30,
                    'otp_sending_server'      => 86,
                    'status'                  => true,
                    'options'                 => '{"plain_sms":"1","receive_plain_sms":"1","voice_sms":"0","receive_voice_sms":"0","mms_sms":"0","receive_mms_sms":"0","whatsapp_sms":"0","receive_whatsapp_sms":"0","viber_sms":"0","receive_viber_sms":"0","otp_sms":"0","receive_otp_sms":"0","plain":"true","voice":"true","mms":"true","whatsapp":"true","viber":"true","otp":"true"}',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ],

            ];

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('plans_coverage_countries')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            PlansCoverageCountries::insert($plan_coverage);

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('subscriptions')->truncate();
            DB::table('subscription_logs')->truncate();
            DB::table('subscription_transactions')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $subscriptions = [
                [
                    'uid'                    => uniqid(),
                    'user_id'                => 1,
                    'plan_id'                => 2,
                    'options'                => '{"credit_warning":true,"credit":"100","credit_notify":"both","subscription_warning":true,"subscription_notify":"both"}',
                    'start_at'               => now(),
                    'status'                 => 'active',
                    'end_period_last_days'   => 10,
                    'current_period_ends_at' => Carbon::now()->addMonth(),
                    'end_at'                 => null,
                    'end_by'                 => null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ],
                [
                    'uid'                    => uniqid(),
                    'user_id'                => 3,
                    'plan_id'                => 2,
                    'options'                => '{"credit_warning":true,"credit":"100","credit_notify":"both","subscription_warning":true,"subscription_notify":"both"}',
                    'start_at'               => now(),
                    'status'                 => 'active',
                    'end_period_last_days'   => 10,
                    'current_period_ends_at' => Carbon::now()->addMonth(),
                    'end_at'                 => null,
                    'end_by'                 => null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ],
                [
                    'uid'                    => uniqid(),
                    'user_id'                => 4,
                    'plan_id'                => 3,
                    'start_at'               => now(),
                    'status'                 => 'active',
                    'options'                => '{"credit_warning":true,"credit":"100","credit_notify":"both","subscription_warning":true,"subscription_notify":"both"}',
                    'end_period_last_days'   => 10,
                    'current_period_ends_at' => Carbon::now()->addYear(),
                    'end_at'                 => null,
                    'end_by'                 => null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ],
                [
                    'uid'                    => uniqid(),
                    'user_id'                => 5,
                    'plan_id'                => 1,
                    'start_at'               => now(),
                    'status'                 => 'active',
                    'options'                => '{"credit_warning":true,"credit":"100","credit_notify":"both","subscription_warning":true,"subscription_notify":"both"}',
                    'end_period_last_days'   => 10,
                    'current_period_ends_at' => Carbon::now()->addMonth(),
                    'end_at'                 => null,
                    'end_by'                 => null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ],
                [
                    'uid'                    => uniqid(),
                    'user_id'                => 6,
                    'plan_id'                => 4,
                    'start_at'               => now(),
                    'status'                 => 'active',
                    'options'                => '{"credit_warning":true,"credit":"100","credit_notify":"both","subscription_warning":true,"subscription_notify":"both"}',
                    'end_period_last_days'   => 10,
                    'current_period_ends_at' => Carbon::now()->addMonth(),
                    'end_at'                 => null,
                    'end_by'                 => null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ],
            ];

            Subscription::insert($subscriptions);

            $subscriptionLogs = [
                [
                    'subscription_id' => 1,
                    'type'            => 'admin_plan_assigned',
                    'data'            => '{"plan":"Standard","price":"\u00a3500"}',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'subscription_id' => 2,
                    'type'            => 'admin_plan_assigned',
                    'data'            => '{"plan":"Premium","price":"$5,000"}',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ];

            SubscriptionLog::insert($subscriptionLogs);

            $subscriptionTransaction = [
                [
                    'subscription_id' => 1,
                    'title'           => 'Subscribed to Standard plan',
                    'type'            => 'subscribe',
                    'status'          => 'success',
                    'amount'          => '£500',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'subscription_id' => 2,
                    'title'           => 'Subscribed to Premium plan',
                    'type'            => 'subscribe',
                    'status'          => 'success',
                    'amount'          => '$5,000',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ];

            SubscriptionTransaction::insert($subscriptionTransaction);


            //invoice
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('invoices')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $invoices = [

                /*User ID 1*/
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword CR7',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword MESSI10',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Premium',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Standard',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for number',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Info',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for Keyword Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Codeglen',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID USMS',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID SHAMIM',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 1,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for Number 88014754789',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now(),
                    'updated_at'     => Carbon::now(),
                ],

                /*User ID 3*/

                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword CR7',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword MESSI10',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Premium',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Standard',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for number',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Info',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for Keyword Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Codeglen',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID USMS',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID SHAMIM',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 3,
                    'currency_id'    => 1,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for Number 88014754789',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now(),
                    'updated_at'     => Carbon::now(),
                ],

                /*User ID 4*/

                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword CR7',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(3),
                    'updated_at'     => Carbon::now()->subDays(3),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for keyword MESSI10',
                    'transaction_id' => 'pi_1Id6n9Jer' . uniqid(),
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Premium',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 500,
                    'tax'            => 0,
                    'total_amount'   => 500,
                    'type'           => 'subscription',
                    'description'    => 'Payment for subscription Standard',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for number',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Info',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDays(2),
                    'updated_at'     => Carbon::now()->subDays(2),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'keyword',
                    'description'    => 'Payment for Keyword Apple',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID Codeglen',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID USMS',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'senderid',
                    'description'    => 'Payment for Sender ID SHAMIM',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now()->subDay(),
                    'updated_at'     => Carbon::now()->subDay(),
                ],
                [
                    'uid'            => uniqid(),
                    'user_id'        => 4,
                    'currency_id'    => 10,
                    'payment_method' => 3,
                    'amount'         => 50,
                    'tax'            => 0,
                    'total_amount'   => 50,
                    'type'           => 'number',
                    'description'    => 'Payment for Number 88014754789',
                    'transaction_id' => 'pi_1Id6n9JerTkfRDz2sMhOqnNS',
                    'status'         => 'paid',
                    'created_at'     => Carbon::now(),
                    'updated_at'     => Carbon::now(),
                ],
            ];

            Invoices::insert($invoices);

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('contact_groups')->truncate();
            DB::table('contact_groups_optin_keywords')->truncate();
            DB::table('contact_groups_optout_keywords')->truncate();
            DB::table('contact_group_fields')->truncate();
            DB::table('contact_group_field_options')->truncate();
            DB::table('contacts')->truncate();
            DB::table('contacts_custom_field')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            //contact groups
            $contact_groups = [
                /*Customer ID 1*/
                [
                    'customer_id'              => 1,
                    'name'                     => 'BlackFriday',
                    'sender_id'                => 'USMS',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => true,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 95,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 5,
                    ]),

                ],
                [
                    'customer_id'              => 1,
                    'name'                     => 'CyberMonday',
                    'sender_id'                => '8801526970168',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 100,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 0,
                    ]),
                ],
                [
                    'customer_id'              => 1,
                    'name'                     => 'Codeglen',
                    'sender_id'                => 'Codeglen',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 9,
                        'TotalSubscribers'  => 10,
                        'UnsubscribesCount' => 1,
                    ]),
                ],

                /*Customer ID 3*/

                [
                    'customer_id'              => 3,
                    'name'                     => 'MonthlyPromotion',
                    'sender_id'                => 'Codeglen',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => true,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 95,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 5,
                    ]),

                ],
                [
                    'customer_id'              => 3,
                    'name'                     => 'HalfYearlyPromotion',
                    'sender_id'                => '8801921970168',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 100,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 0,
                    ]),
                ],
                [
                    'customer_id'              => 3,
                    'name'                     => 'YearlyPromotion',
                    'sender_id'                => 'CoderPixel',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 9,
                        'TotalSubscribers'  => 10,
                        'UnsubscribesCount' => 1,
                    ]),
                ],
                /*Customer ID 4*/

                [
                    'customer_id'              => 4,
                    'name'                     => 'EidPromotion',
                    'sender_id'                => 'DLT',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => true,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 95,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 5,
                    ]),

                ],
                [
                    'customer_id'              => 4,
                    'name'                     => 'IndependenceDayPromotion',
                    'sender_id'                => '8801521970168',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 100,
                        'TotalSubscribers'  => 100,
                        'UnsubscribesCount' => 0,
                    ]),
                ],
                [
                    'customer_id'              => 4,
                    'name'                     => 'RepublicDayPromotion',
                    'sender_id'                => '8801821970168',
                    'send_welcome_sms'         => true,
                    'unsubscribe_notification' => true,
                    'send_keyword_message'     => false,
                    'status'                   => true,
                    'cache'                    => json_encode([
                        'SubscribersCount'  => 9,
                        'TotalSubscribers'  => 10,
                        'UnsubscribesCount' => 1,
                    ]),
                ],
            ];

            foreach ($contact_groups as $group) {
                (new ContactGroups)->create($group);
            }

            $factory      = Factory::create();
            $data         = [];
            $customer_ids = [1, 3, 4];
            for ($i = 0; $i < 95; $i++) {
                $number = '88017' . $i . time();
                $number = substr($number, 0, 13);

                foreach ($customer_ids as $customer_id) {

                    $group_id = match ($customer_id) {
                        3 => 4,
                        4 => 7,
                        default => 1,
                    };

                    $data[] = [
                        'uid'         => uniqid(),
                        'customer_id' => $customer_id,
                        'group_id'    => $group_id,
                        'phone'       => $number,
                        'status'      => 'subscribe',
                    ];
                }
            }


            for ($i = 0; $i < 5; $i++) {
                $number = '88017' . $i . time();
                $number = substr($number, 0, 13);

                foreach ($customer_ids as $customer_id) {

                    $group_id = match ($customer_id) {
                        3 => 4,
                        4 => 7,
                        default => 1,
                    };

                    $data[] = [
                        'uid'         => uniqid(),
                        'customer_id' => $customer_id,
                        'group_id'    => $group_id,
                        'phone'       => $number,
                        'status'      => 'unsubscribe',
                    ];
                }
            }


            for ($i = 0; $i < 100; $i++) {
                $number = '88016' . $i . time();
                $number = substr($number, 0, 13);


                foreach ($customer_ids as $customer_id) {

                    $group_id = match ($customer_id) {
                        3 => 5,
                        4 => 8,
                        default => 2,
                    };

                    $data[] = [
                        'uid'         => uniqid(),
                        'customer_id' => $customer_id,
                        'group_id'    => $group_id,
                        'phone'       => $number,
                        'status'      => 'subscribe',
                    ];
                }

            }

            for ($i = 0; $i < 9; $i++) {
                $number = '88015' . $i . time();
                $number = substr($number, 0, 13);

                foreach ($customer_ids as $customer_id) {

                    $group_id = match ($customer_id) {
                        3 => 6,
                        4 => 9,
                        default => 3,
                    };

                    $data[] = [
                        'uid'         => uniqid(),
                        'customer_id' => $customer_id,
                        'group_id'    => $group_id,
                        'phone'       => $number,
                        'status'      => 'subscribe',
                    ];
                }
            }


            for ($i = 0; $i < 1; $i++) {
                $number = '88015' . $i . time();
                $number = substr($number, 0, 13);

                foreach ($customer_ids as $customer_id) {

                    $group_id = match ($customer_id) {
                        3 => 6,
                        4 => 9,
                        default => 3,
                    };

                    $data[] = [
                        'uid'         => uniqid(),
                        'customer_id' => $customer_id,
                        'group_id'    => $group_id,
                        'phone'       => $number,
                        'status'      => 'unsubscribe',
                    ];
                }
            }

            foreach ($data as $row) {
                $subscriber = Contacts::create($row);
                $subscriber->updateFields([
                    "PHONE"      => $row['phone'],
                    "FIRST_NAME" => $factory->firstName,
                    "LAST_NAME"  => $factory->lastName,
                ]);
            }

            //sms template
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('templates')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $sms_templates = [
                /*Customer ID 1*/
                [
                    'user_id' => 1,
                    'name'    => 'Promotion',
                    'message' => 'You will get 50 Percent off from next {EVENT_DATE}',
                ],
                [
                    'user_id' => 1,
                    'name'    => 'Greeting',
                    'message' => 'Hi {FIRST_NAME} {LAST_NAME}, welcome to Codeglen',
                ],

                /*Customer ID 3*/
                [
                    'user_id' => 3,
                    'name'    => 'BlackFriday',
                    'message' => 'You will get 50 Percent off from next black friday {EVENT_DATE}',
                ],
                [
                    'user_id' => 3,
                    'name'    => 'Welcome',
                    'message' => 'Hi {FIRST_NAME} {LAST_NAME}, welcome to Codeglen',
                ],

                /*Customer ID 4*/
                [
                    'user_id'         => 4,
                    'name'            => 'CyberMonday',
                    'sender_id'       => 'DLT',
                    'message'         => 'You will get 34 Percent off from next cyber monday {EVENT_DATE}',
                    'dlt_template_id' => 'template123ABC',
                    'dlt_category'    => 'promotional',
                    'approved'        => 'approved',
                ],
                [
                    'user_id'         => 4,
                    'name'            => 'Hello',
                    'sender_id'       => '8801821970168',
                    'message'         => 'Hi {FIRST_NAME} {LAST_NAME}, welcome to Codeglen',
                    'dlt_template_id' => 'template123Hello',
                    'dlt_category'    => 'transactional',
                    'approved'        => 'approved',
                ],
            ];

            foreach ($sms_templates as $template) {
                Templates::create($template);
            }

            //campaigns

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('campaigns_senderids')->truncate();
            DB::table('campaigns_lists')->truncate();
            DB::table('campaigns')->truncate();
            DB::table('reports')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');


            $status  = collect(['Delivered', 'Failed', 'Enroute', 'Undelivered', 'Expired', 'Rejected', 'Accepted', 'Skipped']);
            $factory = Factory::create();

            $userOneCampaigns = [
                /*Customer ID 1*/
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'SMS Campaign',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_DONE,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'delivery_at'   => Carbon::now()->subMinutes(2),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(2),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'Voice Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'voice',
                    'language'      => 'en-GB',
                    'gender'        => 'male',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'MMS Campaign',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'media_url'     => 'https://ultimatesms.codeglen.com/demo/mms/mms_1617527278.png',
                    'sms_type'      => 'mms',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'WhatsApp Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'whatsapp',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'Schedule Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SCHEDULED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time' => Carbon::now()->addDays(3),
                    'schedule_type' => 'onetime',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'              => uniqid(),
                    'user_id'          => 1,
                    'campaign_name'    => 'Recurring Campaign',
                    'message'          => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'         => 'plain',
                    'upload_type'      => 'normal',
                    'status'           => Campaigns::STATUS_SCHEDULED,
                    'cache'            => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time'    => Carbon::now()->addDays(2),
                    'schedule_type'    => 'recurring',
                    'frequency_cycle'  => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'recurring_end'    => Carbon::now()->addMonth(),
                    'created_at'       => Carbon::now(),
                    'updated_at'       => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'Normal Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'Campaign Paused',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_PAUSED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(6),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 1,
                    'campaign_name' => 'Campaign Processing',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SENDING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(6),
                ],
            ];
            $campaign_data    = [];

            $contacts = Contacts::where('group_id', 1)->take(30)->get();

            foreach ($userOneCampaigns as $campaign) {
                $getData = Campaigns::insertGetId($campaign);

                if ($getData) {

                    CampaignsList::create([
                        'campaign_id'     => $getData,
                        'contact_list_id' => 1,
                    ]);

                    if ($getData % 2) {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => 'Codeglen',
                            'originator'  => 'sender_id',
                        ]);

                        foreach ($contacts as $contact) {

                            $campaign_data[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 1,
                                'from'              => 'Codeglen',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }

                    } else {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => '8801526970168',
                            'originator'  => 'phone_number',
                        ]);

                        foreach ($contacts as $contact) {

                            $campaign_data[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 1,
                                'from'              => '8801526970168',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }
                    }
                }
            }

            Reports::insert($campaign_data);

            $campaign_data_four = [];
            $userFourCampaigns  = [

                /*Customer ID 4*/
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'SMS Campaign',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_DONE,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'delivery_at'   => Carbon::now()->subMinutes(2),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(2),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'WhatsApp Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'whatsapp',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'Schedule Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SCHEDULED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time' => Carbon::now()->addDays(3),
                    'schedule_type' => 'onetime',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'              => uniqid(),
                    'user_id'          => 4,
                    'campaign_name'    => 'Recurring Campaign',
                    'message'          => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'         => 'plain',
                    'upload_type'      => 'normal',
                    'status'           => Campaigns::STATUS_SCHEDULED,
                    'cache'            => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time'    => Carbon::now()->addDays(2),
                    'schedule_type'    => 'recurring',
                    'frequency_cycle'  => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'recurring_end'    => Carbon::now()->addMonth(),
                    'created_at'       => Carbon::now(),
                    'updated_at'       => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'Normal Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'Campaign Paused',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_PAUSED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(2),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 4,
                    'campaign_name' => 'Campaign Processing',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SENDING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(6),
                ],
            ];

            $contactsFour = Contacts::where('group_id', 7)->take(30)->get();

            foreach ($userFourCampaigns as $campaign) {
                $getData = Campaigns::insertGetId($campaign);

                if ($getData) {

                    CampaignsList::create([
                        'campaign_id'     => $getData,
                        'contact_list_id' => 7,
                    ]);

                    if ($getData % 2) {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => 'DLT',
                            'originator'  => 'sender_id',
                        ]);

                        foreach ($contactsFour as $contact) {

                            $campaign_data_four[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 4,
                                'from'              => 'DLT',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }

                    } else {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => '8801521970168',
                            'originator'  => 'phone_number',
                        ]);

                        foreach ($contactsFour as $contact) {

                            $campaign_data_four[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 4,
                                'from'              => '8801921970168',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }
                    }
                }
            }
            Reports::insert($campaign_data_four);


            $campaign_data_three = [];

            $userThreeCampaigns = [

                /*Customer ID 3*/
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'SMS Campaign',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_DONE,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'delivery_at'   => Carbon::now()->subMinutes(2),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(2),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'Voice Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'voice',
                    'language'      => 'en-GB',
                    'gender'        => 'male',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'MMS Campaign',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'media_url'     => 'https://ultimatesms.codeglen.com/demo/mms/mms_1617527278.png',
                    'sms_type'      => 'mms',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'WhatsApp Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'whatsapp',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'Schedule Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SCHEDULED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time' => Carbon::now()->addDays(3),
                    'schedule_type' => 'onetime',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'              => uniqid(),
                    'user_id'          => 3,
                    'campaign_name'    => 'Recurring Campaign',
                    'message'          => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'         => 'plain',
                    'upload_type'      => 'normal',
                    'status'           => Campaigns::STATUS_SCHEDULED,
                    'cache'            => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'schedule_time'    => Carbon::now()->addDays(2),
                    'schedule_type'    => 'recurring',
                    'frequency_cycle'  => 'monthly',
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                    'recurring_end'    => Carbon::now()->addMonth(),
                    'created_at'       => Carbon::now(),
                    'updated_at'       => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'Normal Campaign',
                    'message'       => 'You will get 50 Percent off from next {event_date}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_QUEUING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'Campaign Paused',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_PAUSED,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(2),
                ],
                [
                    'uid'           => uniqid(),
                    'user_id'       => 3,
                    'campaign_name' => 'Campaign Processing',
                    'message'       => 'Hi {first_name}, welcome to {company}',
                    'sms_type'      => 'plain',
                    'upload_type'   => 'normal',
                    'status'        => Campaigns::STATUS_SENDING,
                    'cache'         => '{"ContactCount":30,"DeliveredCount":30,"FailedDeliveredCount":0,"NotDeliveredCount":0}',
                    'run_at'        => Carbon::now()->subMinutes(5),
                    'created_at'    => Carbon::now()->subMinutes(6),
                    'updated_at'    => Carbon::now()->subMinutes(6),
                ],

            ];

            $contactsThree = Contacts::where('group_id', 4)->take(30)->get();

            foreach ($userThreeCampaigns as $campaign) {
                $getData = Campaigns::insertGetId($campaign);

                if ($getData) {

                    CampaignsList::create([
                        'campaign_id'     => $getData,
                        'contact_list_id' => 4,
                    ]);

                    if ($getData % 2) {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => 'CoderPixel',
                            'originator'  => 'sender_id',
                        ]);

                        foreach ($contactsThree as $contact) {

                            $campaign_data_three[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 3,
                                'from'              => 'CoderPixel',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }

                    } else {
                        CampaignsSenderid::create([
                            'campaign_id' => $getData,
                            'sender_id'   => '8801921970168',
                            'originator'  => 'phone_number',
                        ]);

                        foreach ($contactsThree as $contact) {

                            $campaign_data_three[] = [
                                'uid'               => uniqid(),
                                'user_id'           => 3,
                                'from'              => '8801921970168',
                                'to'                => $contact->phone,
                                'message'           => 'You will get 50 Percent off from next yearly sale.',
                                'sms_type'          => $campaign['sms_type'],
                                'status'            => 'Delivered',
                                'customer_status'   => 'Delivered',
                                'direction'         => Reports::DIRECTION_OUTGOING,
                                'campaign_id'       => $getData,
                                'cost'              => 1,
                                'sms_count'         => 1,
                                'sending_server_id' => 219,
                                'created_at'        => $factory->dateTimeThisMonth(),
                                'updated_at'        => $factory->dateTimeThisMonth(),
                            ];
                        }
                    }
                }
            }

            Reports::insert($campaign_data_three);


            //Automations

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('tracking_logs')->truncate();
            DB::table('automations')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $automations = [
                [
                    'name'              => 'SayBirthday',
                    'user_id'           => 1,
                    'contact_list_id'   => 1,
                    'sending_server_id' => 219,
                    'timezone'          => 'Asia/Dhaka',
                    'sender_id'         => json_encode(['USMS']),
                    'message'           => 'Happy Birthday {first_name}! Thank you for being with us.',
                    'sms_type'          => 'plain',
                    'status'            => 'active',
                    'cache'             => json_encode([
                        'ContactCount'         => 95,
                        'DeliveredCount'       => 30,
                        'FailedDeliveredCount' => 0,
                        'NotDeliveredCount'    => 0,
                        'PendingContactCount'  => 65,
                    ]),
                    'data'              => json_encode([
                        'options' => [
                            'before' => 0,
                            'at'     => '14:21',
                        ],
                    ]),
                ],
                [
                    'name'              => 'BirthdayGreetings',
                    'user_id'           => 1,
                    'contact_list_id'   => 2,
                    'sending_server_id' => 219,
                    'timezone'          => 'Asia/Dhaka',
                    'sender_id'         => json_encode(['USMS']),
                    'message'           => 'Happy Birthday {first_name}! Thank you for being with us.',
                    'sms_type'          => 'plain',
                    'status'            => 'inactive',
                    'cache'             => json_encode([
                        'ContactCount'         => 100,
                        'DeliveredCount'       => 0,
                        'FailedDeliveredCount' => 0,
                        'NotDeliveredCount'    => 0,
                        'PendingContactCount'  => 100,
                    ]),
                    'data'              => json_encode([
                        'options' => [
                            'before' => 0,
                            'at'     => '14:21',
                        ],
                    ]),
                ],
            ];

            foreach ($automations as $automation) {
                Automation::create($automation);
            }
            $AutomationContacts = Contacts::where('group_id', 1)->take(30)->get();

            $automationData = [];

            $message = 'Happy Birthday {first_name}! Thank you for being with us.';

            foreach ($AutomationContacts as $contact) {
                $render_message = Tool::renderSMS($message, [
                    'first_name' => $contact->first_name,
                ]);

                $automationData[] = [
                    'user_id'           => 1,
                    'from'              => 'Codeglen',
                    'to'                => $contact->phone,
                    'message'           => $render_message,
                    'sms_type'          => 'plain',
                    'status'            => 'Delivered',
                    'direction'         => Reports::DIRECTION_INCOMING,
                    'automation_id'     => 1,
                    'cost'              => 1,
                    'sms_count'         => 1,
                    'sending_server_id' => 219,
                ];
            }

            $i = 1;
            foreach ($automationData as $aData) {
                $response = Reports::create($aData);

                $params = [
                    'message_id'        => $response->id,
                    'customer_id'       => 1,
                    'sending_server_id' => 219,
                    'automation_id'     => 1,
                    'contact_id'        => $i,
                    'contact_group_id'  => 1,
                    'sms_count'         => 1,
                    'status'            => 'Delivered',
                ];

                TrackingLog::create($params);
                $i++;
            }

            Automation::find(1)->update([
                'cache' => json_encode([
                    'ContactCount'         => 95,
                    'DeliveredCount'       => 30,
                    'FailedDeliveredCount' => 0,
                    'NotDeliveredCount'    => 0,
                    'PendingContactCount'  => 65,
                ]),
            ]);


            $automationsThree = [
                [
                    'name'              => 'SayBirthday',
                    'user_id'           => 3,
                    'contact_list_id'   => 4,
                    'sending_server_id' => 219,
                    'timezone'          => 'Asia/Dhaka',
                    'sender_id'         => json_encode(['USMS']),
                    'message'           => 'Happy Birthday {first_name}! Thank you for being with us.',
                    'sms_type'          => 'plain',
                    'status'            => 'active',
                    'cache'             => json_encode([
                        'ContactCount'         => 95,
                        'DeliveredCount'       => 30,
                        'FailedDeliveredCount' => 0,
                        'NotDeliveredCount'    => 0,
                        'PendingContactCount'  => 65,
                    ]),
                    'data'              => json_encode([
                        'options' => [
                            'before' => 0,
                            'at'     => '14:21',
                        ],
                    ]),
                ],
                [
                    'name'              => 'BirthdayGreetings',
                    'user_id'           => 3,
                    'contact_list_id'   => 5,
                    'sending_server_id' => 219,
                    'timezone'          => 'Asia/Dhaka',
                    'sender_id'         => json_encode(['USMS']),
                    'message'           => 'Happy Birthday {first_name}! Thank you for being with us.',
                    'sms_type'          => 'plain',
                    'status'            => 'inactive',
                    'cache'             => json_encode([
                        'ContactCount'         => 100,
                        'DeliveredCount'       => 0,
                        'FailedDeliveredCount' => 0,
                        'NotDeliveredCount'    => 0,
                        'PendingContactCount'  => 100,
                    ]),
                    'data'              => json_encode([
                        'options' => [
                            'before' => 0,
                            'at'     => '14:21',
                        ],
                    ]),
                ],
            ];

            foreach ($automationsThree as $automation) {
                Automation::create($automation);
            }
            $AutomationContacts = Contacts::where('group_id', 4)->take(30)->get();

            $automationData = [];


            foreach ($AutomationContacts as $contact) {
                $render_message = Tool::renderSMS($message, [
                    'first_name' => $contact->first_name,
                ]);

                $automationData[] = [
                    'user_id'           => 3,
                    'from'              => 'CoderPixel',
                    'to'                => $contact->phone,
                    'message'           => $render_message,
                    'sms_type'          => 'plain',
                    'status'            => 'Delivered',
                    'direction'         => Reports::DIRECTION_INCOMING,
                    'automation_id'     => 3,
                    'cost'              => 1,
                    'sms_count'         => 1,
                    'sending_server_id' => 219,
                ];
            }

            $i = 1;
            foreach ($automationData as $aData) {
                $response = Reports::create($aData);

                $params = [
                    'message_id'        => $response->id,
                    'customer_id'       => 3,
                    'sending_server_id' => 219,
                    'automation_id'     => 3,
                    'contact_id'        => $i,
                    'contact_group_id'  => 4,
                    'sms_count'         => 1,
                    'status'            => 'Delivered',
                ];

                TrackingLog::create($params);
                $i++;
            }

            Automation::find(3)->update([
                'cache' => json_encode([
                    'ContactCount'         => 95,
                    'DeliveredCount'       => 30,
                    'FailedDeliveredCount' => 0,
                    'NotDeliveredCount'    => 0,
                    'PendingContactCount'  => 65,
                ]),
            ]);


            //chat box
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('chat_box_messages')->truncate();
            DB::table('chat_boxes')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');


            $chatbox = new ChatBoxSeeder();
            $chatbox->run();

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('notifications')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $notifications = [
                [
                    'user_id'           => 1,
                    'notification_for'  => 'admin',
                    'notification_type' => 'user',
                    'message'           => 'Codeglen Registered',
                    'mark_read'         => 0,
                ],
                [
                    'user_id'           => 1,
                    'notification_for'  => 'admin',
                    'notification_type' => 'plan',
                    'message'           => 'Standard Purchased By Codeglen',
                    'mark_read'         => 0,
                ],
                [
                    'user_id'           => 1,
                    'notification_for'  => 'admin',
                    'notification_type' => 'senderid',
                    'message'           => 'New Sender ID request from Codeglen',
                    'mark_read'         => 0,
                ],
                [
                    'user_id'           => 1,
                    'notification_for'  => 'admin',
                    'notification_type' => 'number',
                    'message'           => '8801521970168 Purchased By Codeglen',
                    'mark_read'         => 0,
                ],
                [
                    'user_id'           => 3,
                    'notification_for'  => 'client',
                    'notification_type' => 'chatbox',
                    'message'           => 'Message From 8801521970168',
                    'mark_read'         => 0,
                ],

                [
                    'user_id'           => 3,
                    'notification_for'  => 'admin',
                    'notification_type' => 'senderid',
                    'message'           => 'Sender ID Codeglen Approved',
                    'mark_read'         => 0,
                ],
                [
                    'user_id'           => 4,
                    'notification_for'  => 'client',
                    'notification_type' => 'chatbox',
                    'message'           => 'Message From 8801521970168',
                    'mark_read'         => 0,
                ],

                [
                    'user_id'           => 4,
                    'notification_for'  => 'admin',
                    'notification_type' => 'senderid',
                    'message'           => 'Sender ID Codeglen Approved',
                    'mark_read'         => 0,
                ],
            ];

            foreach ($notifications as $notification) {
                Notifications::create($notification);
            }


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('announcements')->truncate();
            DB::table('announcements_user')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $announcements = [
                [
                    'title'       => 'All Envato Market payments',
                    'description' => 'All Envato Market payments are now being sent via our new payout system. If you have not set up your details yet, visit https://ultimatesms.codeglen.com and select ‘Update Payout Method.’ For more information, see this Help Centre article.',
                ],
                [
                    'title'       => 'Free File nominations for CodeCanyon',
                    'description' => "Free File nominations for CodeCanyon are now open - we're looking for files to use in Marketing promotions during January, February and March, including CodeCanyon's Free File of the Month items. View the criteria and nominate items",
                ],
            ];

            foreach ($announcements as $announcement) {

                $announcement = new Announcements($announcement);

                $announcement->save();

                $announcement->users()->attach([1, 3, 4]);
            }


            $reportsDataCustom = [];

            foreach ($customer_ids as $customer_id) {
                $direction = collect(['incoming', 'outgoing']);
                $smsTypes  = collect(['plain', 'mms', 'voice', 'viber', 'whatsapp', 'otp']);
                $senderIds = collect(['CoderPixel', 'Codeglen', 'DLT']);

                for ($i = 0; $i < 10; $i++) {
                    $reportsDataCustom[] = [

                        'uid'               => uniqid(),
                        'user_id'           => $customer_id,
                        'from'              => $senderIds->random(),
                        'to'                => '88015' . time(),
                        'message'           => $factory->text(100),
                        'sms_type'          => $smsTypes->random(),
                        'status'            => $status->random(),
                        'customer_status'   => $status->random(),
                        'direction'         => $direction->random(),
                        'sms_count'         => 1,
                        'cost'              => 1,
                        'sending_server_id' => 219,
                        'created_at'        => $factory->dateTimeThisMonth(),
                        'updated_at'        => $factory->dateTimeThisMonth(),
                    ];
                }
            }
            Reports::insert($reportsDataCustom);


            $plugin = new Plugins();
            $plugin->truncate();

            $plugins = [
                [
                    'name'        => 'codeglen/usupport',
                    'type'        => 'plugin',
                    'title'       => 'uSupport - Support Ticket Plugin for Ultimate SMS',
                    'description' => 'uSupport is a support ticket plugin for Ultimate SMS.',
                    'version'     => '1.0.0',
                    'status'      => 'active',
                ],
            ];

            foreach ($plugins as $plug) {
                $plugin->create($plug);
            }


            $adminUsersData = [
                [
                    'first_name' => 'Support',
                    'last_name'  => 'Agent',
                    'email'      => 'agent@codeglen.com',
                ],
                [
                    'first_name' => 'Admin',
                    'last_name'  => 'Demo',
                    'email'      => 'demo@admin.com',
                ],
                [
                    'first_name' => 'John',
                    'last_name'  => 'Doe',
                    'email'      => 'john@support.com',
                ],
                [
                    'first_name' => 'Jane',
                    'last_name'  => 'Smith',
                    'email'      => 'jane@support.com',
                ],
                [
                    'first_name' => 'Peter',
                    'last_name'  => 'Jones',
                    'email'      => 'peter@support.com',
                ],
            ];

            $permissions = [
                'access backend',
                'view administrator',
                'create administrator',
                'edit administrator',
                'delete administrator',
                'view roles',
                'create roles',
                'edit roles',
                'delete roles',
                'view tickets',
                'create tickets',
                'edit tickets',
                'delete tickets',
                'assign tickets',
                'reply tickets',
                'edit ticket_replies',
                'delete ticket_replies',
                'delete ticket_attachments',
                'view ticket_tags',
                'create ticket_tags',
                'edit ticket_tags',
                'delete ticket_tags',
                'manage support_settings',
                'view support_agents',
                'create support_agents',
                'edit support_agents',
                'delete support_agents',
                'view support_categories',
                'create support_categories',
                'edit support_categories',
                'delete support_categories',
                'view support_articles',
                'create support_articles',
                'edit support_articles',
                'delete support_articles',
                'view support_analytics',
            ];

            // Ensure support role exists
            $supportRole = Role::firstOrCreate([
                'name'   => 'Support',
                'status' => true,
            ]);

            // Ensure permissions exist (no duplicates)
            foreach ($permissions as $name) {
                $supportRole->permissions()->firstOrCreate(['name' => $name]);
            }

            $createdUsers = collect();

            foreach ($adminUsersData as $userData) {
                $user = User::firstOrCreate(
                    ['email' => $userData['email']],
                    [
                        'uid'               => (string) Str::uuid(),
                        'first_name'        => $userData['first_name'],
                        'last_name'         => $userData['last_name'],
                        'password'          => Hash::make('12345678'),
                        'is_admin'          => true,
                        'email_verified_at' => now(),
                        'status'            => true,
                        'is_customer'       => false,
                        'active_portal'     => 'admin',
                    ]
                );

                // Assign token only if not already set
                if (empty($user->api_token)) {
                    $user->api_token = $user->createToken($user->email)->plainTextToken;
                    $user->save();
                }

                $createdUsers->push($user);
            }

            // Assign roles and create support agents
            $createdUsers->each(function ($user) use ($supportRole) {
                // Attach role only if missing
                if ( ! $user->roles->contains($supportRole->id)) {
                    $user->roles()->attach($supportRole->id);
                }

                // Create support agent if missing
                SupportAgent::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'uid'         => (string) Str::uuid(),
                        'designation' => 'Support Agent',
                        'bio'         => 'Dedicated to providing excellent customer support.',
                        'is_active'   => true,
                    ]
                );
            });


            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            SupportCategory::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // Get all support agent IDs and shuffle them
            $agentIds = SupportAgent::where('is_active', true)->pluck('id')->toArray();
            shuffle($agentIds);

            if (empty($agentIds)) {
                return;
            }

            $categories = [
                [
                    'name'        => 'Account Settings',
                    'description' => 'Manage your user account, profile, and settings.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'settings',
                ],
                [
                    'name'        => 'API Questions',
                    'description' => 'Common questions about our API, limits, and documentation.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'code',
                ],
                [
                    'name'        => 'Billing',
                    'description' => 'Information regarding invoices, payment methods, and refunds.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'file-text',
                ],
                [
                    'name'        => 'Copyright & Legal',
                    'description' => 'Guides on copyright, legal inquiries, and our privacy policy.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'award',
                ],
                [
                    'name'        => 'Mobile Apps',
                    'description' => 'Guides for downloading and using our mobile applications.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'smartphone',
                ],
                [
                    'name'        => 'Using KnowHow',
                    'description' => 'Guides for customizing, upgrading, and using the KnowHow theme.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'help-circle',
                ],
            ];

            foreach ($categories as $categoryData) {
                // Take the first agent ID from the shuffled array
                $assignedAgentId = array_shift($agentIds);

                // If we run out of unique agents, reuse the first one
                if (is_null($assignedAgentId)) {
                    $assignedAgentId = SupportAgent::first()->id;
                }

                SupportCategory::firstOrCreate(
                    ['name' => $categoryData['name']],
                    [
                        'uid'         => (string) Str::uuid(),
                        'slug'        => Str::slug($categoryData['name']),
                        'icon'        => $categoryData['icon'],
                        'description' => $categoryData['description'],
                        'is_active'   => $categoryData['is_active'],
                        'created_by'  => $categoryData['created_by'],
                        'agent_id'    => $assignedAgentId,
                    ]
                );
            }


            $faker = Faker::create();

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            SupportArticle::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');


            $userIds = User::pluck('id')->toArray();

            if (empty($userIds)) {

                return;
            }

            // Fetch categories to assign articles
            $accountSettingsCategory = SupportCategory::where('name', 'Account Settings')->first();
            $apiQuestionsCategory    = SupportCategory::where('name', 'API Questions')->first();
            $billingCategory         = SupportCategory::where('name', 'Billing')->first();
            $copyrightLegalCategory  = SupportCategory::where('name', 'Copyright & Legal')->first();
            $mobileAppsCategory      = SupportCategory::where('name', 'Mobile Apps')->first();
            $usingKnowHowCategory    = SupportCategory::where('name', 'Using KnowHow')->first();

            // Check if categories exist before proceeding
            if ( ! $accountSettingsCategory || ! $apiQuestionsCategory || ! $billingCategory || ! $copyrightLegalCategory || ! $mobileAppsCategory || ! $usingKnowHowCategory) {

                return;
            }

            // Define all articles from the images, categorized
            $articlesData = [
                'Account Settings'  => [
                    'How Secure Is My Password?'    => '
                    <p>Creating a strong password is the first line of defense for your account. We recommend a password that is both long and complex to prevent unauthorized access.</p>
                    <h3>Best Practices for Password Security</h3>
                    <ul>
                        <li>Use a combination of uppercase letters, lowercase letters, numbers, and symbols.</li>
                        <li>Your password should be at least 12 characters long. The longer it is, the more secure it will be.</li>
                        <li>Do not use easily guessable information like your birthdate, pet\'s name, or a common phrase.</li>
                        <li>Avoid reusing the same password across multiple websites or services.</li>
                        <li>Use a password manager to securely store and generate unique, complex passwords.</li>
                    </ul>
                    <p>If you suspect your password has been compromised, please change it immediately through your account settings.</p>',
                    'Can I Change My Username?'     => '
                    <p>Yes, you can change your username. Your username is a unique identifier for your account, and it can be updated directly from your profile settings. To change your username, follow these steps:</p>
                    <ol>
                        <li>Log in to your account.</li>
                        <li>Navigate to the **Account Settings** or **Profile** page.</li>
                        <li>Find the **Username** field and enter your new desired username.</li>
                        <li>Click **Save Changes**.</li>
                    </ol>
                    <p>Please note that usernames must be unique and can only contain letters, numbers, and underscores. You will receive a confirmation once the change is successful.</p>',
                    'Where Can I Upload My Avatar?' => '
                    <p>Your avatar is a great way to personalize your profile! To upload a new avatar, simply go to your profile settings page.</p>
                    <p><strong>Steps to upload your avatar:</strong></p>
                    <ol>
                        <li>Log in to your account and go to your **Profile** page.</li>
                        <li>Look for the current avatar image and click on the **Edit** or **Upload** button.</li>
                        <li>Select an image file from your computer. We support JPG, PNG, and GIF formats.</li>
                        <li>Adjust the crop area if necessary and click **Save**.</li>
                    </ol>
                    <p>Your new avatar will now be visible to other users on the platform.</p>',
                    'How Do I Change My Timezone?'  => '
                    <p>To ensure all timestamps in your account are accurate for your location, you can easily update your timezone in your account settings. This affects notifications, scheduling, and other time-sensitive features.</p>
                    <p><strong>To change your timezone:</strong></p>
                    <ol>
                        <li>Go to **Account Settings**.</li>
                        <li>Find the **Timezone** dropdown menu.</li>
                        <li>Select your correct timezone from the list.</li>
                        <li>Click **Save Changes**.</li>
                    </ol>
                    <p>Your settings will be updated immediately, and all future timestamps will reflect your new timezone.</p>',
                    'How Do I Change My Password?'  => '
                    <p>If you need to change your password for security reasons or simply because you forgot it, follow these steps:</p>
                    <ol>
                        <li>Go to your **Account Settings**.</li>
                        <li>Select the **Password & Security** tab.</li>
                        <li>Enter your current password, then enter your new password twice to confirm.</li>
                        <li>Click **Update Password**.</li>
                    </ol>
                    <p>If you have forgotten your current password, please use the "Forgot Password" link on the login page to reset it via email.</p>',
                ],
                'API Questions'     => [
                    'What Technologies Are Used?'         => '
                    <p>Our API is built on a modern, robust technology stack to ensure reliability, security, and high performance. We utilize a combination of technologies for our backend, including:</p>
                    <ul>
                        <li><strong>Backend:</strong> Laravel PHP framework.</li>
                        <li><strong>Database:</strong> PostgreSQL for high-performance data management.</li>
                        <li><strong>API Endpoints:</strong> RESTful architecture for easy integration.</li>
                        <li><strong>Authentication:</strong> OAuth 2.0 and API keys for secure access.</li>
                    </ul>
                    <p>These technologies allow us to provide a scalable and secure service to all our developers.</p>',
                    'What Are The API Limits?'            => '
                    <p>To ensure fair usage and system stability, our API has certain rate limits. These limits are designed to protect our service from abuse and ensure a great experience for all users.</p>
                    <p><strong>Standard Rate Limits:</strong></p>
                    <ul>
                        <li><strong>Requests per minute:</strong> 60 requests.</li>
                        <li><strong>Requests per hour:</strong> 3,600 requests.</li>
                    </ul>
                    <p>If you exceed these limits, your requests will receive a `429 Too Many Requests` error. Please design your applications to handle this error gracefully, and consider using exponential backoff for retries.</p>
                    <p>For higher limits, please contact our sales team to discuss enterprise-level plans.</p>',
                    'Why Was My Application Rejected?'    => '
                    <p>We review all new API applications to ensure they meet our usage policies. Common reasons for rejection include:</p>
                    <ul>
                        <li>The application\'s purpose is not clearly defined in the application form.</li>
                        <li>The application violates our Terms of Service or Privacy Policy.</li>
                        <li>The provided contact information is invalid or incomplete.</li>
                    </ul>
                    <p>You should have received an email with the specific reason for your application\'s rejection. If you have questions, please feel free to reach out to our support team with your application details.</p>',
                    'Where can I find the documentation?' => '
                    <p>All of our API documentation is available online and is regularly updated. You can find comprehensive guides, endpoint references, and code examples at our dedicated developer portal.</p>
                    <p><strong>Documentation includes:</strong></p>
                    <ul>
                        <li>Getting started guides.</li>
                        <li>Authentication methods and examples.</li>
                        <li>Detailed descriptions of all available endpoints.</li>
                        <li>Example code snippets in popular languages like Python, JavaScript, and PHP.</li>
                    </ul>
                    <p>You can access the full documentation here: <a href="https://your-domain.com/docs">https://your-domain.com/docs</a></p>',
                    'How Do I Get An API Key?'            => '
                    <p>To get your own API key, you must first register as a developer. This process ensures that we can manage and secure access to our API for everyone.</p>
                    <p><strong>Steps to get an API key:</strong></p>
                    <ol>
                        <li>Sign up for a developer account on our platform.</li>
                        <li>Complete the developer application form, describing your planned usage.</li>
                        <li>Once your application is approved, you will find your API key in your developer dashboard.</li>
                    </ol>
                    <p>Please keep your API key secure and never share it publicly. If your key is compromised, you should regenerate it immediately.</p>',
                ],
                'Billing'           => [
                    'Can I Contact A Sales Rep?'           => '
                    <p>Yes, our sales team is ready to help with any questions you have about our pricing, plans, or enterprise solutions. Whether you\'re a large business or need custom features, we can tailor a plan that works for you.</p>
                    <p>You can schedule a call or send an inquiry through our contact page. Our representatives will get back to you within one business day.</p>
                    <p><strong>Contact Options:</strong></p>
                    <ul>
                        <li><strong>Sales Inquiry Form:</strong> Fill out our form <a href="#">here</a>.</li>
                        <li><strong>Email:</strong> sales@yourcompany.com</li>
                        <li><strong>Phone:</strong> +1 (555) 123-4567</li>
                    </ul>',
                    'Do I Need To Pay VAT?'                => '
                    <p>The requirement to pay Value Added Tax (VAT) depends on your location and business status. Our system automatically calculates VAT based on your country and the information you provide in your billing profile.</p>
                    <p><strong>Common Scenarios:</strong></p>
                    <ul>
                        <li><strong>EU Businesses with a valid VAT ID:</strong> You may be exempt from VAT. Please ensure your VAT ID is correctly entered in your billing details.</li>
                        <li><strong>EU Individuals:</strong> VAT will be applied to your invoice at your local rate.</li>
                        <li><strong>Non-EU Customers:</strong> VAT is generally not applied.</li>
                    </ul>
                    <p>Please consult with a tax professional if you have specific questions about your tax obligations.</p>',
                    'Can I Get A Refund?'                  => '
                    <p>Our refund policy is based on the service you have purchased. Generally, we offer refunds under specific circumstances, such as a billing error or if you cancel within a certain trial period.</p>
                    <p><strong>To request a refund:</strong></p>
                    <ol>
                        <li>Log in to your account and go to the **Billing** section.</li>
                        <li>Open a support ticket and select "Refund Request" as the subject.</li>
                        <li>Provide your invoice number and a brief explanation for the request.</li>
                    </ol>
                    <p>Our billing team will review your request and respond as soon as possible. Please note that refunds are not guaranteed for all services.</p>',
                    'Difference Annual & Monthly Billing'  => '
                    <p>We offer both annual and monthly billing options to suit your needs. Here is a quick comparison of the two:</p>
                    <h3>Monthly Billing</h3>
                    <ul>
                        <li><strong>Cost:</strong> Higher per-month rate.</li>
                        <li><strong>Flexibility:</strong> Allows you to cancel or change your plan at any time with a shorter commitment.</li>
                        <li><strong>Best for:</strong> Users who prefer flexibility or are on a short-term project.</li>
                    </ul>
                    <h3>Annual Billing</h3>
                    <ul>
                        <li><strong>Cost:</strong> Lower per-month rate, resulting in significant savings over the year.</li>
                        <li><strong>Commitment:</strong> A one-year commitment is required.</li>
                        <li><strong>Best for:</strong> Users who are committed to the service and want to maximize their savings.</li>
                    </ul>',
                    'What Happens If The Price Increases?' => '
                    <p>We understand that price changes can be a concern. Our policy is to protect our current customers from immediate price increases. If we ever raise our prices, here is how it will affect you:</p>
                    <ul>
                        <li><strong>Existing Subscriptions:</strong> Your current subscription rate will not change. You will be grandfathered into your existing plan at your current price.</li>
                        <li><strong>New Customers:</strong> Any price increases will only apply to new customers signing up after the effective date of the change.</li>
                    </ul>
                    <p>We will always provide a 30-day notice of any upcoming changes to our pricing structure.</p>',
                ],
                'Copyright & Legal' => [
                    'How Do I Contact Legal?'         => '
                    <p>For legal inquiries, copyright claims, or data privacy questions, you can contact our legal department directly. Please use the following methods to ensure your request is handled by the appropriate team.</p>
                    <p><strong>Legal Contact Information:</strong></p>
                    <ul>
                        <li><strong>Email:</strong> legal@yourcompany.com</li>
                        <li><strong>Mailing Address:</strong> [Your Company Name], Attn: Legal Department, [Your Address]</li>
                    </ul>
                    <p>To help us process your request faster, please include all relevant information, such as your account details, a description of the issue, and any supporting documentation.</p>',
                    'Where Are Your Offices Located?' => '
                    <p>Our main corporate office is located at:</p>
                    <p><strong>[Your Company Name]</strong><br>
                    123 Technology Drive<br>
                    Suite 100<br>
                    Cityville, State 12345<br>
                    Country</p>
                    <p>We have several other branch offices and development centers globally. Please note that visits are by appointment only. For business inquiries, please contact our sales team first.</p>',
                    'Who Owns The Copyright On Text?' => '
                    <p>As per our Terms of Service, you retain full ownership and copyright of any content you create and upload to our platform. We do not claim any ownership rights over your intellectual property.</p>
                    <p>By using our service, you grant us a license to use, display, and distribute your content for the sole purpose of providing and operating our service, as described in our Terms of Service.</p>
                    <p>For more details, please review our <a href="#">Terms of Service</a> and <a href="#">Copyright Policy</a>.</p>',
                    'Our Content Policy'              => '
                    <p>Our content policy is designed to ensure a safe and respectful environment for all users. We prohibit the posting of content that is:</p>
                    <ul>
                        <li>Illegal or promotes illegal acts.</li>
                        <li>Harmful, threatening, or harassing.</li>
                        <li>Infringing on copyright or intellectual property rights.</li>
                        <li>Sexually explicit or obscene.</li>
                    </ul>
                    <p>We reserve the right to remove any content that violates this policy and may suspend or terminate accounts that repeatedly violate these rules. You can report violations through our support channels.</p>',
                    'How Do I File A DMCA?'           => '
                    <p>We take copyright infringement seriously. If you believe your copyrighted work has been posted on our service without your authorization, you can file a Digital Millennium Copyright Act (DMCA) notice.</p>
                    <p><strong>To file a DMCA notice, you must include the following information:</strong></p>
                    <ul>
                        <li>Your name, address, and electronic signature.</li>
                        <li>Identification of the copyrighted work you claim has been infringed.</li>
                        <li>Identification of the material that is claimed to be infringing, with enough detail for us to locate it.</li>
                        <li>A statement that you have a good faith belief that the use is not authorized by the copyright owner.</li>
                        <li>A statement, made under penalty of perjury, that the information in your notice is accurate and that you are the copyright owner or authorized to act on behalf of the owner.</li>
                    </ul>
                    <p>Please send your complete DMCA notice to: <a href="mailto:dmca@yourcompany.com">dmca@yourcompany.com</a></p>',
                ],
                'Mobile Apps'       => [
                    'How Do I Download The Android App?' => '
                    <p>Our official Android app is available for download from the Google Play Store. It is compatible with all devices running Android 5.0 (Lollipop) or newer.</p>
                    <p><strong>To download the app:</strong></p>
                    <ol>
                        <li>Open the **Google Play Store** on your device.</li>
                        <li>Search for "[Your App Name]".</li>
                        <li>Tap **Install** and wait for the download to complete.</li>
                    </ol>
                    <p>You can also use this direct link: <a href="https://play.google.com/store/apps/details?id=your.app.package" target="_blank">Download for Android</a></p>
                    <p>Make sure you are connected to a Wi-Fi network to avoid data charges.</p>',
                    'How To Download Our iPad App'       => '
                    <p>You can download our iPad app directly from the Apple App Store. The app is optimized for iPad displays and requires iOS 11 or later.</p>
                    <p><strong>To download the app:</strong></p>
                    <ol>
                        <li>Open the **App Store** on your iPad.</li>
                        <li>Search for "[Your App Name]".</li>
                        <li>Tap the **Get** button and confirm your download with your Apple ID password or Touch ID.</li>
                    </ol>
                    <p>You can also find the app by using this direct link: <a href="https://apps.apple.com/us/app/your-app-name/id123456789" target="_blank">Download for iPad</a></p>',
                    'Where Can I Upload My Avatar?'      => '
                    <p>Your avatar is a great way to personalize your profile! To upload a new avatar, simply go to your profile settings page.</p>
                    <p><strong>Steps to upload your avatar:</strong></p>
                    <ol>
                        <li>Log in to your account and go to your **Profile** page.</li>
                        <li>Look for the current avatar image and click on the **Edit** or **Upload** button.</li>
                        <li>Select an image file from your computer. We support JPG, PNG, and GIF formats.</li>
                        <li>Adjust the crop area if necessary and click **Save**.</li>
                    </ol>
                    <p>Your new avatar will now be visible to other users on the platform.</p>',
                    'Can I Use My Android Phone?'        => '
                    <p>Yes! Our Android app is designed to work seamlessly on both Android smartphones and tablets. It provides all the core features of our service in a mobile-optimized interface.</p>
                    <p>The app is compatible with most Android devices running Android 5.0 (Lollipop) or newer. For the best experience, we recommend using a device with a modern processor and at least 2GB of RAM.</p>
                    <p>You can download the app from the Google Play Store to get started.</p>',
                    'Is There An iOS App?'               => '
                    <p>Yes, we have an official app for both iPhone and iPad available in the Apple App Store. It is designed to provide a full-featured experience on iOS devices.</p>
                    <p><strong>Features of the iOS app include:</strong></p>
                    <ul>
                        <li>Real-time notifications.</li>
                        <li>Offline access to saved content.</li>
                        <li>A user-friendly interface optimized for touch.</li>
                        <li>Secure login with Face ID or Touch ID.</li>
                    </ul>
                    <p>Search for "[Your App Name]" on the App Store to download it today.</p>',
                ],
                'Using KnowHow'     => [
                    'Customization'            => '
                    <p>The KnowHow theme is highly customizable, allowing you to tailor its appearance to match your brand. You can modify various elements, including colors, fonts, and layout options.</p>
                    <p><strong>Key Customization Areas:</strong></p>
                    <ul>
                        <li><strong>Theme Settings:</strong> Use the built-in theme customizer to change colors, typography, and logo.</li>
                        <li><strong>CSS Overrides:</strong> For advanced changes, you can add custom CSS to override default styles without modifying the core theme files.</li>
                        <li><strong>Templates:</strong> You can create and modify your own Blade templates to change the structure of pages.</li>
                    </ul>
                    <p>Refer to the theme\'s documentation for a complete guide on all available customization options.</p>',
                    'Common Questions'         => '
                    <p>Here is a list of some of the most common questions we receive from users. We hope this helps you find answers quickly!</p>
                    <ul>
                        <li>How do I reset my password?</li>
                        <li>What payment methods do you accept?</li>
                        <li>Where can I find the API documentation?</li>
                        <li>How do I contact support?</li>
                    </ul>
                    <p>You can use the search bar above to find more specific answers.</p>',
                    'Setup for Envato Authors' => '
                    <p>If you are an Envato author, setting up your account is a simple process. This guide will walk you through the necessary steps to link your Envato account to your profile.</p>
                    <p><strong>Steps for Envato Authors:</strong></p>
                    <ol>
                        <li>Go to your profile settings.</li>
                        <li>Locate the "Envato API" section.</li>
                        <li>Enter your Envato username and a valid API key from your Envato account.</li>
                        <li>Save your changes.</li>
                    </ol>
                    <p>Once linked, our system can automatically verify your purchases and provide you with better support.  </p>',
                    'Pushover Notifications'   => '
                    <p>Pushover is a service that sends push notifications to your devices. You can integrate it with our platform to receive real-time notifications about important events in your account, such as new ticket replies or system alerts.</p>
                    <p><strong>How to set up Pushover:</strong></p>
                    <ol>
                        <li>Sign up for a Pushover account and get your User Key.</li>
                        <li>Go to your **Notification Settings** on our platform.</li>
                        <li>Enter your Pushover User Key in the designated field.</li>
                        <li>Save your settings.</li>
                    </ol>
                    <p>You will now receive push notifications on all devices where the Pushover app is installed.</p>',
                    'Ticket Shortcut Keys'     => '
                    <p>For users who handle support tickets frequently, we\'ve added several keyboard shortcuts to speed up your workflow. These shortcuts can help you navigate, reply, and manage tickets more efficiently.</p>
                    <p><strong>Available Shortcuts:</strong></p>
                    <ul>
                        <li>`R` - Reply to the current ticket.</li>
                        <li>`B` - Go back to the ticket list.</li>
                        <li>`Ctrl + Enter` - Submit a reply.</li>
                    </ul>
                    <p>These shortcuts are active when you are viewing a single ticket and can be a huge time-saver.  </p>',
                ],
            ];

            // Seed the specific articles from the images
            foreach ($articlesData as $categoryName => $articles) {
                $category = SupportCategory::where('name', $categoryName)->first();

                foreach ($articles as $title => $content) {
                    // Determine if the article should be featured based on its title from the "featured articles" image
                    $isFeatured = in_array($title, [
                        'Common Questions',
                        'Setup for Envato Authors',
                        'Pushover Notifications',
                        'Ticket Shortcut Keys',
                    ]);

                    // Check if the article already exists to prevent duplicates on re-seeding
                    if ( ! SupportArticle::where('title', $title)->exists()) {
                        SupportArticle::create([
                            'uid'          => (string) Str::uuid(),
                            'title'        => $title,
                            'slug'         => Str::slug($title),
                            'content'      => $content,
                            'category_id'  => $category->id,
                            'created_by'   => $faker->randomElement($userIds),
                            'is_published' => true,
                            'is_featured'  => $isFeatured, // Mark as featured if its title matches
                            'views'        => $faker->numberBetween(100, 1500),
                            'created_at'   => Carbon::now()->subDays(rand(1, 365)),
                            'updated_at'   => Carbon::now()->subDays(rand(0, 30)),
                        ]);
                    }
                }
            }


            $faker = Faker::create();

            // Disable foreign key checks for truncation to avoid issues with dependencies
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            TicketAttachment::truncate();
            TicketReply::truncate();
            DB::table('ticket_ticket_tag')->truncate(); // Pivot table for many-to-many
            SupportTicket::truncate();
            TicketTags::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');


            // --- Fetch existing users ---
            $superAdmin     = User::where('email', 'admin@codeglen.com')->first();
            $supervisor     = User::where('email', 'shamim97@gmail.com')->first();
            $mainCustomer   = User::where('email', 'customer@codeglen.com')->first();
            $otherCustomers = User::where('email', '!=', 'admin@codeglen.com')
                ->where('email', '!=', 'shamim97@gmail.com')
                ->where('email', '!=', 'customer@codeglen.com')
                ->get();

            if ( ! $superAdmin || ! $supervisor || ! $mainCustomer) {

                return;
            }

            $agents = [$superAdmin, $supervisor];
            // FIX: Ensure all customers are Eloquent model objects by using a collection and `push`
            $customers = $otherCustomers->push($mainCustomer)->shuffle()->all();


            // --- Fetch Support Categories ---
            $categories = SupportCategory::all();

            if ($categories->isEmpty()) {

                $this->call(SupportCategorySeeder::class);
                $categories = SupportCategory::all();
            }

            if ($categories->isEmpty()) {

                return;
            }


            // --- Create Ticket Tags ---
            $tagNames = [
                ['name' => 'Urgent', 'color' => '#dc3545'],
                ['name' => 'High Priority', 'color' => '#ffc107'],
                ['name' => 'Documentation', 'color' => '#17a2b8'],
                ['name' => 'Installation', 'color' => '#28a745'],
                ['name' => 'Bug', 'color' => '#6f42c1'],
                ['name' => 'Feature', 'color' => '#007bff'],
                ['name' => 'Resolved', 'color' => '#28a745'],
                ['name' => 'Pending Customer', 'color' => '#fd7e14'],
            ];

            $tags = [];
            foreach ($tagNames as $tagData) {
                $tag    = TicketTags::firstOrCreate(
                    ['name' => $tagData['name']],
                    ['color' => $tagData['color']]
                );
                $tags[] = $tag;
            }


            // --- Define Priorities ---
            $priorities = ['low', 'medium', 'high', 'urgent'];

            // --- Generate data for the last 30 days ---
            $today     = Carbon::today();
            $startDate = $today->copy()->subDays(29);

            // --- Specific counts for today and yesterday to ensure desired trends ---
            $targetNewTicketsToday          = 20;
            $targetNewTicketsYesterday      = 15;
            $targetAgentRepliesToday        = 15;
            $targetAgentRepliesYesterday    = 10;
            $targetResolvedTicketsToday     = 8;
            $targetResolvedTicketsYesterday = 8;
            $targetClosedTicketsToday       = 5;
            $targetClosedTicketsYesterday   = 8;

            // --- Realistic Content Data ---
            $ticketContent = [
                'subject'           => [
                    'Payment processing failed for my subscription',
                    'Website is showing an error on checkout page',
                    'How to customize the theme color?',
                    'Need help with API key authentication',
                    'My account is locked after multiple login attempts',
                    'Request for a new feature in the dashboard',
                    'Bug report: search function is not working',
                    'I cannot upload images to my profile',
                    'Question about the annual billing plan',
                    'Issue with mobile app login on iOS',
                    'Documentation for a specific API endpoint is missing',
                    'My order status is stuck in "pending"',
                    'Can I get a refund for my recent purchase?',
                    'How do I integrate with Zapier?',
                    'I am getting a 404 error on a specific page',
                    'My account details are incorrect',
                ],
                'customer_messages' => [
                    'Hi, I was trying to renew my subscription, but the payment failed. Can you please check what the issue is? My card details are correct.',
                    'I\'m getting a `500 Server Error` on the checkout page. I have tried clearing my cache but it still persists. Please assist!',
                    'I want to change the primary color of the theme to my brand color. I looked in the settings but couldn\'t find the option. Is there a way to do this?',
                    'I\'m building an application using your API but keep getting an `Invalid API Key` error. I double-checked the key and it seems fine. What could be wrong?',
                    'It looks like my account has been locked. I might have entered the wrong password a few times. Can you unlock it for me? My username is john.doe.',
                    'I have a great idea for a new feature! It would be really helpful if the dashboard had an export-to-PDF option for reports. Could you consider adding this?',
                    'The search bar on the knowledge base page is not working. When I type anything and hit enter, nothing happens. This seems like a bug.',
                    'Whenever I try to upload my profile picture, I get an error saying `File too large`. The image is only 2MB. What\'s the maximum size?',
                    'I\'m trying to decide between the annual and monthly billing plans. Could you explain the main differences and benefits of each, besides the price?',
                    'I recently updated my iPhone to the latest iOS, and now I can\'t log into the app. It just shows a loading spinner forever. Is this a known issue?',
                    'The documentation for the `/v1/users` endpoint seems to be incomplete. I need to know the parameters for the PATCH request. Where can I find this info?',
                ],
                'agent_replies'     => [
                    'Hello! Thanks for reaching out. Could you please provide the last 4 digits of the card you are using? We will check the payment gateway logs for the specific error.',
                    'Thanks for the report! This sounds like a theme-related issue. You can change the primary color by going to **Appearance -> Customize -> Colors** in your admin dashboard. Let me know if you need more help.',
                    'I\'ve checked our logs, and it seems there was a temporary issue with our payment processor. I have cleared your previous payment attempt. Please try again now.',
                    'For API authentication, please ensure you are sending your API key in the `X-API-Key` header. Also, remember that keys are case-sensitive. Let me know if that works!',
                    'I have reviewed your account and successfully unlocked it. Please try logging in now. If you continue to have issues, you can use the "Forgot Password" link.',
                    'That\'s a great suggestion! I have forwarded your feature request to our product team for consideration. We appreciate your feedback.',
                    'Thank you for reporting this bug. Our development team is aware of the search issue and is currently working on a fix. We will update you as soon as it\'s deployed.',
                    'The maximum file size for avatars is 1MB. Please try compressing your image before uploading. We recommend using a tool like TinyPNG to optimize your images.',
                    'Our annual plan offers a significant discount compared to the monthly plan. It\'s ideal if you plan to use our service long-term. You get 2 months free when you pay annually.',
                ],
            ];

            for ($day = 0; $day < 30; $day++) {
                $currentDate = $startDate->copy()->addDays($day);

                $isToday     = $currentDate->isToday();
                $isYesterday = $currentDate->isYesterday();

                $newTicketsCountForThisDay      = $faker->numberBetween(10, 25);
                $agentRepliesCountForThisDay    = $faker->numberBetween(5, 20);
                $resolvedTicketsCountForThisDay = $faker->numberBetween(3, 12);
                $closedTicketsCountForThisDay   = $faker->numberBetween(2, 8);

                if ($isToday) {
                    $newTicketsCountForThisDay      = $targetNewTicketsToday;
                    $agentRepliesCountForThisDay    = $targetAgentRepliesToday;
                    $resolvedTicketsCountForThisDay = $targetResolvedTicketsToday;
                    $closedTicketsCountForThisDay   = $targetClosedTicketsToday;
                } else if ($isYesterday) {
                    $newTicketsCountForThisDay      = $targetNewTicketsYesterday;
                    $agentRepliesCountForThisDay    = $targetAgentRepliesYesterday;
                    $resolvedTicketsCountForThisDay = $targetResolvedTicketsYesterday;
                    $closedTicketsCountForThisDay   = $targetClosedTicketsYesterday;
                }

                $ticketsToCreateThisDay = max(
                    $newTicketsCountForThisDay,
                    $resolvedTicketsCountForThisDay,
                    $closedTicketsCountForThisDay,
                    (int) ceil($agentRepliesCountForThisDay / 1.5)
                );

                $createdAgentReplies    = 0;
                $createdResolvedTickets = 0;
                $createdClosedTickets   = 0;

                for ($i = 0; $i < $ticketsToCreateThisDay; $i++) {
                    $customer = $faker->randomElement($customers);
                    $agent    = $faker->boolean(70) ? $faker->randomElement($agents) : null;
                    $category = $faker->randomElement($categories);
                    $priority = $faker->randomElement($priorities);

                    if ($createdClosedTickets < $closedTicketsCountForThisDay) {
                        $status = 'closed';
                        $createdClosedTickets++;
                    } else if ($createdResolvedTickets < $resolvedTicketsCountForThisDay) {
                        $status = 'resolved';
                        $createdResolvedTickets++;
                    } else {
                        $status = $faker->randomElement(['open', 'pending']);
                    }

                    $ticketCreatedAt = $currentDate->copy()->addHours($faker->numberBetween(0, 23))->addMinutes($faker->numberBetween(0, 59))->addSeconds($faker->numberBetween(0, 59));
                    $ticketUpdatedAt = $faker->dateTimeBetween($ticketCreatedAt, $currentDate->copy()->endOfDay());
                    $closedAt        = null;

                    if ($status === 'closed') {
                        $closedAt = Carbon::parse($faker->dateTimeBetween($ticketUpdatedAt, $currentDate->copy()->endOfDay()));
                    }

                    $subjectIndex   = array_rand($ticketContent['subject']);
                    $subject        = $ticketContent['subject'][$subjectIndex];
                    $initialMessage = $ticketContent['customer_messages'][$subjectIndex] ?? $faker->paragraph(mt_rand(2, 5));

                    $ticket = SupportTicket::create([
                        'uid'             => (string) Str::uuid(),
                        'subject'         => $subject,
                        'status'          => $status,
                        'priority'        => $priority,
                        'customer_id'     => $customer->id,
                        'agent_id'        => $agent ? $agent->id : null,
                        'category_id'     => $category->id,
                        'created_by'      => $customer->id,
                        'last_replied_by' => $customer->id,
                        'note'            => $faker->optional(0.3)->paragraph,
                        'customer_note'   => null,
                        'is_public'       => $faker->boolean(20),
                        'mark_starred'    => $faker->boolean(15),
                        'last_replied_at' => $ticketCreatedAt,
                        'closed_at'       => $closedAt,
                        'created_at'      => $ticketCreatedAt,
                        'updated_at'      => $ticketUpdatedAt,
                    ]);

                    TicketReply::create([
                        'ticket_id'         => $ticket->id,
                        'user_id'           => $customer->id,
                        'is_customer_reply' => true,
                        'message'           => $initialMessage,
                        'created_at'        => $ticketCreatedAt,
                        'updated_at'        => $ticketCreatedAt,
                    ]);

                    $ticket->tags()->attach(collect($faker->randomElements($tags, mt_rand(1, min(3, count($tags)))))->pluck('id'));

                    $numRepliesForTicket = $faker->numberBetween(0, 3);
                    $lastReplyTime       = $ticketCreatedAt;

                    for ($j = 0; $j < $numRepliesForTicket; $j++) {
                        $isCustomerReply = $faker->boolean();
                        if ($createdAgentReplies < $agentRepliesCountForThisDay) {
                            $isCustomerReply = false;
                        }

                        $replier        = $isCustomerReply ? $customer : ($agent ?? $superAdmin);
                        $replyCreatedAt = Carbon::parse($faker->dateTimeBetween($lastReplyTime, $ticketUpdatedAt));

                        $replyMessage = $isCustomerReply ? $faker->randomElement($ticketContent['customer_messages']) : $faker->randomElement($ticketContent['agent_replies']);

                        $reply         = TicketReply::create([
                            'ticket_id'         => $ticket->id,
                            'user_id'           => $replier->id,
                            'is_customer_reply' => $isCustomerReply,
                            'message'           => $replyMessage,
                            'created_at'        => $replyCreatedAt,
                            'updated_at'        => $replyCreatedAt,
                        ]);
                        $lastReplyTime = $replyCreatedAt;

                        if ( ! $isCustomerReply) {
                            $createdAgentReplies++;
                        }

                        $numAttachments = mt_rand(0, 1);
                        if ($numAttachments > 0) {
                            if ( ! Storage::disk('public')->exists('ticket_attachments')) {
                                Storage::disk('public')->makeDirectory('ticket_attachments');
                            }
                            $dummyContent = 'This is a dummy attachment file content for ' . $reply->message;
                            $filename     = Str::random(10) . '.txt';
                            $filepath     = 'ticket_attachments/' . $filename;
                            Storage::disk('public')->put($filepath, $dummyContent);

                            TicketAttachment::create([
                                'ticket_id'  => $ticket->id,
                                'reply_id'   => $reply->id,
                                'filename'   => $filename,
                                'filepath'   => $filepath,
                                'mime_type'  => 'text/plain',
                                'file_size'  => strlen($dummyContent),
                                'created_at' => $reply->created_at,
                                'updated_at' => $reply->created_at,
                            ]);
                        }
                    }
                }
            }

        }

    }
