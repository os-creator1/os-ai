@extends('layouts.contentLayoutMaster')

@section('title', __('locale.menu.All Settings'))

{{--
    B3 Simplified Platform Settings. Replaces the inherited 9-tab "All
    Settings" vendor screen (resources/views/admin/settings/AllSettings/
    system_settings.blade.php) with one page of six sections, built from
    the existing M2 component library the same way
    admin/theme-settings/index.blade.php already is. Section visibility
    (the $tabs array built in SettingsController::general()) mirrors each
    section's own write ability, so a scoped admin never sees a tab whose
    form they could not submit.
--}}

@section('content')
    <section id="platform-settings">
        @if (session('status'))
            <x-alert variant="{{ session('status') === 'success' ? 'success' : 'danger' }}" class="mb-3">
                {{ session('message') }}
                @if (session('notify'))
                    <div class="mt-1">{{ session('notify') }}</div>
                @endif
            </x-alert>
        @endif

        @if ($errors->any())
            <x-alert variant="danger" class="mb-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <x-card :padded="false">
            <div class="card-body">
                <x-tabs :tabs="$tabs" :active="$activeTab" id="platform-settings-tabs">
                    @if (isset($tabs['platform']))
                        <div class="tab-pane fade @if ($activeTab === 'platform') show active @endif" id="platform-settings-tabs-platform" role="tabpanel">
                            @include('admin.settings.platform._platform')
                        </div>
                    @endif

                    @if (isset($tabs['appearance']))
                        <div class="tab-pane fade @if ($activeTab === 'appearance') show active @endif" id="platform-settings-tabs-appearance" role="tabpanel">
                            @include('admin.settings.platform._appearance')
                        </div>
                    @endif

                    @if (isset($tabs['email']))
                        <div class="tab-pane fade @if ($activeTab === 'email') show active @endif" id="platform-settings-tabs-email" role="tabpanel">
                            @include('admin.settings.platform._email')
                        </div>
                    @endif

                    @if (isset($tabs['security']))
                        <div class="tab-pane fade @if ($activeTab === 'security') show active @endif" id="platform-settings-tabs-security" role="tabpanel">
                            @include('admin.settings.platform._security')
                        </div>
                    @endif

                    @if (isset($tabs['ai']))
                        <div class="tab-pane fade @if ($activeTab === 'ai') show active @endif" id="platform-settings-tabs-ai" role="tabpanel">
                            @include('admin.settings.platform._ai')
                        </div>
                    @endif

                    @if (isset($tabs['advanced']))
                        <div class="tab-pane fade @if ($activeTab === 'advanced') show active @endif" id="platform-settings-tabs-advanced" role="tabpanel">
                            @include('admin.settings.platform._advanced', [
                                'permissionGroups' => $permissionGroups,
                                'existingPermissions' => $existingPermissions,
                                'phpBinPath' => $phpBinPath,
                                'execEnabled' => $execEnabled,
                            ])
                        </div>
                    @endif
                </x-tabs>
            </div>
        </x-card>
    </section>
@endsection

@section('page-script')
    <script>
        $(document).ready(function () {
            $(".select2").each(function () {
                let $this = $(this);
                $this.wrap('<div class="position-relative"></div>');
                $this.select2({
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent()
                });
            });

            // Reveal a section's dependent fields (SMTP host/port/etc.,
            // a Sign-in & Security provider's client id/secret) only once
            // its own controlling <select> equals data-reveal-value
            // (defaults to '1' for a plain Yes/No boolean select).
            function toggleRevealedFields(selectEl) {
                let $select = $(selectEl);
                let target = $select.data('reveals');
                if (!target) {
                    return;
                }
                let revealValue = String($select.data('reveal-value') ?? '1');
                $(target).toggleClass('d-none', $select.val() !== revealValue);
            }

            $('[data-reveals]').each(function () {
                toggleRevealedFields(this);
            }).on('change', function () {
                toggleRevealedFields(this);
            });
        });
    </script>
@endsection
