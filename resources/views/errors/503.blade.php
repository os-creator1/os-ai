{{--
    Unified customer-facing error page: see components/error-page.blade.php.
    Used for a live 503 and as the maintenance-mode prerender (`artisan down --render`, which passes $retryAfter).

    The prerender is produced by the CLI, outside any visitor session, so it can never contain a CSRF token that is
    valid for the visitor. It therefore carries only a plain link to the session-backed form (maintenance.notify.form,
    rendered live), and the form itself, with its token, appears only on a live response while maintenance is active.
--}}
@php
    $maintenanceNotify = false;
    try {
        if (isset($retryAfter)) {
            $maintenanceNotify = 'link';
        } elseif (app()->maintenanceMode()->active()) {
            $maintenanceNotify = 'form';
        }
    } catch (\Throwable) {
        $maintenanceNotify = false;
    }
@endphp
<x-error-page code="503" :notify="$maintenanceNotify" />
