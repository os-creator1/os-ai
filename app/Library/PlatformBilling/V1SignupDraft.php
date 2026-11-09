<?php

namespace App\Library\PlatformBilling;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * The guest's in-progress V1 signup: plan → account → business, held in the
 * session until the payment step creates the real account.
 *
 * WHY THE SESSION AND NOT A TABLE. Until the customer commits at the payment
 * step there is nothing durable to protect: no User, no Workspace, no
 * subscription. A signup table would only collect abandoned half-accounts.
 * The moment the customer commits, the existing durable machinery takes over
 * (a User, then V1SignupManager's Workspace + pending subscription), and this
 * draft is discarded — so a refresh, a Back, or a closed tab before payment
 * costs the customer a few fields, never a duplicated tenant object.
 *
 * THE PASSWORD IS NEVER STORED IN THE CLEAR. It is held encrypted (Crypt, the
 * application key) only so the account can be created once, at commit, through
 * the same UserRepository::store() primitive that hashes it; it is not hashed
 * here because store() hashes, and a double hash would lock the customer out.
 */
final class V1SignupDraft
{
    private const KEY = 'v1_signup_draft';

    public function __construct(private readonly Session $session)
    {
    }

    public function tier(): ?string
    {
        $tier = $this->all()['tier'] ?? null;

        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    /**
     * Pin the chosen plan. Moving between the Agency branch and the
     * non-Agency branch discards the business facts: they are different
     * questions, and a stale answer must not ride into the other branch.
     */
    public function setTier(string $tier): void
    {
        $draft = $this->all();
        $previous = $draft['tier'] ?? null;

        if ($previous !== null && ($previous === 'agency') !== ($tier === 'agency')) {
            unset($draft['business']);
        }

        $draft['tier'] = $tier;
        $this->save($draft);
    }

    /** The private-offer link this signup began from, if any. */
    public function offerToken(): ?string
    {
        $token = $this->all()['offer_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function setOfferToken(string $token): void
    {
        $draft = $this->all();
        $draft['offer_token'] = $token;
        $this->save($draft);
    }

    public function forgetTier(): void
    {
        $draft = $this->all();
        unset($draft['tier']);
        $this->save($draft);
    }

    /** @return array{first_name?: string, last_name?: ?string, email?: string}|null */
    public function account(): ?array
    {
        $account = $this->all()['account'] ?? null;

        // An account is a password the customer chose, OR an identity a provider verified (Google): never both, never neither.
        if (! is_array($account) || ! isset($account['email']) || (! isset($account['password_encrypted']) && ! isset($account['provider_id']))) {
            return null;
        }

        return [
            'first_name' => (string) ($account['first_name'] ?? ''),
            'last_name' => $account['last_name'] ?? null,
            'email' => (string) $account['email'],
            'locale' => $account['locale'] ?? null,
            'provider' => isset($account['provider_id']) ? (string) ($account['provider'] ?? '') : null,
        ];
    }

    /** @param array{first_name: string, last_name?: ?string, email: string, password: string} $account */
    public function setAccount(array $account): void
    {
        $draft = $this->all();
        $draft['account'] = [
            'first_name' => $account['first_name'],
            'last_name' => $account['last_name'] ?? null,
            'email' => $account['email'],
            'locale' => $account['locale'] ?? null,
            'password_encrypted' => Crypt::encryptString($account['password']),
        ];
        $this->save($draft);
    }

    /**
     * A provider-VERIFIED identity (Google) as the signup's account: no password is chosen or stored. Set only by the server after the
     * provider callback, never from request input.
     *
     * @param array{provider: string, provider_id: string, email: string, first_name: string, last_name?: ?string, avatar?: ?string} $identity
     */
    public function setProviderAccount(array $identity): void
    {
        $draft = $this->all();
        $draft['account'] = [
            'first_name' => $identity['first_name'],
            'last_name' => $identity['last_name'] ?? null,
            'email' => $identity['email'],
            'locale' => null,
            'provider' => $identity['provider'],
            'provider_id' => $identity['provider_id'],
            'avatar' => $identity['avatar'] ?? null,
        ];
        $this->save($draft);
    }

    /** @return array{provider: string, provider_id: string, avatar: ?string}|null */
    public function providerIdentity(): ?array
    {
        $account = $this->all()['account'] ?? null;

        if (! is_array($account) || ! isset($account['provider_id'], $account['provider'])) {
            return null;
        }

        return ['provider' => (string) $account['provider'], 'provider_id' => (string) $account['provider_id'], 'avatar' => $account['avatar'] ?? null];
    }

    /**
     * The ONE definition of a complete signup account. Manual: a valid account AND the password that was chosen. Google: a valid account AND
     * a provider-verified identity the SERVER put in the draft (never request input). Every step guard uses this, so no step can disagree.
     */
    public function hasCompleteAccount(): bool
    {
        if ($this->account() === null) {
            return false;
        }

        return $this->providerIdentity() !== null || $this->password() !== null;
    }

    /** Drop the account step's answer (e.g. to switch from Google to a manual email and password). The plan and business facts stay. */
    public function forgetAccount(): void
    {
        $draft = $this->all();
        unset($draft['account']);
        $this->save($draft);
    }

    public function password(): ?string
    {
        $encrypted = $this->all()['account']['password_encrypted'] ?? null;

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{business_name: string, industry: string, country_code: string, timezone: string, currency_code: string}|null */
    public function business(): ?array
    {
        $business = $this->all()['business'] ?? null;

        foreach (['business_name', 'industry', 'country_code', 'timezone', 'currency_code'] as $key) {
            if (! is_array($business) || ! isset($business[$key]) || $business[$key] === '') {
                return null;
            }
        }

        return $business;
    }

    /** @param array{business_name: string, industry: string, country_code: string, timezone: string, currency_code: string} $business */
    public function setBusiness(array $business): void
    {
        $draft = $this->all();
        $draft['business'] = $business;
        $this->save($draft);
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $draft = $this->session->get(self::KEY, []);

        return is_array($draft) ? $draft : [];
    }

    /** @param array<string, mixed> $draft */
    private function save(array $draft): void
    {
        $this->session->put(self::KEY, $draft);
    }
}
