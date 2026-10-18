<?php

namespace App\Library\Documents\Templates;

use App\Library\Documents\Blocks\BlockSchema;

/**
 * Implementation Contract 17B §6b - the platform's Photo Booth template set.
 *
 * Four platform templates built ONLY from allowed block types: headings,
 * explanatory text with merge tokens, business details, section bands, the
 * generic product placeholder, payment terms, a page break (agreement) and the
 * signature. They hold NO Business product, price, Contact, payment amount or
 * date, and NO image (there is no platform media seam in V1). The copy is
 * deliberately plain and generic so a Business can edit any sentence; it makes no
 * legal claim. Typed e-signing is described, consistently with contract 17
 * §6.5, as a record of agreement and not a legal opinion.
 *
 * `documents:seed-photo-booth-templates` creates these (idempotently, by
 * `seed_key`) and assigns them to the `photo_booth` blueprint.
 */
final class PhotoBoothPlatformTemplates
{
    public const BLUEPRINT_KEY = 'photo_booth';

    private const E_SIGN_NOTE = 'By typing your name below you confirm that you have read this document and agree to it. '
        . 'A typed electronic signature is a record of your agreement; it is not legal advice or a legal opinion.';

    /**
     * @return array<int, array{seed_key: string, name: string, type: string, description: string, blocks: array<int, array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'seed_key' => 'photo_booth_proposal',
                'name' => 'Photo Booth Proposal',
                'type' => 'proposal',
                'description' => 'A friendly, general-purpose photo booth proposal: what is included, the investment and how to book. Edit the wording to match your offer before sending.',
                'blocks' => self::photoBoothProposal(),
            ],
            [
                'seed_key' => 'photo_booth_wedding_proposal',
                'name' => 'Wedding Photo Booth Proposal',
                'type' => 'proposal',
                'description' => 'A warm wedding-focused proposal for a photo booth hire. Edit the wording to match your offer before sending.',
                'blocks' => self::weddingProposal(),
            ],
            [
                'seed_key' => 'photo_booth_corporate_proposal',
                'name' => 'Corporate Event Proposal',
                'type' => 'proposal',
                'description' => 'A concise, professional proposal for corporate events, launches and brand activations. Edit the wording to match your offer before sending.',
                'blocks' => self::corporateProposal(),
            ],
            [
                'seed_key' => 'photo_booth_event_agreement',
                'name' => 'Event Agreement',
                'type' => 'contract',
                'description' => 'A simple, plain-language event hire agreement with a signature. A starting point only: review and edit every clause to suit your business before sending.',
                'blocks' => self::eventAgreement(),
            ],
        ];
    }

    /**
     * Every definition validated as a PLATFORM template (no images).
     *
     * @return array<int, array<string, mixed>> definitions whose `blocks` are the sanitised BlockSchema output
     *
     * @throws \App\Exceptions\Documents\InvalidDocumentBlocksException
     */
    public static function normalized(): array
    {
        return array_map(function (array $definition): array {
            $definition['blocks'] = BlockSchema::normalize($definition['blocks'], ['allow_images' => false]);

            return $definition;
        }, self::definitions());
    }

    // ---- the four layouts ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function photoBoothProposal(): array
    {
        $b = new self();

        return [
            $b->details('pb-details'),
            $b->heading('pb-title', 1, [['t' => 'Photo booth proposal for '], ['merge' => 'contact.first_name']], 'left'),
            $b->text('pb-intro', [['t' => 'Hi '], ['merge' => 'contact.first_name'], ['t' => ', thank you for thinking of '], ['merge' => 'business.name'], ['t' => ' for your event. Here is everything we have put together for you: what is included, the investment and how to confirm your booking.']]),
            $b->section('pb-sec-included', 'What is included'),
            $b->text('pb-included-1', [['t' => 'A professional photo booth experience for your guests, set up and looked after by a friendly on-site attendant from start to finish.']]),
            $b->text('pb-included-2', [['t' => 'Guests get great photos they can enjoy and share, and you receive a gallery of every photo taken after the event.']]),
            $b->text('pb-included-3', [['t' => 'We handle delivery, setup and pack-down, so the only thing you need to do on the day is enjoy your event.']]),
            $b->section('pb-sec-investment', 'Your investment'),
            $b->text('pb-investment-intro', [['t' => 'The package you choose, its price and how it is paid for are shown below.']]),
            $b->block('pb-products', 'product_list', ['show_description' => true, 'show_quantity' => true]),
            $b->block('pb-payment', 'payment_terms', []),
            $b->section('pb-sec-book', 'How to book'),
            $b->text('pb-book-1', [['t' => '1. Review this proposal and get in touch if you would like to change anything.']]),
            $b->text('pb-book-2', [['t' => '2. Sign below to confirm your booking.']]),
            $b->text('pb-book-3', [['t' => '3. Pay securely online using the link you will see after signing.']]),
            $b->text('pb-thanks', [['t' => 'We would love to be part of your event. Thank you from everyone at '], ['merge' => 'business.name'], ['t' => '.']]),
            $b->text('pb-esign', [['t' => self::E_SIGN_NOTE, 'i' => true]]),
            $b->block('pb-sign', 'signature', ['label' => 'Client signature']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function weddingProposal(): array
    {
        $b = new self();

        return [
            $b->details('wp-details'),
            $b->heading('wp-title', 1, [['t' => 'Your wedding photo booth, '], ['merge' => 'contact.first_name']], 'center'),
            $b->text('wp-intro', [['t' => 'Congratulations, '], ['merge' => 'contact.first_name'], ['t' => '! We are delighted that you are thinking of '], ['merge' => 'business.name'], ['t' => ' for your wedding. A photo booth gives your guests something fun to do and gives you a lovely collection of candid moments to look back on.']], 'center'),
            $b->block('wp-divider', 'divider', []),
            $b->section('wp-sec-experience', 'The experience'),
            $b->text('wp-exp-1', [['t' => 'A stylish photo booth that fits in with your day, looked after by a friendly attendant so you and your guests can relax.']]),
            $b->text('wp-exp-2', [['t' => 'Guests step in together, strike a pose and take their photos away with them. You receive the full gallery after the wedding.']]),
            $b->text('wp-exp-3', [['t' => 'We coordinate arrival, setup and pack-down with your venue, so there is nothing for you to organise on the day.']]),
            $b->section('wp-sec-investment', 'Your investment'),
            $b->text('wp-investment-intro', [['t' => 'Your chosen package, its price and the payment schedule are shown below.']]),
            $b->block('wp-products', 'product_list', ['show_description' => true, 'show_quantity' => true]),
            $b->block('wp-payment', 'payment_terms', []),
            $b->section('wp-sec-book', 'Securing your date'),
            $b->text('wp-book', [['t' => 'To secure your booking, sign below and complete the payment shown above. Once confirmed, we will be in touch to talk through the details for your day.']]),
            $b->text('wp-thanks', [['t' => 'Thank you for considering us. We would be honoured to be part of your celebration. '], ['merge' => 'business.name']]),
            $b->text('wp-esign', [['t' => self::E_SIGN_NOTE, 'i' => true]]),
            $b->block('wp-sign', 'signature', ['label' => 'Client signature']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function corporateProposal(): array
    {
        $b = new self();

        return [
            $b->details('cp-details'),
            $b->heading('cp-title', 1, [['t' => 'Event proposal for '], ['merge' => 'contact.full_name']], 'left'),
            $b->text('cp-intro', [['t' => 'Thank you for the opportunity to work with you. '], ['merge' => 'business.name'], ['t' => ' provides a branded, easy-to-run photo booth experience for corporate events, launches and team days. This proposal sets out what we will provide and the commercial terms.']]),
            $b->section('cp-sec-scope', 'Scope of service'),
            $b->text('cp-scope-1', [['t' => 'Supply, delivery, setup and pack-down of the photo booth at your venue, with a trained attendant on site for the agreed hours.']]),
            $b->text('cp-scope-2', [['t' => 'Digital delivery of every photo after the event, ready to share with attendees or use in your own communications.']]),
            $b->text('cp-scope-3', [['t' => 'Where you would like branding on the experience, tell us what you need and we will confirm what is possible before the event.']]),
            $b->section('cp-sec-investment', 'Investment'),
            $b->text('cp-investment-intro', [['t' => 'The selected package, quantity and pricing are itemised below, followed by the payment terms.']]),
            $b->block('cp-products', 'product_list', ['show_description' => true, 'show_quantity' => true]),
            $b->block('cp-payment', 'payment_terms', []),
            $b->section('cp-sec-next', 'Next steps'),
            $b->text('cp-next-1', [['t' => '1. Confirm the details above with us.']]),
            $b->text('cp-next-2', [['t' => '2. Sign this proposal to confirm your booking.']]),
            $b->text('cp-next-3', [['t' => '3. Complete payment using the secure link provided after signing.']]),
            $b->text('cp-thanks', [['t' => 'We look forward to working with you. Kind regards, '], ['merge' => 'business.name']]),
            $b->text('cp-esign', [['t' => self::E_SIGN_NOTE, 'i' => true]]),
            $b->block('cp-sign', 'signature', ['label' => 'Authorised signature']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function eventAgreement(): array
    {
        $b = new self();

        return [
            $b->details('ea-details'),
            $b->heading('ea-title', 1, [['t' => 'Event agreement']], 'left'),
            $b->text('ea-parties', [['t' => 'This agreement is between '], ['merge' => 'business.name'], ['t' => ' (the "Provider") and '], ['merge' => 'contact.full_name'], ['t' => ' (the "Client"). It sets out the services the Provider will supply for the Client\'s event and the terms that apply.']]),
            $b->section('ea-sec-services', '1. Services'),
            $b->text('ea-services', [['t' => 'The Provider will supply, deliver, set up and pack down the photo booth and provide an attendant for the hire period agreed with the Client. The package, quantity and price are set out below.']]),
            $b->block('ea-products', 'product_list', ['show_description' => true, 'show_quantity' => true]),
            $b->section('ea-sec-payment', '2. Payment'),
            $b->text('ea-payment-intro', [['t' => 'The Client agrees to pay the amounts, and by the times, shown in the payment terms below.']]),
            $b->block('ea-payment', 'payment_terms', []),
            $b->section('ea-sec-venue', '3. Venue and access'),
            $b->text('ea-venue', [['t' => 'The Client will make sure the Provider has safe access to the venue, a suitable space and a power supply at the agreed setup time, and will tell the Provider about any venue rules in advance.']]),
            $b->section('ea-sec-changes', '4. Changes and cancellation'),
            $b->text('ea-changes', [['t' => 'Either party should tell the other in writing as early as possible if the booking needs to change or be cancelled. Any deposit, refund or cancellation terms are those stated in the payment terms above or otherwise agreed in writing by both parties.']]),
            $b->section('ea-sec-care', '5. Care of equipment'),
            $b->text('ea-care', [['t' => 'The Client will take reasonable care of the equipment while it is at the venue and will tell the Provider promptly about any damage or problem.']]),
            $b->section('ea-sec-general', '6. General'),
            $b->text('ea-general', [['t' => 'This agreement is the full understanding between the parties for this event. Any change must be agreed in writing by both parties.']]),
            $b->block('ea-pagebreak', 'page_break', []),
            $b->section('ea-sec-sign', 'Agreement'),
            $b->text('ea-esign', [['t' => self::E_SIGN_NOTE, 'i' => true]]),
            $b->block('ea-sign', 'signature', ['label' => 'Client signature']),
        ];
    }

    // ---- tiny builders ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function block(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type, 'data' => $data];
    }

    /** @return array<string, mixed> */
    private function heading(string $id, int $level, array $runs, string $align): array
    {
        return $this->block($id, 'heading', ['level' => $level, 'align' => $align, 'runs' => $runs]);
    }

    /** @return array<string, mixed> */
    private function text(string $id, array $runs, string $align = 'left'): array
    {
        return $this->block($id, 'text', ['align' => $align, 'runs' => $runs]);
    }

    /** @return array<string, mixed> */
    private function section(string $id, string $title): array
    {
        return $this->block($id, 'section', ['title' => $title]);
    }

    /** @return array<string, mixed> */
    private function details(string $id): array
    {
        return $this->block($id, 'business_details', ['show' => ['name', 'phone', 'email', 'website']]);
    }
}
