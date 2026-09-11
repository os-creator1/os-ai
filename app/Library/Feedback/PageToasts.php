<?php

namespace App\Library\Feedback;

use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\ViewErrorBag;

/**
 * Customer notification cleanup — the toasts a customer-facing page load
 * shows, rendered by <x-toast-region> in the Business OS style.
 *
 * Customer-facing means the customer shell and the signed-out pages (sign
 * in, sign up, password reset). The admin portal keeps its Toastr
 * notifications unchanged.
 *
 * A toast only says what the page does not already say:
 *
 *  - the flashed status/message pair is left out when the page shows it
 *    inline — <x-flash-alert> and the sign-in summary mark that element
 *    data-role="flash-message" / data-role="auth-flash";
 *  - the first validation error is left out when the page shows its field
 *    errors — a field marked is-invalid or aria-invalid="true", or a
 *    data-role="validation-summary" list. Pages that show neither keep the
 *    toast, so a validation error is never silently lost.
 *
 * Nothing here changes what a controller flashes; this only decides where
 * it is shown.
 */
final class PageToasts
{
    /** Flash status => toast variant. Any other status shows no toast, as before. */
    private const VARIANTS = [
        'success' => 'success',
        'info' => 'info',
        'warning' => 'warning',
        'error' => 'error',
    ];

    private const FLASH_SHOWN_INLINE = ['data-role="flash-message"', 'data-role="auth-flash"'];

    private const FIELD_ERRORS_SHOWN_INLINE = '/\bclass="[^"]*\bis-invalid\b|\baria-invalid="true"|data-role="validation-summary"/';

    /** Sections that hold scripts and styles, not what the customer reads. */
    private const NON_CONTENT_SECTIONS = ['title', 'page-script', 'vendor-script', 'page-style', 'vendor-style'];

    public static function appliesTo(?User $user): bool
    {
        return $user === null || CustomerShellComposer::isCustomerPortal($user);
    }

    /**
     * @param  array<string, string>  $sections  the page's rendered Blade sections
     * @return list<array{variant: string, title: ?string, message: string}>
     */
    public static function forPage(Session $session, ?ViewErrorBag $errors, array $sections, ?User $user): array
    {
        $content = implode("\n", array_diff_key($sections, array_flip(self::NON_CONTENT_SECTIONS)));
        $toasts = [];

        $status = $session->get('status', 'success');
        $message = $session->get('message');

        if (is_string($status) && isset(self::VARIANTS[$status]) && is_string($message) && ! self::flashShownInline($content)) {
            $title = $session->get('message_title');
            $toasts[] = self::toast(self::VARIANTS[$status], $message, is_string($title) ? $title : null);
        }

        // Carried over from the former Toastr renderer, same condition.
        if ($session->get('check_subscription') && $user !== null && CustomerShellComposer::isCustomerPortal($user)
            && $user->customer !== null && $user->customer->activeSubscription() === null) {
            $toasts[] = self::toast('warning', __('locale.customer.no_active_subscription'));
        }

        if ($errors !== null && $errors->any() && preg_match(self::FIELD_ERRORS_SHOWN_INLINE, $content) !== 1) {
            $toasts[] = self::toast('error', (string) $errors->first());
        }

        return array_values(array_filter($toasts, fn (array $toast): bool => $toast['message'] !== ''));
    }

    private static function flashShownInline(string $content): bool
    {
        foreach (self::FLASH_SHOWN_INLINE as $marker) {
            if (str_contains($content, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toastr rendered these strings as HTML; the Business OS toast renders
     * text only, so markup inside a legacy message is reduced to its words.
     *
     * @return array{variant: string, title: ?string, message: string}
     */
    private static function toast(string $variant, string $message, ?string $title = null): array
    {
        $title = $title === null ? '' : self::plainText($title);

        return [
            'variant' => $variant,
            'title' => $title === '' ? null : $title,
            'message' => self::plainText($message),
        ];
    }

    private static function plainText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
