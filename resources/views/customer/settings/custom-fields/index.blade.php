@extends('layouts/contentLayoutMaster')

{{--
    Settings → Custom fields. Presentation only: every rule (stable keys,
    types, options, archive, order) lives in CustomFieldDefinitionManager and
    a refusal comes back through the `custom_field` error bag as it worded it.
    The merge field shown for each row is the canonical {{contact.key}} token
    and never changes when the name is edited.
--}}

@section('title', 'Custom fields')

@section('page-style')
    <style>
        .mg-cf{max-width:64rem}
        .mg-cf-back{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--color-text-muted,#6f6d67);text-decoration:none;margin-bottom:1rem}
        .mg-cf-back:hover{color:var(--color-primary,#b5524c)}
        .mg-cf-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.25rem}
        .mg-cf-head > div{min-width:0}
        .mg-cf-title{font-size:1.6rem;font-weight:700;margin:0 0 .25rem}
        .mg-cf-sub{margin:0;color:var(--color-text-muted,#6f6d67);max-width:42rem}
        .mg-cf-card{background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.9rem;overflow:hidden}
        .mg-cf-bar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:.75rem 1rem;border-bottom:1px solid var(--color-border-neutral,#e5e1da)}
        .mg-cf-tabs{display:inline-flex;gap:.25rem;padding:.2rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.6rem;background:var(--color-surface-secondary,#fbfaf7)}
        .mg-cf-tab{border:0;background:transparent;padding:.3rem .8rem;border-radius:.45rem;font-size:.85rem;color:var(--color-text-muted,#6f6d67)}
        .mg-cf-tab[aria-selected="true"]{background:var(--color-surface-primary,#fff);color:inherit;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08)}
        .mg-cf-hint{font-size:.8rem;color:var(--color-text-muted,#6f6d67)}
        .mg-cf-table{width:100%;border-collapse:collapse}
        .mg-cf-table th{padding:.6rem 1rem;font-size:.7rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted,#6f6d67);text-align:left;white-space:nowrap}
        .mg-cf-table td{padding:.7rem 1rem;border-top:1px solid var(--color-border-neutral,#e5e1da);vertical-align:middle}
        .mg-cf-table th.mg-cf-end,.mg-cf-table td.mg-cf-end{text-align:right;white-space:nowrap}
        .mg-cf-field{display:flex;align-items:center;gap:.75rem;min-width:0}
        .mg-cf-order{display:inline-flex;flex-direction:column;flex:none}
        .mg-cf-order form{margin:0}
        .mg-cf-iconbtn{display:inline-flex;align-items:center;justify-content:center;width:1.7rem;height:1.7rem;padding:0;border:0;border-radius:.45rem;background:#efece7;color:#6f6d67;cursor:pointer}
        .mg-cf-iconbtn:hover:not(:disabled){background:#e5e1da}
        .mg-cf-iconbtn:disabled{opacity:.35;cursor:not-allowed}
        .mg-cf-iconbtn:focus-visible,.mg-cf-order button:focus-visible{outline:2px solid var(--color-primary,#b5524c);outline-offset:1px}
        .mg-cf-order button{display:inline-flex;align-items:center;justify-content:center;width:1.4rem;height:1rem;padding:0;border:0;background:transparent;color:#8a867d;cursor:pointer;border-radius:.25rem}
        .mg-cf-order button:disabled{opacity:.3;cursor:not-allowed}
        .mg-cf-typeicon{flex:none;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.55rem;background:var(--color-primary-soft-bg,#f4e6e4);color:var(--color-primary,#b5524c)}
        .mg-cf-label{font-weight:600;min-width:0;overflow-wrap:anywhere}
        .mg-cf-badge{display:inline-block;padding:.15rem .6rem;border-radius:999px;background:#efece7;color:#57534b;font-size:.75rem;white-space:nowrap}
        .mg-cf-actions{display:inline-flex;align-items:center;gap:.4rem;justify-content:flex-end}
        .mg-cf-menu{position:relative}
        .mg-cf-menu .dropdown-menu{min-width:9rem;padding:.25rem}
        .mg-cf-menu .dropdown-item{border-radius:.4rem;font-size:.875rem}
        .mg-cf-empty{padding:1.5rem 1rem;margin:0;color:var(--color-text-muted,#6f6d67)}
        .mg-cf-note{display:flex;gap:.75rem;align-items:flex-start;margin-top:1.25rem;padding:.9rem 1rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.8rem;background:var(--color-surface-primary,#fff);font-size:.85rem}
        .mg-cf-note p{margin:0}
        .mg-cf-note p + p{margin-top:.2rem}
        .mg-cf-note-icon{flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;border-radius:999px;background:#e6eefb;color:#2b5fb8}
        html.dark-layout .mg-cf-iconbtn,html.dark-layout .mg-cf-badge{background:rgba(255,255,255,.08);color:var(--color-text-secondary,#bdbbb5)}
        html.dark-layout .mg-cf-iconbtn:hover:not(:disabled){background:rgba(255,255,255,.14)}
        html.dark-layout .mg-cf-order button{color:var(--color-text-muted,#a09e97)}
        html.dark-layout .mg-cf-note-icon{background:rgba(75,134,220,.2);color:#8fb6f0}
        @media (max-width:575.98px){
            .mg-cf-head{flex-direction:column}
            .mg-cf-table th,.mg-cf-table td{padding:.6rem .6rem}
            .mg-cf-hint{display:none}
            .mg-cf-col-merge,.mg-cf-col-type{display:none}
        }
    </style>

    <script>
        (function () {
            var live = document.createElement('span');
            live.className = 'visually-hidden';
            live.setAttribute('role', 'status');
            document.body.appendChild(live);

            function fallbackCopy(text) {
                var area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(area);
            }

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var text = button.getAttribute('data-copy');
                    var original = button.getAttribute('title');
                    var done = function () {
                        live.textContent = 'Copied ' + text;
                        button.setAttribute('title', 'Copied');
                        setTimeout(function () { button.setAttribute('title', original || ''); live.textContent = ''; }, 1500);
                    };

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
                    } else {
                        fallbackCopy(text);
                        done();
                    }
                });
            });

            var tabs = document.querySelectorAll('[data-role="custom-field-tab"]');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (other) {
                        var on = other === tab;
                        other.setAttribute('aria-selected', on ? 'true' : 'false');
                        var panel = document.querySelector('[data-panel="' + other.getAttribute('data-filter') + '"]');
                        if (panel) { panel.hidden = !on; }
                    });
                });
            });
        })();
    </script>
@endsection

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.custom-fields.' . $name, [...$scope, ...$extra]);
        $canManage = auth()->user()?->can('update_contact');
        $optionTypes = collect($types)->filter(fn ($type) => $type->hasOptions())->map(fn ($type) => $type->value)->values();
        $activeFields = $fields->reject(fn ($field) => $field->isArchived())->values();
        $archivedFields = $fields->filter(fn ($field) => $field->isArchived())->values();
        $typeIcons = ['date' => 'calendar', 'datetime' => 'calendar', 'text' => 'type', 'long_text' => 'type', 'number' => 'hash', 'currency' => 'banknote', 'select' => 'list-checks', 'multi_select' => 'list-checks', 'boolean' => 'check-square', 'email' => 'mail', 'phone' => 'hash'];
        $lastIndex = $fields->count() - 1;
    @endphp

    <div class="mg-cf" data-role="settings-module-header">
        <a href="{{ route('customer.workspaces.businesses.settings.show', $scope) }}" class="mg-cf-back">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
            Back to Settings
        </a>

        <div class="mg-cf-head">
            <div>
                <h1 class="mg-cf-title">Custom fields</h1>
                <p class="mg-cf-sub">The extra details you keep about contacts — like an event date or venue. Define them once here and use them everywhere: on a contact, in forms, in messages and in automations.</p>
            </div>
            @if ($canManage)
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#custom-field-create" data-role="custom-field-add">+ Add field</button>
            @endif
        </div>

        @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
            @if (session($key))
                <x-alert :dismissible="true" :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
            @endif
        @endforeach

        @error('custom_field')
            <x-alert variant="danger" class="mb-2" role="alert" data-role="custom-field-error">{{ $message }}</x-alert>
        @enderror

        <section id="custom-fields" data-role="custom-fields" aria-label="Custom fields">
            <div class="mg-cf-card">
                <div class="mg-cf-bar">
                    <div class="mg-cf-tabs" role="tablist" aria-label="Field status">
                        <button type="button" class="mg-cf-tab" role="tab" id="cf-tab-active" aria-controls="cf-panel-active" aria-selected="true" data-role="custom-field-tab" data-filter="active">Active · <span data-role="custom-field-count-active">{{ $activeFields->count() }}</span></button>
                        <button type="button" class="mg-cf-tab" role="tab" id="cf-tab-archived" aria-controls="cf-panel-archived" aria-selected="false" data-role="custom-field-tab" data-filter="archived">Archived · <span data-role="custom-field-count-archived">{{ $archivedFields->count() }}</span></button>
                    </div>
                    @if ($canManage)
                        <span class="mg-cf-hint">Use the arrows to change the order fields appear on a contact.</span>
                    @endif
                </div>

                @foreach (['active' => $activeFields, 'archived' => $archivedFields] as $status => $group)
                    <div role="tabpanel" id="cf-panel-{{ $status }}" aria-labelledby="cf-tab-{{ $status }}" data-panel="{{ $status }}" @if ($status === 'archived') hidden @endif>
                        @if ($fields->isEmpty() && $status === 'active')
                            <p class="mg-cf-empty" data-role="custom-fields-empty">No custom fields yet. Add one — for example "Event date" — to start collecting it.</p>
                        @elseif ($group->isEmpty())
                            <p class="mg-cf-empty" data-role="custom-fields-empty-{{ $status }}">{{ $status === 'active' ? 'Every field is archived. Restore one from the Archived tab to use it again.' : 'No archived fields. Archiving hides a field from new use without losing its values.' }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="mg-cf-table" @if ($status === 'active') data-role="custom-field-list" @else data-role="custom-field-list-archived" @endif>
                                    <thead>
                                        <tr>
                                            <th scope="col">Field</th>
                                            <th scope="col" class="mg-cf-col-type">Type</th>
                                            <th scope="col" class="mg-cf-col-merge">Merge field</th>
                                            <th scope="col" class="mg-cf-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($group as $field)
                                            @php
                                                $fieldIndex = $fields->search(fn ($candidate) => $candidate->uid === $field->uid);
                                            @endphp
                                            <tr data-role="custom-field" data-key="{{ $field->key }}" data-status="{{ $status }}">
                                                <td>
                                                    <div class="mg-cf-field">
                                                        @if ($canManage && $status === 'active')
                                                            <div class="mg-cf-order">
                                                                <form method="POST" action="{{ $route('move', [$field->uid]) }}">
                                                                    @csrf
                                                                    <input type="hidden" name="direction" value="up">
                                                                    <button type="submit" aria-label="Move {{ $field->label }} up" title="Move up" data-role="custom-field-move-up" @disabled($fieldIndex === 0)><x-ds-icon name="chevron-up" size="14" aria-hidden="true" /></button>
                                                                </form>
                                                                <form method="POST" action="{{ $route('move', [$field->uid]) }}">
                                                                    @csrf
                                                                    <input type="hidden" name="direction" value="down">
                                                                    <button type="submit" aria-label="Move {{ $field->label }} down" title="Move down" data-role="custom-field-move-down" @disabled($fieldIndex === $lastIndex)><x-ds-icon name="chevron-down" size="14" aria-hidden="true" /></button>
                                                                </form>
                                                            </div>
                                                        @endif
                                                        <span class="mg-cf-typeicon"><x-ds-icon :name="$typeIcons[$field->type] ?? 'type'" size="16" aria-hidden="true" /></span>
                                                        <span class="mg-cf-label">{{ $field->label }}</span><span class="visually-hidden d-sm-none"> ({{ \App\Enums\CustomFields\CustomFieldType::from($field->type)->label() }})</span>
                                                    </div>
                                                </td>
                                                <td class="mg-cf-col-type"><span class="mg-cf-badge">{{ \App\Enums\CustomFields\CustomFieldType::from($field->type)->label() }}</span></td>
                                                <td class="mg-cf-col-merge">
                                                    <code data-role="custom-field-token" class="visually-hidden">{{ $field->token() }}</code>
                                                    <button type="button" class="mg-cf-iconbtn" data-copy="{{ $field->token() }}" data-role="custom-field-copy" aria-label="Copy merge field {{ $field->token() }}" title="Copy {{ $field->token() }}"><x-ds-icon name="copy" size="14" aria-hidden="true" /></button>
                                                </td>
                                                <td class="mg-cf-end">
                                                    <span class="mg-cf-actions">
                                                        {{-- The Merge field column is hidden on phones, so the copy action moves here. --}}
                                                        <button type="button" class="mg-cf-iconbtn d-sm-none" data-copy="{{ $field->token() }}" aria-label="Copy merge field {{ $field->token() }}" title="Copy {{ $field->token() }}"><x-ds-icon name="copy" size="14" aria-hidden="true" /></button>
                                                        @if ($canManage)
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#custom-field-edit-{{ $field->uid }}" data-role="custom-field-edit">Edit</button>
                                                            <div class="dropdown mg-cf-menu">
                                                                <button type="button" class="mg-cf-iconbtn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $field->label }}" data-role="custom-field-more"><x-ds-icon name="ellipsis" size="16" aria-hidden="true" /></button>
                                                                <div class="dropdown-menu dropdown-menu-end">
                                                                    @if ($field->isArchived())
                                                                        <form method="POST" action="{{ $route('restore', [$field->uid]) }}">
                                                                            @csrf
                                                                            <button type="submit" class="dropdown-item" data-role="custom-field-restore">Restore</button>
                                                                        </form>
                                                                    @else
                                                                        <form method="POST" action="{{ $route('archive', [$field->uid]) }}">
                                                                            @csrf
                                                                            <button type="submit" class="dropdown-item" data-role="custom-field-archive">Archive</button>
                                                                        </form>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mg-cf-note" data-role="custom-fields-note">
                <span class="mg-cf-note-icon"><x-ds-icon name="info" size="16" aria-hidden="true" /></span>
                <div>
                    <p><strong>Renaming is safe.</strong> The merge field never changes, so existing messages and automations keep working.</p>
                    <p><strong>Archiving hides a field from new use.</strong> Its values, and anything that already uses it, are kept.</p>
                </div>
            </div>
        </section>
    </div>

    @if ($canManage)
        <x-dialog id="custom-field-create" title="Add a custom field">
            <form method="POST" action="{{ $route('store') }}" data-role="custom-field-create-form">
                @csrf
                <div class="mb-1">
                    <label for="cf-label" class="form-label">Name</label>
                    <input id="cf-label" name="label" class="form-control" maxlength="80" required value="{{ old('label') }}" placeholder="Event date">
                </div>
                <div class="mb-1">
                    <label for="cf-type" class="form-label">Type</label>
                    <select id="cf-type" name="type" class="form-select" data-role="custom-field-type" required>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">The type can't be changed later.</small>
                </div>
                <div class="mb-1" data-role="custom-field-options" hidden>
                    <label for="cf-options" class="form-label">Options <span class="text-muted">(one per line)</span></label>
                    <textarea id="cf-options" name="options" rows="4" class="form-control">{{ old('options') }}</textarea>
                </div>
                <p class="text-caption">The merge field is created from the name — for example <code>@{{contact.event_date}}</code> — and stays the same if you rename the field.</p>
                <div class="text-end">
                    <button type="submit" class="btn btn-primary">Add field</button>
                </div>
            </form>
        </x-dialog>

        @foreach ($fields as $field)
            <x-dialog :id="'custom-field-edit-' . $field->uid" :title="'Edit ' . $field->label">
                <form method="POST" action="{{ $route('update', [$field->uid]) }}" data-role="custom-field-edit-form">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="cf-edit-label-{{ $field->uid }}">Name</label>
                        <input id="cf-edit-label-{{ $field->uid }}" name="label" class="form-control" maxlength="80" required value="{{ $field->label }}">
                    </div>
                    <p class="text-caption">
                        Type: {{ \App\Enums\CustomFields\CustomFieldType::from($field->type)->label() }} ·
                        Merge field: <code>{{ $field->token() }}</code> (fixed)
                    </p>
                    @if ($field->fieldType()->hasOptions())
                        <div class="mb-1">
                            <p class="form-label mb-25">Options</p>
                            @foreach ($field->optionList() as $i => $option)
                                <div class="mb-50">
                                    <input type="hidden" name="options[{{ $i }}][id]" value="{{ $option['id'] }}">
                                    <input name="options[{{ $i }}][label]" class="form-control form-control-sm" maxlength="80" value="{{ $option['label'] }}" aria-label="Option {{ $i + 1 }}">
                                </div>
                            @endforeach
                            <small class="text-muted d-block mb-50">Rename an option freely — stored answers keep pointing at it. Clear a name to remove it.</small>
                            <label class="form-label" for="cf-new-options-{{ $field->uid }}">Add options <span class="text-muted">(one per line)</span></label>
                            <textarea id="cf-new-options-{{ $field->uid }}" name="new_options" rows="2" class="form-control"></textarea>
                        </div>
                    @endif
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </x-dialog>
        @endforeach

        <script>
            (function () {
                var optionTypes = @json($optionTypes);
                var select = document.querySelector('[data-role="custom-field-type"]');
                var options = document.querySelector('[data-role="custom-field-options"]');

                function sync() {
                    if (select && options) {
                        options.hidden = optionTypes.indexOf(select.value) === -1;
                    }
                }

                if (select) {
                    select.addEventListener('change', sync);
                    sync();
                }

            })();
        </script>
    @endif

    <script>
        (function () {
            var live = document.createElement('span');
            live.className = 'visually-hidden';
            live.setAttribute('role', 'status');
            document.body.appendChild(live);

            function fallbackCopy(text) {
                var area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(area);
            }

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var text = button.getAttribute('data-copy');
                    var original = button.getAttribute('title');
                    var done = function () {
                        live.textContent = 'Copied ' + text;
                        button.setAttribute('title', 'Copied');
                        setTimeout(function () { button.setAttribute('title', original || ''); live.textContent = ''; }, 1500);
                    };

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
                    } else {
                        fallbackCopy(text);
                        done();
                    }
                });
            });

            var tabs = document.querySelectorAll('[data-role="custom-field-tab"]');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (other) {
                        var on = other === tab;
                        other.setAttribute('aria-selected', on ? 'true' : 'false');
                        var panel = document.querySelector('[data-panel="' + other.getAttribute('data-filter') + '"]');
                        if (panel) { panel.hidden = !on; }
                    });
                });
            });
        })();
    </script>
@endsection
