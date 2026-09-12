<?php

namespace App\Library\Business;

use App\Models\Currency;
use Collator;
use DateTimeZone;
use ResourceBundle;

/**
 * The choices offered for a Business's country, timezone and currency on the
 * customer Business forms. Every value is the canonical one the Business
 * already stores — an ISO 3166-1 alpha-2 country code, an IANA timezone
 * identifier and a currency code — only the labels are for people.
 *
 * - Countries: ICU's English region names, minus the codes ICU carries that
 *   are not countries (macro-regions such as EU/UN, pseudo and unknown
 *   regions, and exceptionally-reserved territory codes). Kosovo keeps XK,
 *   the code in general use.
 * - Timezones: PHP's canonical identifier list — the same list the
 *   `timezone` validation rule checks against.
 * - Currencies: the active `currencies` rows, because that is what a new
 *   Business's usage wallet resolves its currency_code against
 *   (UsageWalletManager::resolveCurrencyId()); a code outside it would leave
 *   the Business without a wallet.
 *
 * Nothing here validates or writes; the form requests keep their rules.
 */
class BusinessLocaleOptions
{
    private const NON_COUNTRY_REGION_CODES = [
        'AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'ZZ',
    ];

    /** @var array<string, string>|null */
    private ?array $countryNames = null;

    /** @var array<string, string>|null */
    private ?array $currencyLabels = null;

    /**
     * @return array<string, string> code => "Lithuania (LT)", sorted by name
     */
    public function countries(): array
    {
        $options = [];

        foreach ($this->countryNames() as $code => $name) {
            $options[$code] = "{$name} ({$code})";
        }

        return $options;
    }

    /**
     * @return array<string, string> identifier => identifier
     */
    public function timezones(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /**
     * @return array<string, string> code => "EUR — Euro", sorted by code
     */
    public function currencies(): array
    {
        if ($this->currencyLabels !== null) {
            return $this->currencyLabels;
        }

        $icuNames = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');
        $labels = [];

        foreach (Currency::query()->where('status', true)->orderBy('code')->get(['code', 'name']) as $currency) {
            $code = strtoupper((string) $currency->code);

            if ($code === '' || isset($labels[$code])) {
                continue;
            }

            $entry = $icuNames?->get($code);
            $name = $entry instanceof ResourceBundle ? (string) $entry->get(1) : trim((string) $currency->name);

            $labels[$code] = $name !== '' ? "{$code} — {$name}" : $code;
        }

        return $this->currencyLabels = $labels;
    }

    /**
     * Countries whose currency is not in doubt: exactly one current legal
     * tender according to ICU's currency data, and that currency is one of
     * the offered currencies. A country with two tenders in use (Panama,
     * Cuba, Bhutan, …) or whose currency is not offered is simply absent, so
     * choosing it leaves the currency as it was.
     *
     * @return array<string, string> country code => currency code
     */
    public function defaultCurrencyByCountry(): array
    {
        $currencyMap = ResourceBundle::create('supplementalData', 'ICUDATA-curr', false)?->get('CurrencyMap');

        if (! $currencyMap instanceof ResourceBundle) {
            return [];
        }

        $offered = $this->currencies();
        $defaults = [];

        foreach (array_keys($this->countryNames()) as $countryCode) {
            $entries = $currencyMap->get($countryCode);

            if (! $entries instanceof ResourceBundle) {
                continue;
            }

            $current = [];

            foreach ($entries as $entry) {
                if ($entry->get('to') !== null || $entry->get('tender') === 'false') {
                    continue;
                }

                $current[] = (string) $entry->get('id');
            }

            if (count($current) === 1 && isset($offered[$current[0]])) {
                $defaults[$countryCode] = $current[0];
            }
        }

        return $defaults;
    }

    /**
     * Adds a stored value the canonical list does not contain (a Business
     * saved before these lists existed) so that editing the Business shows
     * what it has, rather than silently replacing it on the next save.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function withStoredValue(array $options, ?string $stored): array
    {
        if ($stored === null || $stored === '' || array_key_exists($stored, $options)) {
            return $options;
        }

        return [$stored => $stored] + $options;
    }

    /**
     * @return array<string, string> code => English name, sorted by name
     */
    private function countryNames(): array
    {
        if ($this->countryNames !== null) {
            return $this->countryNames;
        }

        $names = [];

        foreach (ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries') ?? [] as $code => $name) {
            $code = (string) $code;

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, self::NON_COUNTRY_REGION_CODES, true)) {
                continue;
            }

            $names[$code] = (string) $name;
        }

        $collator = new Collator('en');
        uasort($names, fn (string $a, string $b) => $collator->compare($a, $b));

        return $this->countryNames = $names;
    }
}
