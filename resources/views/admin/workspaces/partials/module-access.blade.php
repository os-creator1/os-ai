@php
    use App\Library\Entitlement\WorkspaceModuleCatalog as Modules;
@endphp

{{-- Module Access — per-customer module switches. A thin view over the existing
     Workspace entitlement overrides (EntitlementManager); the effective badge is
     what the customer actually gets, not the stored choice. Plans, prices and
     Stripe subscriptions are never touched, and no customer data is removed. --}}
<section id="admin-workspace-module-access">
    <div class="row">
        <div class="col-12">
            <x-card title="Module Access">
                <p class="text-muted">
                    Choose which MotionGrove modules this customer can use. A disabled module disappears from their
                    navigation and is refused on direct links and actions; their data is kept and returns when the
                    module is restored. This does not change their plan or billing.
                </p>

                @unless ($entitlementSummary->isAssigned)
                    <x-alert variant="warning">Assign a plan before managing module access.</x-alert>
                @else
                    <form method="POST" action="{{ route('admin.workspaces.module-access.update', $workspace) }}">
                        @csrf
                        @method('PUT')

                        <div class="list-group list-group-flush mb-3">
                            @foreach (Modules::modules() as $moduleId => $module)
                                @php
                                    $stored = Modules::storedState($moduleId, $entitlementSummary->overrides);
                                    $effective = Modules::effective($moduleId, $entitlementSummary, $businessDisabledKeys ?? []);
                                    $canEnable = Modules::canEnable($moduleId);
                                    $choices = [Modules::STATE_DEFAULT => 'Plan default'];
                                    if ($canEnable) {
                                        $choices[Modules::STATE_ENABLED] = 'Enabled';
                                    }
                                    $choices[Modules::STATE_DISABLED] = 'Disabled';
                                @endphp
                                <div class="list-group-item px-0 d-flex flex-wrap align-items-center justify-content-between gap-2" data-module="{{ $moduleId }}">
                                    <div class="me-auto">
                                        <div class="fw-semibold">{{ $module['label'] }}</div>
                                        <div class="text-muted text-caption">{{ $module['description'] }}</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-badge :variant="$effective['on'] ? 'success' : 'danger'" data-effective="{{ $effective['on'] ? 'on' : 'off' }}">
                                            {{ $effective['on'] ? 'On' : 'Off' }} · {{ $effective['reason'] }}@if ($effective['on'] && $effective['restricted']) · off for {{ implode(', ', $effective['restricted']) }} (turned off by the business)@endif
                                        </x-badge>
                                        <div class="btn-group" role="group" aria-label="{{ $module['label'] }} access">
                                            @if ($stored === Modules::STATE_MIXED)
                                                <input type="radio" class="btn-check" name="modules[{{ $moduleId }}]" id="mod-{{ $moduleId }}-mixed" value="{{ Modules::STATE_MIXED }}" checked>
                                                <label class="btn btn-sm btn-outline-warning" for="mod-{{ $moduleId }}-mixed">Custom</label>
                                            @endif
                                            @foreach ($choices as $value => $label)
                                                <input type="radio" class="btn-check" name="modules[{{ $moduleId }}]" id="mod-{{ $moduleId }}-{{ $value }}" value="{{ $value }}" @checked($stored === $value)>
                                                <label class="btn btn-sm btn-outline-primary" for="mod-{{ $moduleId }}-{{ $value }}">{{ $label }}</label>
                                            @endforeach
                                        </div>
                                    </div>
                                    @if ($stored === Modules::STATE_MIXED)
                                        <div class="w-100 text-caption text-warning">Set individually in advanced overrides; pick a state to replace it.</div>
                                    @endif
                                    <details class="w-100">
                                        <summary class="text-muted text-caption">Advanced details</summary>
                                        <div class="text-caption text-muted mt-1">
                                            Feature keys:
                                            @foreach ($module['keys'] as $key)
                                                <code>{{ $key }}</code>@if ($entitlementSummary->overrides[$key] ?? null) ({{ $entitlementSummary->overrides[$key]->value }})@endif{{ $loop->last ? '' : ',' }}
                                            @endforeach
                                        </div>
                                    </details>
                                </div>
                            @endforeach
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-md-9">
                                <label for="module-access-reason" class="form-label">Reason (recorded in the audit trail)</label>
                                <input type="text" id="module-access-reason" name="reason" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">Save module access</button>
                            </div>
                        </div>
                    </form>
                @endunless
            </x-card>
        </div>
    </div>
</section>
