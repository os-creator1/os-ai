<?php

namespace App\Library\ErrorPages;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The single source of copy and navigation for the platform's branded error
 * pages (resources/views/components/error-page.blade.php).
 *
 * The layout never varies. Only the title, description and optional context
 * card do, chosen from a small fixed table keyed by status and request
 * context. Nothing here reads the exception, so a message, path or token can
 * never reach the page, and nothing varies with whether a protected resource
 * exists.
 */
class ErrorPagePresenter
{
    public const CONTEXT_GENERIC = 'generic';
    public const CONTEXT_DOCUMENT = 'document';
    public const CONTEXT_MODULE = 'module';

    /**
     * @return array{code: int, title: string, description: string, card: ?array{title: string, text: string}, homeUrl: string, context: string}
     */
    public function for(int $code, ?Request $request = null): array
    {
        $request ??= request();
        $context = $this->context($request);
        // 401/403 reuse the platform's existing copy (locale.http.*) inside the same shell; anything else is a 404.
        [$shown, $titleKey] = match ($code) {
            401, 403 => [$code, "locale.http.{$code}"],
            429 => [429, 'locale.error_pages.429'],
            default => [404, 'locale.error_pages.404'],
        };

        $card = null;
        if ($shown === 404) {
            $card = match ($context) {
                self::CONTEXT_DOCUMENT => [
                    'title' => __('locale.error_pages.document.card_title'),
                    'text'  => __('locale.error_pages.document.card_text'),
                ],
                self::CONTEXT_MODULE => [
                    'title' => __('locale.error_pages.module.card_title'),
                    'text'  => __('locale.error_pages.module.card_text'),
                ],
                default => null,
            };
        }

        return [
            'code'        => $shown,
            'title'       => __("{$titleKey}.title"),
            'description' => __("{$titleKey}.description"),
            'card'        => $card,
            'homeUrl'     => $this->homeUrl($context),
            'context'     => $context,
        ];
    }

    public function context(Request $request): string
    {
        if ($request->routeIs('public.documents.*')) {
            return self::CONTEXT_DOCUMENT;
        }

        if ($request->routeIs('customer.*')) {
            return self::CONTEXT_MODULE;
        }

        return self::CONTEXT_GENERIC;
    }

    /**
     * Where "Back to Home" goes: the signed-in user's own home, otherwise a
     * public destination. A public document recipient is never sent to a
     * dashboard they cannot open.
     */
    public function homeUrl(string $context): string
    {
        if ($context === self::CONTEXT_DOCUMENT) {
            return url('/');
        }

        try {
            if (Auth::check()) {
                return Helper::home_route();
            }
        } catch (Throwable) {
            // fall through to the public destination
        }

        return route('login');
    }
}
