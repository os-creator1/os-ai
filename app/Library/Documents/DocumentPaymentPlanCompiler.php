<?php

namespace App\Library\Documents;

use App\Exceptions\Documents\InvalidDocumentPaymentPlanException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 17B §3 — payment INTENT -> canonical schedule terms.
 *
 * The editor stores what the Business meant (`content.payment_plan`):
 *
 *   {structure: full|deposit, deposit_minor?, full_due: on_signing|date,
 *    full_due_date?, balance_due: after_deposit|date, balance_due_date?}
 *
 * `full_due` is when the FIRST payment (the full amount, or the deposit) is
 * due; `balance_due` only applies to a deposit plan. This class is pure: it
 * validates the intent and compiles it to the `terms` DocumentManager::
 * setSchedule() takes. It never touches the database, so DocumentManager can
 * re-run it after every line change (recalculate() clears the schedule).
 *
 * `due_at` NULL means "after signing / after the deposit"; a date means the END
 * of that calendar day in the Business timezone, stored in the application
 * timezone. A date in the past is not refused here; it only has to parse.
 * Balance is always `total - deposit`, and a deposit must satisfy
 * `0 < deposit < total`.
 */
final class DocumentPaymentPlanCompiler
{
    public const STRUCTURES = ['full', 'deposit'];
    public const FULL_DUE = ['on_signing', 'date'];
    public const BALANCE_DUE = ['after_deposit', 'date'];

    /**
     * Validate and canonicalise a plan. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidDocumentPaymentPlanException
     */
    public function normalize(array $input, int $totalMinor): array
    {
        $errors = [];
        $plan = [];

        $structure = $input['structure'] ?? null;
        if (! is_string($structure) || ! in_array($structure, self::STRUCTURES, true)) {
            $errors['structure'] = 'Choose full payment or a deposit plus balance.';
        } else {
            $plan['structure'] = $structure;
        }

        if (($plan['structure'] ?? null) === 'deposit') {
            $deposit = $input['deposit_minor'] ?? null;
            if (! is_int($deposit) || $deposit <= 0) {
                $errors['deposit'] = 'Enter a deposit greater than zero.';
            } elseif ($totalMinor <= 0) {
                $errors['deposit'] = 'Add a product before setting a deposit.';
            } elseif ($deposit >= $totalMinor) {
                $errors['deposit'] = 'The deposit must be less than the document total.';
            } else {
                $plan['deposit_minor'] = $deposit;
            }
        }

        $this->timing($input, 'full_due', 'full_due_date', self::FULL_DUE, 'on_signing', $plan, $errors);

        if (($plan['structure'] ?? null) === 'deposit') {
            $this->timing($input, 'balance_due', 'balance_due_date', self::BALANCE_DUE, 'after_deposit', $plan, $errors);
        }

        if ($errors !== []) {
            throw new InvalidDocumentPaymentPlanException($errors);
        }

        return $plan;
    }

    /**
     * Why a STORED plan cannot be applied to this total, or null when it can
     * (or when there is nothing to apply it to yet — a document with no total).
     */
    public function problem(array $plan, int $totalMinor): ?string
    {
        if ($totalMinor <= 0) {
            return null;
        }

        if (($plan['structure'] ?? null) === 'deposit') {
            $deposit = $plan['deposit_minor'] ?? null;

            if (! is_int($deposit) || $deposit <= 0 || $deposit >= $totalMinor) {
                return 'The deposit must be less than the document total.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan  a normalised plan
     * @return array<int, array{kind: string, amount_minor: int, currency_code: string, due_at: ?Carbon}>
     *
     * @throws InvalidDocumentPaymentPlanException
     */
    public function compile(array $plan, int $totalMinor, string $currencyCode, ?string $timezone): array
    {
        if ($totalMinor <= 0) {
            throw new InvalidDocumentPaymentPlanException(['plan' => 'Add a product before setting a payment plan.']);
        }

        $problem = $this->problem($plan, $totalMinor);
        if ($problem !== null) {
            throw new InvalidDocumentPaymentPlanException(['deposit' => $problem]);
        }

        $zone = $this->zone($timezone);

        if (($plan['structure'] ?? null) === 'deposit') {
            $deposit = (int) $plan['deposit_minor'];

            return [
                ['kind' => 'deposit', 'amount_minor' => $deposit, 'currency_code' => $currencyCode,
                    'due_at' => $this->dueAt($plan['full_due'] ?? 'on_signing', $plan['full_due_date'] ?? null, $zone)],
                ['kind' => 'balance', 'amount_minor' => $totalMinor - $deposit, 'currency_code' => $currencyCode,
                    'due_at' => $this->dueAt($plan['balance_due'] ?? 'after_deposit', $plan['balance_due_date'] ?? null, $zone)],
            ];
        }

        return [
            ['kind' => 'full', 'amount_minor' => $totalMinor, 'currency_code' => $currencyCode,
                'due_at' => $this->dueAt($plan['full_due'] ?? 'on_signing', $plan['full_due_date'] ?? null, $zone)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $allowed
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $errors
     */
    private function timing(array $input, string $key, string $dateKey, array $allowed, string $default, array &$plan, array &$errors): void
    {
        $value = $input[$key] ?? $default;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $errors[$key] = 'Choose when this payment is due.';

            return;
        }

        $plan[$key] = $value;

        if ($value !== 'date') {
            return;
        }

        $date = $input[$dateKey] ?? null;

        if (! is_string($date) || ! $this->validDate($date)) {
            $errors[$dateKey] = 'Enter a valid due date.';

            return;
        }

        $plan[$dateKey] = $date;
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $problems = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function dueAt(string $timing, ?string $date, DateTimeZone $zone): ?Carbon
    {
        if ($timing !== 'date' || $date === null) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $date, $zone)
            ->endOfDay()
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    private function zone(?string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone !== null && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return new DateTimeZone((string) config('app.timezone', 'UTC'));
        }
    }
}
