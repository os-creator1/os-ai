<?php

namespace App\Library\Demo\BrightFrame;

use App\Models\ChatBox;
use Illuminate\Support\Facades\DB;

/**
 * Conversations: ten text threads, written straight into the inbox tables.
 * Nothing is sent: no provider, number or send operation exists for this
 * tenant, so every "outbound" row is history only.
 */
final class ConversationsStage implements DemoStage
{
    private const BUSINESS_NUMBER = '17045550142';

    /**
     * [contact index, minutes-ago of first message, awaiting reply (unread), pinned, [[in|out, text, minutes after previous]...]]
     */
    private const THREADS = [
        [0, 3 * 1440 + 90, true, true, [
            ['in', 'Hi! Our venue is confirmed for Oct 24 at the Foundry Loft. Is the Signature package still open that day?', 0],
            ['out', 'Congratulations, Maya! Oct 24 is open. The Signature package covers 3 hours, a custom template and an attendant. Want me to send the proposal?', 14],
            ['in', 'Yes please. Also can we add the Glam booth instead of the classic one?', 41],
            ['out', 'Absolutely, the Glam Mirror Booth is a swap with no change to the package price. Proposal is on its way to your email.', 9],
            ['in', 'Perfect. Do you need a deposit to hold the date?', 126],
        ]],
        [1, 2 * 1440 + 30, true, false, [
            ['in', 'We are planning our summer mixer for about 150 people. Does the 360 booth work with a branded overlay?', 0],
            ['out', 'It does. We design the overlay with your logo and colors, and clips are ready to share instantly. I can hold your date while you decide.', 22],
            ['in', 'Great. Can you send pricing for 3 hours?', 55],
        ]],
        [2, 5 * 1440, false, false, [
            ['in', 'Thank you for the quote! We are comparing two companies and will decide by Friday.', 0],
            ['out', 'Thanks, Priya! Happy to answer anything. Our Luxe package includes two booths and a roaming photographer, which guests love at larger weddings.', 33],
            ['in', 'That is helpful. What is your cancellation policy?', 210],
            ['out', 'You can reschedule once at no charge up to 30 days before the event. The deposit is fully refundable within 7 days of booking.', 18],
        ]],
        [3, 1 * 1440 + 400, true, false, [
            ['in', 'Do you serve Matthews? Planning a 40th birthday at our house, about 35 guests.', 0],
            ['out', 'Yes, Matthews is in our regular service area. For 35 guests the Classic booth with 2 hours is a great fit.', 17],
            ['in', 'Sounds good, what dates do you have in November?', 80],
        ]],
        [5, 6 * 1440, false, false, [
            ['in', 'Following up on the gala quote. Can we get a custom backdrop in our brand colors?', 0],
            ['out', 'Yes, custom backdrops are included in the Branded Corporate Activation. I will send a mockup by tomorrow.', 25],
            ['out', 'Mockup attached to your email. Let me know if the teal is close to your palette.', 1440],
            ['in', 'It is. Looks great, we are going to move forward.', 300],
        ]],
        [6, 2 * 1440 + 800, true, false, [
            ['in', 'Hi, I am planning prom for 220 students. Is one booth enough?', 0],
            ['out', 'For 220 students I would suggest the Classic booth plus a Roaming Photographer to keep lines short. Want a quote for both?', 29],
            ['in', 'Yes! Our budget is around $1,100.', 52],
        ]],
        [8, 4 * 1440 + 200, false, false, [
            ['in', 'We loved meeting you at the bridal show! Sending our date: June 6.', 0],
            ['out', 'So great to meet you, Hannah! June 6 is open. I will reserve it for 48 hours while you review the Signature proposal.', 20],
            ['in', 'Thank you, we are reviewing it with our families this weekend.', 400],
        ]],
        [12, 20 * 1440, false, false, [
            ['out', 'Hi Aisha, your event is next week! Your attendant Jordan will arrive at 4:30 PM to set up. Reply here with any venue changes.', 0],
            ['in', 'Thank you! Venue loading dock is on the north side.', 61],
            ['out', 'Noted, we will use the north dock. See you Saturday!', 8],
        ]],
        [13, 14 * 1440, false, false, [
            ['in', 'The booth was a hit at our holiday party. Can we book you again for the spring picnic?', 0],
            ['out', 'That made our day, thank you! I will send the picnic options and a repeat-client discount.', 40],
        ]],
        [9, 3 * 1440 + 20, true, false, [
            ['in', 'Hi, we are launching a new bourbon in November. Does your team do events at distilleries?', 0],
            ['out', 'We do, and the 360 Video Booth is a favorite for product launches. What is your date and expected headcount?', 31],
            ['in', 'Nov 14, about 120 guests. Can you do a barrel-themed overlay?', 95],
        ]],
    ];

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $contacts = array_values($context->refs['contacts']);
        $created = 0;

        foreach (self::THREADS as [$index, $minutesAgo, $awaiting, $pinned, $messages]) {
            $phone = $contacts[$index]->phone;

            $box = ChatBox::query()->where('business_id', $business->id)->where('from', self::BUSINESS_NUMBER)->where('to', $phone)->first();

            if ($box !== null) {
                continue;
            }

            $start = $context->now->subMinutes($minutesAgo);
            $at = $start;
            $unread = 0;
            $box = new ChatBox([
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'location_id' => $context->location->id,
                'from' => self::BUSINESS_NUMBER,
                'to' => $phone,
                'pinned' => $pinned,
            ]);
            $box->uid = (string) \Illuminate\Support\Str::uuid();
            $box->save();

            $rows = [];
            foreach ($messages as $j => [$who, $text, $gap]) {
                $at = $j === 0 ? $start : $at->addMinutes($gap);
                $incoming = $who === 'in';
                $rows[] = [
                    'box_id' => $box->id,
                    'message' => $text,
                    'sms_type' => 'plain',
                    'direction' => $incoming ? 'incoming' : 'outgoing',
                    'send_by' => $incoming ? 'to' : 'from',
                    'source' => $incoming ? null : 'manual',
                    'send_status' => $incoming ? null : 'sent',
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
            DB::table('chat_box_messages')->insert($rows);

            // The message log Home and Analytics read. Delivered/received history only, no provider involved.
            DB::table('reports')->insert(array_map(fn (array $row): array => [
                'uid' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'from' => $row['direction'] === 'incoming' ? $phone : self::BUSINESS_NUMBER,
                'to' => $row['direction'] === 'incoming' ? self::BUSINESS_NUMBER : $phone,
                'message' => $row['message'],
                'sms_type' => 'plain',
                'status' => $row['direction'] === 'incoming' ? 'Received' : 'Delivered',
                'customer_status' => $row['direction'] === 'incoming' ? 'Received' : 'Delivered',
                'send_by' => $row['send_by'],
                'direction' => $row['direction'],
                'cost' => '0',
                'sms_count' => 1,
                'created_at' => $row['created_at'],
                'updated_at' => $row['created_at'],
            ], $rows));

            $last = end($messages);
            if ($awaiting) {
                $trailing = 0;
                foreach (array_reverse($messages) as [$who]) {
                    if ($who !== 'in') {
                        break;
                    }
                    $trailing++;
                }
                $unread = max(1, $trailing);
            }

            DB::table('chat_boxes')->where('id', $box->id)->update([
                'reply_by_customer' => $awaiting && $last[0] === 'in',
                'notification' => $unread,
                'ai_replied' => false,
                'created_at' => $start,
                'updated_at' => $at,
            ]);
            $created++;
        }

        $context->note('conversations', ChatBox::query()->where('business_id', $business->id)->count());
        $context->note('conversations_created_now', $created);
    }
}
