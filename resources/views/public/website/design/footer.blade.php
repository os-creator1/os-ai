{{--
    Website V1 final — the template-owned footer: brand + contact, the
    explore links, the services and the areas we serve. Every page appears
    here or in the header dropdowns, so none is orphaned. Escaped output only.
--}}
@php($footerLinks = $nav['footer'])
<footer class="wd-footer wd-footer-{{ $design->variant('footer') }}" data-testid="site-footer">
    <div class="website-container wd-footer-grid">
        <div class="wd-footer-brand">
            <a class="wd-logo wd-logo-footer" href="{{ $footerLinks['explore'][0]['url'] ?? '#' }}">
                @if ($logo)
                    {{ \App\Library\Website\Media\ResponsiveImage::tag($logo, '210px', ['class' => 'wd-logo-img']) }}
                @else
                    <span class="wd-wordmark">{{ $websiteMeta['name'] }}</span>
                @endif
            </a>
            <ul class="wd-footer-contact">
                @if ($siteContact['phone'])
                    <li><a href="tel:{{ preg_replace('/[^+0-9]/', '', $siteContact['phone']) }}">{{ \App\Library\Website\Design\PhoneDisplay::format($siteContact['phone']) }}</a></li>
                @endif
                @if ($siteContact['email'])
                    <li><a href="mailto:{{ $siteContact['email'] }}">{{ $siteContact['email'] }}</a></li>
                @endif
            </ul>
            @if ($siteCta)
                <a class="wd-btn wd-btn-primary" href="{{ $siteCta['url'] }}">{{ $siteCta['label'] }}</a>
            @endif
        </div>

        @if (count($footerLinks['explore']) > 1)
            <nav class="wd-footer-col" aria-label="Explore">
                <h2 class="wd-footer-heading">Explore</h2>
                <ul>
                    @foreach ($footerLinks['explore'] as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if (count($footerLinks['services']) > 0)
            <nav class="wd-footer-col" aria-label="Services">
                <h2 class="wd-footer-heading">Services</h2>
                <ul>
                    @foreach ($footerLinks['services'] as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if (count($footerLinks['areas']) > 0)
            <nav class="wd-footer-col" aria-label="Areas we serve">
                <h2 class="wd-footer-heading">Areas we serve</h2>
                <ul>
                    @foreach ($footerLinks['areas'] as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
    <div class="website-container wd-footer-legal">
        <p>&copy; {{ date('Y') }} {{ $websiteMeta['name'] }}</p>
    </div>
</footer>
