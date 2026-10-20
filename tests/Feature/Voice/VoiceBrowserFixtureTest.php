<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * NOT a regression test. A fixture seeder for tests/e2e/voice-real-layout.mjs (see that file): it COMMITS one
 * disposable customer with two Workspaces/Businesses, a contact, a conversation and a voice-ready managed
 * number to the (disposable, TestDatabaseSafety-approved) test database so a real browser can log in to the
 * real application layout. Skipped unless VOICE_E2E_SEED is set, so it is inert in every normal and CI run.
 */
class VoiceBrowserFixtureTest extends TestCase
{
    use BuildsVoiceFixtures;

    public function test_seed_voice_browser_fixture(): void
    {
        if (! getenv('VOICE_E2E_SEED')) {
            $this->markTestSkipped('Browser fixture seeding is opt-in (VOICE_E2E_SEED=1).');
        }

        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin', 'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Harbor Lane');
        $customer->forceFill(['permissions' => Customer::customerPermissions()])->save();
        $password = 'E2e-Fixture-Pass-123!';
        $customer->user->forceFill(['password' => Hash::make($password), 'email_verified_at' => now()])->save();

        $this->managedVoiceNumber($business, '+14155550199', true, $customer->user_id);
        $contact = $this->contactFor($business, '12025550111', 'Maya', 'Lopez');
        $box = $this->conversationWith($business, '12025550111');
        $this->message($box, 'incoming', 'Can someone call me about Saturday?', now()->subMinutes(30));

        // A second Workspace/Business of the SAME person, for workspace-switching during a call.
        $second = $this->createWorkspace($customer->user, ['name' => 'Second Workspace']);
        $secondBusiness = $this->addBusiness($customer, $second, 'Second Studio');
        $this->assignTier($second, WorkspacePlanTier::Core);

        file_put_contents((string) getenv('VOICE_E2E_OUT'), json_encode([
            'email' => $customer->user->email, 'password' => $password,
            'workspace' => $workspace->uid, 'business' => $business->uid,
            'contact' => $contact->uid, 'conversation' => $box->uid,
            'workspace2' => $second->uid, 'business2' => $secondBusiness->uid,
        ]));

        $this->assertTrue(true);
    }
}
