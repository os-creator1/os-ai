<?php

namespace Database\Seeders;

use App\Models\QuestionPack;
use Illuminate\Database\Seeder;

/**
 * Website Guided Generation contract §6.2-§6.4, seeded by this lane
 * (the base contract deliberately left seeding to "an operator" — this
 * task explicitly authorizes and requires it). Two packs: a general
 * local-service fallback (`key = 'general'`, both `applies_to_*`
 * columns null — the contracted fallback shape) and a Photo Booth pack
 * (`applies_to_industry = 'photo_booth_service'`). Every `field_key`
 * below is a real, existing BusinessKnowledgeProfileFieldKey case
 * (§5.1) — this seeder can only ever ask about facts the Profile
 * already knows how to store; QuestionPack's own model-event validation
 * enforces that at save time regardless.
 *
 * Deliberately asks nothing the canonical Business/Location/Service
 * tables already own (name, phone, email, hours, services, packages) —
 * only the Profile's own facts (task instruction: "Do not ask for data
 * already stored canonically elsewhere").
 */
class QuestionPackSeeder extends Seeder
{
    public function run(): void
    {
        QuestionPack::updateOrCreate(
            ['key' => 'general', 'version' => 1],
            [
                'applies_to_industry' => null,
                'applies_to_vertical_key' => null,
                'is_active' => true,
                'questions' => [
                    ['field_key' => 'ideal_customers', 'prompt' => 'Who is your ideal customer?', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'customer_problems', 'prompt' => 'What problems do customers come to you to solve?', 'input_type' => 'multi_select', 'options' => ['Cost', 'Speed', 'Quality', 'Convenience', 'Trust/reputation', 'Lack of expertise'], 'required' => false],
                    ['field_key' => 'differentiators', 'prompt' => 'What makes your business different from competitors?', 'input_type' => 'multi_select', 'options' => ['Experience', 'Price', 'Quality', 'Guarantee', 'Speed', 'Personal service'], 'required' => false],
                    ['field_key' => 'years_operating', 'prompt' => 'How many years has this business been operating?', 'input_type' => 'text', 'options' => null, 'required' => false],
                    ['field_key' => 'credentials', 'prompt' => 'What licenses, certifications, or credentials do you hold?', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'warranties_guarantees', 'prompt' => 'Do you offer any warranty or guarantee? Describe it.', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'pricing_method', 'prompt' => 'How do you price your work?', 'input_type' => 'select', 'options' => ['fixed', 'hourly', 'quote_only', 'package_tiers'], 'required' => false],
                    ['field_key' => 'financing_available', 'prompt' => 'Do you offer financing or payment plans?', 'input_type' => 'boolean', 'options' => null, 'required' => false],
                    ['field_key' => 'primary_conversion_goal', 'prompt' => 'What should a visitor do first?', 'input_type' => 'select', 'options' => ['call', 'quote_request', 'consultation_booking', 'calendar_booking', 'external_booking_link'], 'required' => false],
                    ['field_key' => 'brand_voice', 'prompt' => 'How would you describe your brand\'s tone (e.g. friendly, professional, playful)?', 'input_type' => 'text', 'options' => null, 'required' => false],
                ],
            ],
        );

        QuestionPack::updateOrCreate(
            ['key' => 'photobooth', 'version' => 1],
            [
                'applies_to_industry' => 'photo_booth_service',
                'applies_to_vertical_key' => null,
                'is_active' => true,
                'questions' => [
                    ['field_key' => 'differentiators', 'prompt' => 'Which booth/experience types do you offer (open-air, mirror, 360, glam, roaming)? What makes each one worth booking?', 'input_type' => 'multi_select', 'options' => ['Open-air/DSLR', 'Mirror booth', '360 booth', 'Glam booth', 'Roaming/social media booth', 'AI-generated photos'], 'required' => false],
                    ['field_key' => 'ideal_customers', 'prompt' => 'Which event types do you book most (weddings, corporate, private parties, brand activations)?', 'input_type' => 'multi_select', 'options' => ['Weddings', 'Corporate events', 'Private parties', 'Brand activations', 'School events'], 'required' => false],
                    ['field_key' => 'customer_problems', 'prompt' => 'What do guests actually get (unlimited prints, digital sharing, props, backdrops, custom templates)?', 'input_type' => 'multi_select', 'options' => ['Unlimited prints', 'Digital sharing', 'Custom props', 'Custom backdrops', 'Branded templates', 'On-site attendant'], 'required' => false],
                    ['field_key' => 'years_operating', 'prompt' => 'How many years have you been renting photo booths?', 'input_type' => 'text', 'options' => null, 'required' => false],
                    ['field_key' => 'credentials', 'prompt' => 'Are you licensed or insured for event work? List each credential.', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'warranties_guarantees', 'prompt' => 'What is your on-time / equipment-failure guarantee, if any?', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'pricing_method', 'prompt' => 'How do your packages work?', 'input_type' => 'select', 'options' => ['fixed', 'hourly', 'quote_only', 'package_tiers'], 'required' => false],
                    ['field_key' => 'financing_available', 'prompt' => 'Do you offer payment plans or deposits?', 'input_type' => 'boolean', 'options' => null, 'required' => false],
                    ['field_key' => 'offers', 'prompt' => 'List your package tiers and what is included in each.', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                    ['field_key' => 'primary_conversion_goal', 'prompt' => 'How should a visitor book you?', 'input_type' => 'select', 'options' => ['call', 'quote_request', 'consultation_booking', 'calendar_booking', 'external_booking_link'], 'required' => false],
                    ['field_key' => 'brand_voice', 'prompt' => 'How would you describe your booth experience\'s tone (fun, luxury, playful, professional)?', 'input_type' => 'text', 'options' => null, 'required' => false],
                    ['field_key' => 'testimonials', 'prompt' => 'Share up to 5 real quotes from past clients (name + quote).', 'input_type' => 'textarea', 'options' => null, 'required' => false],
                ],
            ],
        );
    }
}
