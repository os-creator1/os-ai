@extends('layouts/contentLayoutMaster')

@section('title', 'Theme Settings')

{{--
    Design System M2 Slice 1 contract §9 item 33. Simple/Advanced token
    controls (§4), font selector + upload, embedded live preview,
    Cancel/Save/Activate/Delete actions per the open preset's own state,
    unsaved-change warning, audit info. Built entirely from the existing
    13-component library (§9 item 33).
--}}

@section('content')
    <section id="admin-theme-settings-index" data-active-preset-uid="{{ $presets->firstWhere('status', 'active')?->uid }}">
        @if (session('success'))
            <x-alert variant="success" class="mb-3">{{ session('success') }}</x-alert>
        @endif
        @if (session('warnings') && count(session('warnings')))
            <x-alert variant="warning" class="mb-3">
                <strong>Accessibility warnings:</strong>
                <ul class="mb-0">
                    @foreach (session('warnings') as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
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

        <div class="row">
            <div class="col-lg-3 mb-3">
                @include('admin.theme-settings._preset-list', ['presets' => $presets])
            </div>

            <div class="col-lg-9">
                <x-card :padded="false">
                    <x-slot:actions>
                        <span id="theme-preset-status-badge"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="theme-preset-rename-btn" data-bs-toggle="modal" data-bs-target="#theme-preset-rename-modal">Rename</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="theme-preset-duplicate-btn">Duplicate</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="theme-preset-delete-btn">Delete</button>
                        <button type="button" class="btn btn-sm btn-success" id="theme-preset-activate-btn">Activate</button>
                    </x-slot:actions>

                    <div class="card-body">
                        <h4 class="card-title mb-1" id="theme-preset-name-heading">&nbsp;</h4>
                        <p class="text-caption text-muted mb-3" id="theme-preset-audit-info"></p>

                        <form id="theme-preset-form" novalidate>
                            @csrf
                            <input type="hidden" name="_method" value="PUT">
                            <input type="hidden" id="theme-preset-uid" value="">

                            <div id="theme-preset-readonly-notice" class="d-none">
                                <x-alert variant="warning" class="mb-3">
                                    This preset cannot be edited directly. Duplicate it to create an editable copy.
                                </x-alert>
                            </div>

                            <x-tabs :tabs="['simple' => 'Simple', 'advanced' => 'Advanced']" id="theme-editor-tabs">
                                <div class="tab-pane fade show active" id="theme-editor-tabs-simple" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-section-heading">Brand</h6>
                                            <x-input type="color" name="primary" label="Primary" />
                                            <x-input type="color" name="secondary" label="Secondary / accent" />
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-section-heading">Header / navigation</h6>
                                            <x-select name="header_mode" label="Header background" :options="['follow' => 'Follow Primary', 'custom' => 'Custom']" />
                                            <div class="d-none" id="theme-header-custom-field">
                                                <x-input type="color" name="header" label="Custom header color" />
                                            </div>
                                        </div>
                                    </div>

                                    <h6 class="text-section-heading mt-2">Status colors</h6>
                                    <div class="row">
                                        <div class="col-md-4 col-6"><x-input type="color" name="status_success" label="Success" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="status_warning" label="Warning" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="status_danger" label="Danger" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="status_info" label="Info" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="status_pending" label="Pending" /></div>
                                    </div>

                                    <h6 class="text-section-heading mt-2">Surfaces &amp; border</h6>
                                    <div class="row">
                                        <div class="col-md-4 col-6"><x-input type="color" name="canvas" label="Canvas" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="sidebar" label="Sidebar" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="surface" label="Surface" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="surface_secondary" label="Secondary surface" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="input" label="Input / control" /></div>
                                        <div class="col-md-4 col-6"><x-input type="color" name="border" label="Border" /></div>
                                    </div>

                                    <h6 class="text-section-heading mt-2">Chart series</h6>
                                    <div class="row">
                                        @for ($i = 1; $i <= 8; $i++)
                                            <div class="col-md-3 col-6"><x-input type="color" name="chart_{{ $i }}" label="Series {{ $i }}" /></div>
                                        @endfor
                                        <div class="col-md-3 col-6"><x-input type="color" name="chart_neutral" label="Neutral" /></div>
                                    </div>

                                    <h6 class="text-section-heading mt-2">Application font</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <x-select name="font_choice" label="Font source" :options="['bundled' => 'Bundled', 'uploaded' => 'Uploaded']" />
                                        </div>
                                        <div class="col-md-4" id="theme-font-bundled-field">
                                            <x-select name="font_bundled_key" label="Bundled font" :options="['geist' => 'Geist', 'system-ui' => 'System UI']" />
                                        </div>
                                        <div class="col-md-4 d-none" id="theme-font-uploaded-field">
                                            <x-select name="font_uploaded_id" label="Uploaded font" :options="$uploadedFonts ?? []" />
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#theme-font-upload-modal">
                                            Upload a new font (.woff2 / .woff)
                                        </button>
                                    </div>
                                </div>

                                <div class="tab-pane fade" id="theme-editor-tabs-advanced" role="tabpanel">
                                    <p class="text-caption text-muted">
                                        Explicitly override any individual token. Overrides always win over the
                                        automatically derived value, even after other Simple fields change.
                                    </p>
                                    <div class="d-flex gap-2 mb-3">
                                        <select class="form-select" id="theme-override-key-select">
                                            <option value="">Choose a token…</option>
                                            @foreach (\App\Http\Requests\Admin\UpdatePlatformThemePresetRequest::OVERRIDABLE_KEYS as $key)
                                                <option value="{{ $key }}">{{ $key }}</option>
                                            @endforeach
                                        </select>
                                        <input type="color" id="theme-override-value-input" value="#000000" style="width: 3.5rem;">
                                        <x-button type="button" id="theme-override-add-btn" size="sm">Add override</x-button>
                                    </div>
                                    <table class="table table-sm" id="theme-override-table">
                                        <thead>
                                            <tr><th>Token</th><th>Value</th><th></th></tr>
                                        </thead>
                                        <tbody id="theme-override-table-body"></tbody>
                                    </table>
                                </div>
                            </x-tabs>

                            <div id="theme-contrast-feedback" class="mt-3"></div>

                            <div class="mt-3 d-flex gap-2">
                                <x-button type="submit" variant="primary" id="theme-preset-save-btn">Save</x-button>
                                <x-button type="button" variant="ghost" id="theme-preset-cancel-btn">Cancel</x-button>
                            </div>
                        </form>

                        <hr>
                        <h6 class="text-section-heading">Live preview</h6>
                        <div id="theme-preview" class="ds-theme-preview p-3 rounded border">
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn ds-preview-btn-primary">Primary button</button>
                                <button type="button" class="btn ds-preview-btn-secondary">Secondary button</button>
                            </div>
                            <p class="ds-preview-text-primary mb-1">Primary text on canvas</p>
                            <p class="ds-preview-text-muted mb-3">Muted secondary text</p>
                            <div class="d-flex gap-2 flex-wrap mb-3">
                                <span class="badge ds-preview-status" data-status="success">Success</span>
                                <span class="badge ds-preview-status" data-status="warning">Warning</span>
                                <span class="badge ds-preview-status" data-status="danger">Danger</span>
                                <span class="badge ds-preview-status" data-status="info">Info</span>
                                <span class="badge ds-preview-status" data-status="pending">Pending</span>
                            </div>
                            <div class="d-flex gap-1 mb-3" id="theme-preview-chart">
                                @for ($i = 1; $i <= 8; $i++)
                                    <div class="ds-preview-chart-bar" data-chart="{{ $i }}" style="width: 20px; height: 40px;"></div>
                                @endfor
                            </div>
                            <div class="d-flex gap-2">
                                <div class="ds-preview-chat-bubble-in p-2 rounded">Incoming message</div>
                                <div class="ds-preview-chat-bubble-out p-2 rounded">Outgoing message</div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>
        </div>
    </section>

    <div class="modal fade" id="theme-preset-rename-modal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="theme-preset-rename-form" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Rename preset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <x-input name="name" label="Preset name" required />
                </div>
                <div class="modal-footer">
                    <x-button type="submit" variant="primary">Rename</x-button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="theme-font-upload-modal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.theme-fonts.upload') }}" enctype="multipart/form-data" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Upload font</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="file" name="font" class="form-control" accept=".woff,.woff2" required>
                    <p class="text-caption text-muted mb-0 mt-2">WOFF or WOFF2 only, up to 5MB.</p>
                </div>
                <div class="modal-footer">
                    <x-button type="submit" variant="primary">Upload</x-button>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" id="theme-preset-duplicate-form" class="d-none">@csrf</form>
    <form method="POST" id="theme-preset-activate-form" class="d-none">@csrf</form>
    <form method="POST" id="theme-preset-delete-form" class="d-none">@csrf @method('DELETE')</form>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/scripts/pages/theme-settings.js')) }}"></script>
@endsection
