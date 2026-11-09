<?php

namespace App\Library\ErrorPages;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The single source of copy, icons and navigation for the platform's customer-facing
 * error pages (resources/views/components/error-page.blade.php).
 *
 * The layout never varies. Only the title, description, icon and buttons do, chosen
 * from a small fixed table keyed by status and request context. Nothing here reads the
 * exception, so a message, path, class name or token can never reach the page, and
 * nothing varies with whether a protected resource exists.
 */
class ErrorPagePresenter
{
    public const CONTEXT_GENERIC = 'generic';
    public const CONTEXT_DOCUMENT = 'document';
    public const CONTEXT_MODULE = 'module';

    /** Every status the unified page renders, with the soft status tone of its icon. */
    public const CODES = [
        401 => 'info',
        403 => 'warn',
        404 => 'neutral',
        419 => 'warn',
        429 => 'warn',
        500 => 'danger',
        503 => 'info',
    ];

    /**
     * @return array{code: int, title: string, description: string, icon: string, tone: string, card: ?array{title: string, text: string}, homeUrl: string, primary: array{label: string, url: string}, secondary: ?array{label: string, url: string}, context: string}
     */
    public function for(int $code, ?Request $request = null): array
    {
        $request ??= request();
        $code = array_key_exists($code, self::CODES) ? $code : ($code >= 500 ? 500 : 404);
        $context = $this->context($request);
        $publicHome = $this->publicHomeUrl();
        $loginUrl = $this->loginUrl();

        $card = null;
        if ($code === 404) {
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

        $homepage = ['label' => __('locale.error_pages.go_homepage'), 'url' => $publicHome];
        $primary = $homepage;
        $secondary = null;

        if ($code === 401) {
            $primary = ['label' => __('locale.error_pages.log_in'), 'url' => $loginUrl];
        } elseif ($code === 419) {
            $primary = ['label' => __('locale.error_pages.log_in_again'), 'url' => $loginUrl];
            $secondary = $homepage;
        } elseif ($code === 403 && $context !== self::CONTEXT_DOCUMENT && ($dashboard = $this->dashboardUrl()) !== null) {
            $primary = ['label' => __('locale.error_pages.go_dashboard'), 'url' => $dashboard];
        }

        return [
            'code'        => $code,
            'title'       => __("locale.error_pages.pages.{$code}.title"),
            'description' => __("locale.error_pages.pages.{$code}.description"),
            'icon'        => $this->icon($code),
            'tone'        => self::CODES[$code],
            'card'        => $card,
            'homeUrl'     => $primary['url'],
            'primary'     => $primary,
            'secondary'   => $secondary,
            'context'     => $context,
        ];
    }

    public function context(Request $request): string
    {
        try {
            if ($request->routeIs('public.documents.*')) {
                return self::CONTEXT_DOCUMENT;
            }

            if ($request->routeIs('customer.*')) {
                return self::CONTEXT_MODULE;
            }
        } catch (Throwable) {
            // no route context (maintenance prerender, console)
        }

        return self::CONTEXT_GENERIC;
    }

    /** The public homepage. Never a dashboard, so it is valid for every visitor. */
    public function publicHomeUrl(): string
    {
        return url('/');
    }

    public function loginUrl(): string
    {
        try {
            return route('login');
        } catch (Throwable) {
            return url('/login');
        }
    }

    /** The signed-in user's own landing page, or null for a guest (or when it cannot be resolved). */
    public function dashboardUrl(): ?string
    {
        try {
            if (Auth::check()) {
                return Helper::home_route();
            }
        } catch (Throwable) {
            // fall through: no dashboard link
        }

        return null;
    }

    /** Inline Lucide-style glyph paths (24x24 viewBox) so the page needs no icon library. */
    private function icon(int $code): string
    {
        return match ($code) {
            401 => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            403 => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
            404 => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
            419 => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            429 => '<path d="M12 3l10 18H2z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>',
            500 => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6"/><path d="M12 16.5v.01"/>',
            default => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6"/><path d="M14 9v6"/>',
        };
    }
}
