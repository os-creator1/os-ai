{{--
    Public Marketing Homepage contract. Pulled directly from
    App\Library\Entitlement\PlatformFeatureCopy — the same copy the plan
    catalog itself uses — so this section can never drift into claiming a
    capability that is not actually implemented.
--}}
<div class="marketing-product-grid">
    @foreach (\App\Library\Entitlement\PlatformFeatureCopy::keys() as $featureKey)
        <div class="marketing-product-card">
            <h3>{{ \App\Library\Entitlement\PlatformFeatureCopy::name($featureKey) }}</h3>
            <p>{{ \App\Library\Entitlement\PlatformFeatureCopy::description($featureKey) }}</p>
        </div>
    @endforeach
</div>
