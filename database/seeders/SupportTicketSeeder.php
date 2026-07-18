<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Codeglen\Usupport\Database\Seeders\SupportCategorySeeder;
use Codeglen\Usupport\Models\SupportCategory;
use Codeglen\Usupport\Models\SupportTicket;
use Codeglen\Usupport\Models\TicketAttachment;
use Codeglen\Usupport\Models\TicketReply;
use Codeglen\Usupport\Models\TicketTags;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportTicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Disable foreign key checks for truncation to avoid issues with dependencies
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        TicketAttachment::truncate();
        TicketReply::truncate();
        DB::table('ticket_ticket_tag')->truncate(); // Pivot table for many-to-many
        SupportTicket::truncate();
        TicketTags::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Cleared previous support ticket related data (excluding categories).');

        // --- Fetch existing users ---
        $superAdmin   = User::where('email', 'admin@codeglen.com')->first();
        $supervisor   = User::where('email', 'shamim97@gmail.com')->first();
        $mainCustomer = User::where('email', 'customer@codeglen.com')->first();
        $otherCustomers = User::where('email', '!=', 'admin@codeglen.com')
            ->where('email', '!=', 'shamim97@gmail.com')
            ->where('email', '!=', 'customer@codeglen.com')
            ->get();

        if (!$superAdmin || !$supervisor || !$mainCustomer) {
            $this->command->error('Pre-defined admin/agent/customer users not found. Please run your User/Admin Seeder first.');
            return;
        }

        $agents = [$superAdmin, $supervisor];
        // FIX: Ensure all customers are Eloquent model objects by using a collection and `push`
        $customers = $otherCustomers->push($mainCustomer)->shuffle()->all();

        $allUsers = array_merge($agents, $customers);

        $this->command->info('Fetched existing agents and customers.');

        // --- Fetch Support Categories ---
        $categories = SupportCategory::all();

        if ($categories->isEmpty()) {
            $this->command->warn('No categories found. Calling SupportCategorySeeder...');
            $this->call(SupportCategorySeeder::class);
            $categories = SupportCategory::all();
        }

        if ($categories->isEmpty()) {
            $this->command->error('No support categories found. Please ensure categories are seeded before tickets.');
            return;
        }

        $this->command->info('Support Categories fetched.');

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
            $tag = TicketTags::firstOrCreate(
                ['name' => $tagData['name']],
                ['color' => $tagData['color']]
            );
            $tags[] = $tag;
        }
        $this->command->info('Ticket Tags created.');

        // --- Define Priorities ---
        $priorities = ['low', 'medium', 'high', 'urgent'];

        // --- Generate data for the last 30 days ---
        $today = Carbon::today();
        $startDate = $today->copy()->subDays(29);

        // --- Specific counts for today and yesterday to ensure desired trends ---
        $targetNewTicketsToday = 20;
        $targetNewTicketsYesterday = 15;
        $targetAgentRepliesToday = 15;
        $targetAgentRepliesYesterday = 10;
        $targetResolvedTicketsToday = 8;
        $targetResolvedTicketsYesterday = 8;
        $targetClosedTicketsToday = 5;
        $targetClosedTicketsYesterday = 8;

        $totalTicketsCreated = 0;

        // --- Realistic Content Data ---
        $ticketContent = [
            'subject' => [
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
            'agent_replies' => [
                'Hello! Thanks for reaching out. Could you please provide the last 4 digits of the card you are using? We will check the payment gateway logs for the specific error.',
                'Thanks for the report! This sounds like a theme-related issue. You can change the primary color by going to **Appearance -> Customize -> Colors** in your admin dashboard. Let me know if you need more help.',
                'I\'ve checked our logs, and it seems there was a temporary issue with our payment processor. I have cleared your previous payment attempt. Please try again now.',
                'For API authentication, please ensure you are sending your API key in the `X-API-Key` header. Also, remember that keys are case-sensitive. Let me know if that works!',
                'I have reviewed your account and successfully unlocked it. Please try logging in now. If you continue to have issues, you can use the "Forgot Password" link.',
                'That\'s a great suggestion! I have forwarded your feature request to our product team for consideration. We appreciate your feedback.',
                'Thank you for reporting this bug. Our development team is aware of the search issue and is currently working on a fix. We will update you as soon as it\'s deployed.',
                'The maximum file size for avatars is 1MB. Please try compressing your image before uploading. We recommend using a tool like TinyPNG to optimize your images.',
                'Our annual plan offers a significant discount compared to the monthly plan. It\'s ideal if you plan to use our service long-term. You get 2 months free when you pay annually.',
            ]
        ];

        for ($day = 0; $day < 30; $day++) {
            $currentDate = $startDate->copy()->addDays($day);

            $isToday = $currentDate->isToday();
            $isYesterday = $currentDate->isYesterday();

            $newTicketsCountForThisDay = $faker->numberBetween(10, 25);
            $agentRepliesCountForThisDay = $faker->numberBetween(5, 20);
            $resolvedTicketsCountForThisDay = $faker->numberBetween(3, 12);
            $closedTicketsCountForThisDay = $faker->numberBetween(2, 8);

            if ($isToday) {
                $newTicketsCountForThisDay = $targetNewTicketsToday;
                $agentRepliesCountForThisDay = $targetAgentRepliesToday;
                $resolvedTicketsCountForThisDay = $targetResolvedTicketsToday;
                $closedTicketsCountForThisDay = $targetClosedTicketsToday;
            } elseif ($isYesterday) {
                $newTicketsCountForThisDay = $targetNewTicketsYesterday;
                $agentRepliesCountForThisDay = $targetAgentRepliesYesterday;
                $resolvedTicketsCountForThisDay = $targetResolvedTicketsYesterday;
                $closedTicketsCountForThisDay = $targetClosedTicketsYesterday;
            }

            $ticketsToCreateThisDay = max(
                $newTicketsCountForThisDay,
                $resolvedTicketsCountForThisDay,
                $closedTicketsCountForThisDay,
                (int)ceil($agentRepliesCountForThisDay / 1.5)
            );

            $createdAgentReplies = 0;
            $createdResolvedTickets = 0;
            $createdClosedTickets = 0;

            for ($i = 0; $i < $ticketsToCreateThisDay; $i++) {
                $customer = $faker->randomElement($customers);
                $agent = $faker->boolean(70) ? $faker->randomElement($agents) : null;
                $category = $faker->randomElement($categories);
                $priority = $faker->randomElement($priorities);

                if ($createdClosedTickets < $closedTicketsCountForThisDay) {
                    $status = 'closed';
                    $createdClosedTickets++;
                } elseif ($createdResolvedTickets < $resolvedTicketsCountForThisDay) {
                    $status = 'resolved';
                    $createdResolvedTickets++;
                } else {
                    $status = $faker->randomElement(['open', 'pending']);
                }

                $ticketCreatedAt = $currentDate->copy()->addHours($faker->numberBetween(0, 23))->addMinutes($faker->numberBetween(0, 59))->addSeconds($faker->numberBetween(0, 59));
                $ticketUpdatedAt = $faker->dateTimeBetween($ticketCreatedAt, $currentDate->copy()->endOfDay());
                $lastRepliedAt = $ticketUpdatedAt;
                $closedAt = null;

                if ($status === 'closed') {
                    $closedAt = Carbon::parse($faker->dateTimeBetween($ticketUpdatedAt, $currentDate->copy()->endOfDay()));
                }

                $subjectIndex = array_rand($ticketContent['subject']);
                $subject = $ticketContent['subject'][$subjectIndex];
                $initialMessage = $ticketContent['customer_messages'][$subjectIndex] ?? $faker->paragraph(mt_rand(2, 5));

                $ticket = SupportTicket::create([
                    'uid'             => (string)Str::uuid(),
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
                $lastReplyTime = $ticketCreatedAt;

                for ($j = 0; $j < $numRepliesForTicket; $j++) {
                    $isCustomerReply = $faker->boolean();
                    if ($createdAgentReplies < $agentRepliesCountForThisDay) {
                        $isCustomerReply = false;
                    }

                    $replier = $isCustomerReply ? $customer : ($agent ?? $superAdmin);
                    $replyCreatedAt = Carbon::parse($faker->dateTimeBetween($lastReplyTime, $ticketUpdatedAt));

                    $replyMessage = $isCustomerReply ? $faker->randomElement($ticketContent['customer_messages']) : $faker->randomElement($ticketContent['agent_replies']);

                    $reply = TicketReply::create([
                        'ticket_id'         => $ticket->id,
                        'user_id'           => $replier->id,
                        'is_customer_reply' => $isCustomerReply,
                        'message'           => $replyMessage,
                        'created_at'        => $replyCreatedAt,
                        'updated_at'        => $replyCreatedAt,
                    ]);
                    $lastReplyTime = $replyCreatedAt;

                    if (!$isCustomerReply) {
                        $createdAgentReplies++;
                    }

                    $numAttachments = mt_rand(0, 1);
                    if ($numAttachments > 0) {
                        if (!Storage::disk('public')->exists('ticket_attachments')) {
                            Storage::disk('public')->makeDirectory('ticket_attachments');
                        }
                        $dummyContent = 'This is a dummy attachment file content for ' . $reply->message;
                        $filename = Str::random(10) . '.txt';
                        $filepath = 'ticket_attachments/' . $filename;
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
            $totalTicketsCreated += $ticketsToCreateThisDay;
        }

        $this->command->info("{$totalTicketsCreated} Support Tickets with replies and attachments created for the last 30 days.");
        $this->command->info("New Tickets: Today (Target: {$targetNewTicketsToday}) vs Yesterday (Target: {$targetNewTicketsYesterday}) -- UP TREND");
        $this->command->info("Agent Replies: Today (Target: {$targetAgentRepliesToday}) vs Yesterday (Target: {$targetAgentRepliesYesterday}) -- UP TREND");
        $this->command->info("Resolved Tickets: Today (Target: {$targetResolvedTicketsToday}) vs Yesterday (Target: {$targetResolvedTicketsYesterday}) -- NO CHANGE TREND");
        $this->command->info("Closed Tickets: Today (Target: {$targetClosedTicketsToday}) vs Yesterday (Target: {$targetClosedTicketsYesterday}) -- DOWN TREND");
    }
}
