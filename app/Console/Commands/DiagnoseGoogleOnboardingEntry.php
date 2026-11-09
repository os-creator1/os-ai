<?php

namespace App\Console\Commands;

use App\Library\Business\GoogleOnboardingBridge;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Console\Command;

/**
 * Explains, for ONE customer, why the "Connect Google Business Profile"
 * onboarding option is or is not offered. Read-only, and it prints only
 * closed reason codes and yes/no facts: never a client id, secret, redirect
 * value, token or other configured value.
 */
class DiagnoseGoogleOnboardingEntry extends Command
{
    protected $signature = 'gbp:diagnose-onboarding {customer : the customer\'s user id or email}';

    protected $description = 'Report (without exposing secrets) why Google quick onboarding is or is not offered to a customer';

    public function handle(CustomerOnboardingRepository $onboardings, GoogleOnboardingBridge $bridge, AccountRepository $accounts): int
    {
        $key = (string) $this->argument('customer');
        $user = ctype_digit($key) ? User::query()->find((int) $key) : User::query()->where('email', $key)->first();
        $customer = $user === null ? null : Customer::query()->where('user_id', $user->id)->first();

        if ($user === null || $customer === null) {
            $this->error('No such customer.');

            return self::FAILURE;
        }

        // The session-less, durable permission set: what the customer holds, not what a request happens to carry.
        $holdsPermission = $accounts->durablePermissions($user, true)->contains('manage_google_business_profile');
        $row = $onboardings->findByCustomer($customer);
        $mayManage = $row !== null && $bridge->mayManageGoogle($user, $row);
        $result = $row === null
            ? ['available' => false, 'reason' => 'onboarding_not_started']
            : $bridge->availability($customer, $row, $mayManage);

        $this->table(['Check', 'Result'], [
            ['Google OAuth configured', $bridge->isConfigured() ? 'yes' : 'NO'],
            ['Holds manage_google_business_profile', $holdsPermission ? 'yes' : 'no'],
            ['Authorized (owner or permission)', $row === null ? 'not evaluated' : ($mayManage ? 'yes' : 'NO')],
            ['Onboarding has a business attached', $row?->business_id === null ? 'no' : 'yes'],
            ['Option offered', $result['available'] ? 'yes' : 'NO'],
            ['Reason', $result['reason']],
        ]);

        return self::SUCCESS;
    }
}
