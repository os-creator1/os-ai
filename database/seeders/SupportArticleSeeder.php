<?php

namespace Database\Seeders;

use Codeglen\Usupport\Models\SupportArticle;
use Codeglen\Usupport\Models\SupportCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Faker\Factory as Faker;

class SupportArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker::create();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        SupportArticle::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Cleared previous support article data.');

        $userIds = User::pluck('id')->toArray();

        if (empty($userIds)) {
            $this->command->error('No users found. Please ensure users are seeded first.');
            return;
        }

        // Fetch categories to assign articles
        $accountSettingsCategory = SupportCategory::where('name', 'Account Settings')->first();
        $apiQuestionsCategory = SupportCategory::where('name', 'API Questions')->first();
        $billingCategory = SupportCategory::where('name', 'Billing')->first();
        $copyrightLegalCategory = SupportCategory::where('name', 'Copyright & Legal')->first();
        $mobileAppsCategory = SupportCategory::where('name', 'Mobile Apps')->first();
        $usingKnowHowCategory = SupportCategory::where('name', 'Using KnowHow')->first();

        // Check if categories exist before proceeding
        if (!$accountSettingsCategory || !$apiQuestionsCategory || !$billingCategory || !$copyrightLegalCategory || !$mobileAppsCategory || !$usingKnowHowCategory) {
            $this->command->error('One or more support categories not found. Please run SupportCategorySeeder first.');
            return;
        }

        // Define all articles from the images, categorized
        $articlesData = [
            'Account Settings' => [
                'How Secure Is My Password?' => '
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
                'Can I Change My Username?' => '
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
                'How Do I Change My Timezone?' => '
                    <p>To ensure all timestamps in your account are accurate for your location, you can easily update your timezone in your account settings. This affects notifications, scheduling, and other time-sensitive features.</p>
                    <p><strong>To change your timezone:</strong></p>
                    <ol>
                        <li>Go to **Account Settings**.</li>
                        <li>Find the **Timezone** dropdown menu.</li>
                        <li>Select your correct timezone from the list.</li>
                        <li>Click **Save Changes**.</li>
                    </ol>
                    <p>Your settings will be updated immediately, and all future timestamps will reflect your new timezone.</p>',
                'How Do I Change My Password?' => '
                    <p>If you need to change your password for security reasons or simply because you forgot it, follow these steps:</p>
                    <ol>
                        <li>Go to your **Account Settings**.</li>
                        <li>Select the **Password & Security** tab.</li>
                        <li>Enter your current password, then enter your new password twice to confirm.</li>
                        <li>Click **Update Password**.</li>
                    </ol>
                    <p>If you have forgotten your current password, please use the "Forgot Password" link on the login page to reset it via email.</p>'
            ],
            'API Questions' => [
                'What Technologies Are Used?' => '
                    <p>Our API is built on a modern, robust technology stack to ensure reliability, security, and high performance. We utilize a combination of technologies for our backend, including:</p>
                    <ul>
                        <li><strong>Backend:</strong> Laravel PHP framework.</li>
                        <li><strong>Database:</strong> PostgreSQL for high-performance data management.</li>
                        <li><strong>API Endpoints:</strong> RESTful architecture for easy integration.</li>
                        <li><strong>Authentication:</strong> OAuth 2.0 and API keys for secure access.</li>
                    </ul>
                    <p>These technologies allow us to provide a scalable and secure service to all our developers.</p>',
                'What Are The API Limits?' => '
                    <p>To ensure fair usage and system stability, our API has certain rate limits. These limits are designed to protect our service from abuse and ensure a great experience for all users.</p>
                    <p><strong>Standard Rate Limits:</strong></p>
                    <ul>
                        <li><strong>Requests per minute:</strong> 60 requests.</li>
                        <li><strong>Requests per hour:</strong> 3,600 requests.</li>
                    </ul>
                    <p>If you exceed these limits, your requests will receive a `429 Too Many Requests` error. Please design your applications to handle this error gracefully, and consider using exponential backoff for retries.</p>
                    <p>For higher limits, please contact our sales team to discuss enterprise-level plans.</p>',
                'Why Was My Application Rejected?' => '
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
                'How Do I Get An API Key?' => '
                    <p>To get your own API key, you must first register as a developer. This process ensures that we can manage and secure access to our API for everyone.</p>
                    <p><strong>Steps to get an API key:</strong></p>
                    <ol>
                        <li>Sign up for a developer account on our platform.</li>
                        <li>Complete the developer application form, describing your planned usage.</li>
                        <li>Once your application is approved, you will find your API key in your developer dashboard.</li>
                    </ol>
                    <p>Please keep your API key secure and never share it publicly. If your key is compromised, you should regenerate it immediately.</p>'
            ],
            'Billing' => [
                'Can I Contact A Sales Rep?' => '
                    <p>Yes, our sales team is ready to help with any questions you have about our pricing, plans, or enterprise solutions. Whether you\'re a large business or need custom features, we can tailor a plan that works for you.</p>
                    <p>You can schedule a call or send an inquiry through our contact page. Our representatives will get back to you within one business day.</p>
                    <p><strong>Contact Options:</strong></p>
                    <ul>
                        <li><strong>Sales Inquiry Form:</strong> Fill out our form <a href="#">here</a>.</li>
                        <li><strong>Email:</strong> sales@yourcompany.com</li>
                        <li><strong>Phone:</strong> +1 (555) 123-4567</li>
                    </ul>',
                'Do I Need To Pay VAT?' => '
                    <p>The requirement to pay Value Added Tax (VAT) depends on your location and business status. Our system automatically calculates VAT based on your country and the information you provide in your billing profile.</p>
                    <p><strong>Common Scenarios:</strong></p>
                    <ul>
                        <li><strong>EU Businesses with a valid VAT ID:</strong> You may be exempt from VAT. Please ensure your VAT ID is correctly entered in your billing details.</li>
                        <li><strong>EU Individuals:</strong> VAT will be applied to your invoice at your local rate.</li>
                        <li><strong>Non-EU Customers:</strong> VAT is generally not applied.</li>
                    </ul>
                    <p>Please consult with a tax professional if you have specific questions about your tax obligations.</p>',
                'Can I Get A Refund?' => '
                    <p>Our refund policy is based on the service you have purchased. Generally, we offer refunds under specific circumstances, such as a billing error or if you cancel within a certain trial period.</p>
                    <p><strong>To request a refund:</strong></p>
                    <ol>
                        <li>Log in to your account and go to the **Billing** section.</li>
                        <li>Open a support ticket and select "Refund Request" as the subject.</li>
                        <li>Provide your invoice number and a brief explanation for the request.</li>
                    </ol>
                    <p>Our billing team will review your request and respond as soon as possible. Please note that refunds are not guaranteed for all services.</p>',
                'Difference Annual & Monthly Billing' => '
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
                    <p>We will always provide a 30-day notice of any upcoming changes to our pricing structure.</p>'
            ],
            'Copyright & Legal' => [
                'How Do I Contact Legal?' => '
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
                'Our Content Policy' => '
                    <p>Our content policy is designed to ensure a safe and respectful environment for all users. We prohibit the posting of content that is:</p>
                    <ul>
                        <li>Illegal or promotes illegal acts.</li>
                        <li>Harmful, threatening, or harassing.</li>
                        <li>Infringing on copyright or intellectual property rights.</li>
                        <li>Sexually explicit or obscene.</li>
                    </ul>
                    <p>We reserve the right to remove any content that violates this policy and may suspend or terminate accounts that repeatedly violate these rules. You can report violations through our support channels.</p>',
                'How Do I File A DMCA?' => '
                    <p>We take copyright infringement seriously. If you believe your copyrighted work has been posted on our service without your authorization, you can file a Digital Millennium Copyright Act (DMCA) notice.</p>
                    <p><strong>To file a DMCA notice, you must include the following information:</strong></p>
                    <ul>
                        <li>Your name, address, and electronic signature.</li>
                        <li>Identification of the copyrighted work you claim has been infringed.</li>
                        <li>Identification of the material that is claimed to be infringing, with enough detail for us to locate it.</li>
                        <li>A statement that you have a good faith belief that the use is not authorized by the copyright owner.</li>
                        <li>A statement, made under penalty of perjury, that the information in your notice is accurate and that you are the copyright owner or authorized to act on behalf of the owner.</li>
                    </ul>
                    <p>Please send your complete DMCA notice to: <a href="mailto:dmca@yourcompany.com">dmca@yourcompany.com</a></p>'
            ],
            'Mobile Apps' => [
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
                'How To Download Our iPad App' => '
                    <p>You can download our iPad app directly from the Apple App Store. The app is optimized for iPad displays and requires iOS 11 or later.</p>
                    <p><strong>To download the app:</strong></p>
                    <ol>
                        <li>Open the **App Store** on your iPad.</li>
                        <li>Search for "[Your App Name]".</li>
                        <li>Tap the **Get** button and confirm your download with your Apple ID password or Touch ID.</li>
                    </ol>
                    <p>You can also find the app by using this direct link: <a href="https://apps.apple.com/us/app/your-app-name/id123456789" target="_blank">Download for iPad</a></p>',
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
                'Can I Use My Android Phone?' => '
                    <p>Yes! Our Android app is designed to work seamlessly on both Android smartphones and tablets. It provides all the core features of our service in a mobile-optimized interface.</p>
                    <p>The app is compatible with most Android devices running Android 5.0 (Lollipop) or newer. For the best experience, we recommend using a device with a modern processor and at least 2GB of RAM.</p>
                    <p>You can download the app from the Google Play Store to get started.</p>',
                'Is There An iOS App?' => '
                    <p>Yes, we have an official app for both iPhone and iPad available in the Apple App Store. It is designed to provide a full-featured experience on iOS devices.</p>
                    <p><strong>Features of the iOS app include:</strong></p>
                    <ul>
                        <li>Real-time notifications.</li>
                        <li>Offline access to saved content.</li>
                        <li>A user-friendly interface optimized for touch.</li>
                        <li>Secure login with Face ID or Touch ID.</li>
                    </ul>
                    <p>Search for "[Your App Name]" on the App Store to download it today.</p>'
            ],
            'Using KnowHow' => [
                'Customization' => '
                    <p>The KnowHow theme is highly customizable, allowing you to tailor its appearance to match your brand. You can modify various elements, including colors, fonts, and layout options.</p>
                    <p><strong>Key Customization Areas:</strong></p>
                    <ul>
                        <li><strong>Theme Settings:</strong> Use the built-in theme customizer to change colors, typography, and logo.</li>
                        <li><strong>CSS Overrides:</strong> For advanced changes, you can add custom CSS to override default styles without modifying the core theme files.</li>
                        <li><strong>Templates:</strong> You can create and modify your own Blade templates to change the structure of pages.</li>
                    </ul>
                    <p>Refer to the theme\'s documentation for a complete guide on all available customization options.</p>',
                'Common Questions' => '
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
                'Pushover Notifications' => '
                    <p>Pushover is a service that sends push notifications to your devices. You can integrate it with our platform to receive real-time notifications about important events in your account, such as new ticket replies or system alerts.</p>
                    <p><strong>How to set up Pushover:</strong></p>
                    <ol>
                        <li>Sign up for a Pushover account and get your User Key.</li>
                        <li>Go to your **Notification Settings** on our platform.</li>
                        <li>Enter your Pushover User Key in the designated field.</li>
                        <li>Save your settings.</li>
                    </ol>
                    <p>You will now receive push notifications on all devices where the Pushover app is installed.</p>',
                'Ticket Shortcut Keys' => '
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
                if (!SupportArticle::where('title', $title)->exists()) {
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

        $this->command->info('Support Articles seeds successfully');
    }
}
