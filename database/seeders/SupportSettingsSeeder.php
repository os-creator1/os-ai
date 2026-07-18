<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('support_settings')->insert([
            'desk_name' => 'Support Desk',
            'desk_email' => 'support@example.com',
            'auto_reassign_ticket' => true,
            'filter_articles_by_category' => true,
            'disable_public_tickets' => false,
            'public_ticket_default' => true,
            'order_tickets_asc' => false,
            'group_tickets_by_updated_date' => false,
            'enable_autoresponder' => false,
            'enable_email_tickets' => false,
            'important_notice' => 'Welcome to our support system!',
            'private_ticketing' => false,
            'limit_support_agents' => false,
            'hide_customers_from_agents' => false,
            'hide_purchase_data_from_agents' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
