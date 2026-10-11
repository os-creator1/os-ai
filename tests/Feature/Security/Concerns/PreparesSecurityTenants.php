<?php

namespace Tests\Feature\Security\Concerns;

use App\Models\AppConfig;
use App\Models\User;

/** Mirrors the setup the other tenancy security tests use (user id 1 is the implicit super admin). */
trait PreparesSecurityTenants
{
    protected function createPlaceholderSuperAdmin(): void
    {
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }
}
