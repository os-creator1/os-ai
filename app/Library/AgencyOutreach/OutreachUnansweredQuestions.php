<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\AgencyProspectUnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Unanswered questions queue: recording what no approved answer covered, and the
 * owner teaching the bot from it.
 *
 * Teaching only ever changes the Agency's approved answers (through OutreachScriptManager,
 * the same path as the Script page) and the queue row. It never sends anything: the
 * original prospect is not texted when an answer is saved; only FUTURE replies use it.
 */
final class OutreachUnansweredQuestions
{
    /**
     * One row per question, idempotent per (inbound, wording): reprocessing the same inbound
     * never adds a second item.
     *
     * @param  list<string>  $questions
     */
    public function record(AgencyProspectCampaignMember $member, AgencyProspectMessage $inbound, array $questions): void
    {
        $rows = [];
        $now = now();

        foreach ($questions as $question) {
            $question = mb_substr(trim($question), 0, 500);
            $key = OutreachFaq::aliasKey($question);

            if ($key === '') {
                continue;
            }

            $rows[] = [
                'uid' => (string) Str::uuid(),
                'workspace_id' => (int) $member->workspace_id,
                'campaign_id' => $member->campaign_id === null ? null : (int) $member->campaign_id,
                'campaign_member_id' => (int) $member->id,
                'inbound_message_id' => (int) $inbound->id,
                'question' => $question,
                'question_hash' => hash('sha256', $key),
                'status' => AgencyProspectUnansweredQuestion::STATUS_OPEN,
                'asked_at' => $inbound->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('agency_prospect_unanswered_questions')->insertOrIgnore($rows);
        }
    }

    /**
     * "Add answer": save a new approved answer (the owner's wording) and remember the prospect's
     * exact phrasing as an alias of it. The item is resolved only once the answer is saved.
     *
     * @throws ValidationException
     */
    public function addAnswer(Workspace $workspace, AgencyProspectUnansweredQuestion $item, string $question, string $answer, bool $onlyThisCampaign, ?User $user): string
    {
        return DB::transaction(function () use ($workspace, $item, $question, $answer, $onlyThisCampaign, $user) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $item = AgencyProspectUnansweredQuestion::where('workspace_id', $workspace->id)
                ->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($workspace, $item);

            $id = 'q' . strtolower(Str::random(10));
            $entries = OutreachFaq::forWorkspace($workspace)->all();
            $entries[] = [
                'id' => $id,
                'question' => trim($question) !== '' ? trim($question) : $item->question,
                'answer' => $answer,
                'keywords' => [],
                'aliases' => [OutreachFaq::aliasKey($item->question)],
                'intent' => null,
                'campaign_ids' => $onlyThisCampaign && $item->campaign_id !== null ? [(int) $item->campaign_id] : null,
                'niche' => null,
            ];

            OutreachScriptManager::save($workspace, ['faq_entries' => $entries], $user);
            $this->resolve($item, AgencyProspectUnansweredQuestion::RESOLUTION_ANSWER_ADDED, $id, $user);

            return $id;
        });
    }

    /**
     * "Link existing answer": the prospect's exact wording becomes an alias of an approved answer
     * that applies to the item's campaign. Never a keyword: aliases match exactly, not as substrings.
     *
     * @throws ValidationException
     */
    public function linkAnswer(Workspace $workspace, AgencyProspectUnansweredQuestion $item, string $faqId, ?User $user): void
    {
        DB::transaction(function () use ($workspace, $item, $faqId, $user) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $item = AgencyProspectUnansweredQuestion::where('workspace_id', $workspace->id)
                ->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($workspace, $item);

            $faq = OutreachFaq::forWorkspace($workspace);

            if ($faq->applicableById($faqId, $item->campaign_id === null ? null : (int) $item->campaign_id) === null) {
                throw ValidationException::withMessages(['faq_id' => 'Choose an approved answer that applies to this campaign.']);
            }

            $entries = array_map(static function (array $entry) use ($faqId, $item): array {
                if ($entry['id'] === $faqId) {
                    $entry['aliases'] = OutreachFaq::aliases([...$entry['aliases'] ?? [], $item->question]);
                }

                return $entry;
            }, $faq->all());

            OutreachScriptManager::save($workspace, ['faq_entries' => $entries], $user);
            $this->resolve($item, AgencyProspectUnansweredQuestion::RESOLUTION_ALIAS_LINKED, $faqId, $user);
        });
    }

    /** "Dismiss": off the open queue; approved answers are untouched. */
    public function dismiss(Workspace $workspace, AgencyProspectUnansweredQuestion $item, ?User $user): void
    {
        DB::transaction(function () use ($workspace, $item, $user) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $item = AgencyProspectUnansweredQuestion::where('workspace_id', $workspace->id)
                ->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($workspace, $item);

            $item->forceFill([
                'status' => AgencyProspectUnansweredQuestion::STATUS_DISMISSED,
                'resolution' => AgencyProspectUnansweredQuestion::RESOLUTION_DISMISSED,
                'resolved_by_user_id' => $user?->id,
                'resolved_at' => now(),
            ])->save();
        });
    }

    private function resolve(AgencyProspectUnansweredQuestion $item, string $resolution, string $faqId, ?User $user): void
    {
        $item->forceFill([
            'status' => AgencyProspectUnansweredQuestion::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_faq_id' => $faqId,
            'resolved_by_user_id' => $user?->id,
            'resolved_at' => now(),
        ])->save();
    }

    private function assertOpen(Workspace $workspace, AgencyProspectUnansweredQuestion $item): void
    {
        abort_if((int) $item->workspace_id !== (int) $workspace->id, 404);

        if (! $item->isOpen()) {
            throw ValidationException::withMessages(['item' => 'This question has already been handled.']);
        }
    }
}
