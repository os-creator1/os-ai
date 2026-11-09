{{--
    Unified customer-facing error page: see components/error-page.blade.php.
    Used both for a live 503 and as the maintenance-mode prerender (`artisan down --render`, which passes
    $retryAfter). The "notify me" form is offered only where the endpoint can accept it: while the application is
    in maintenance mode, or when this view is being prerendered for it.
--}}
@php
    $maintenanceNotifyAvailable = false;
    try {
        $maintenanceNotifyAvailable = isset($retryAfter) || app()->maintenanceMode()->active();
    } catch (\Throwable) {
        $maintenanceNotifyAvailable = false;
    }
@endphp
<x-error-page code="503" :notify="$maintenanceNotifyAvailable" />
