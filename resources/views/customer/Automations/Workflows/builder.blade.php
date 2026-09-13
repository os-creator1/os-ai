@extends('layouts/contentLayoutMaster')

{{--
    Automations V2 (contract §5, §13, §14.4, V2-D) — the builder shell.

    This view is pure presentation: it receives an already-validated,
    already-tenancy-checked document and catalogs, and hands them to the JS
    module as one JSON blob. It performs no query of its own — whatever
    controller V2-E builds is responsible for the ≤10-query builder-load
    budget (§18); this template cannot violate it because it runs no query
    at all.

    Props:
      string $workspaceUid
      string $businessUid
      string $basePath                  the workflow collection endpoint
      object $workflow                  ->uid, ->name, ->status (string|BackedEnum)
      array  $draft                     ['definition' => array, 'revision' => int,
                                          'errors' => array<string, list<string>>]
      iterable $contactGroups           each: ->id, ->name
      iterable $dateFields               each: ->id, ->label, ->contact_group_id
      iterable $writableFields          each: ->id, ->label, ->contact_group_id, ->type
                                          (the caller has already excluded any
                                          is_phone field — this view never
                                          receives one to render)
--}}

@php
    $statusValue = is_object($workflow->status ?? null) ? $workflow->status->value : ($workflow->status ?? 'draft');

    $toArrayList = function (iterable $items, array $keys): array {
        $out = [];
        foreach ($items as $item) {
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
            }
            $out[] = $row;
        }

        return $out;
    };

    $builderData = [
        'workflow' => ['uid' => $workflow->uid, 'name' => $workflow->name, 'status' => $statusValue],
        'draft' => [
            'definition' => $draft['definition'],
            'revision' => (int) $draft['revision'],
            'errors' => $draft['errors'] ?? [],
        ],
        'basePath' => rtrim($basePath, '/') . '/' . $workflow->uid,
        'catalogs' => [
            'contactGroups' => $toArrayList($contactGroups ?? [], ['id', 'name']),
            'dateFields' => $toArrayList($dateFields ?? [], ['id', 'label', 'contact_group_id']),
            'writableFields' => $toArrayList($writableFields ?? [], ['id', 'label', 'contact_group_id', 'type']),
        ],
        'limits' => [
            'maxNodes' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_NODES_PER_VERSION,
            'maxBranchDepth' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_BRANCH_DEPTH,
            'maxConditionsPerBranch' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_CONDITIONS_PER_BRANCH,
        ],
        'dateOffsets' => \App\Library\Automation\Workflow\NodeTypeRegistry::DATE_OFFSET_ALLOWLIST,
        'contactSources' => \App\Library\Automation\Workflow\NodeTypeRegistry::CONTACT_SOURCES,
    ];
@endphp

@section('title', $workflow->name)

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/automations-workflow-builder.css')) }}">
@endsection

@section('content')
    <div class="wf-builder" id="wf-builder" data-role="wf-builder" data-narrow-readonly="true">
        <script type="application/json" id="wf-builder-data">{!! json_encode($builderData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

        <div class="wf-narrow-notice alert alert-warning" role="status" data-role="wf-narrow-notice">
            {{ __('automations.v2.builder.narrow_viewport_notice') }}
        </div>

        <div class="wf-builder-topbar">
            <div class="d-flex align-items-center gap-2">
                <x-button variant="ghost" size="sm" icon="arrow-left" href="{{ $basePath }}" data-role="wf-back">
                    {{ __('automations.v2.builder.back') }}
                </x-button>
                <h5 class="mb-0" data-role="wf-name">{{ $workflow->name }}</h5>
                <span class="wf-save-state" data-role="wf-save-state" data-state="saved" aria-live="polite">{{ __('automations.v2.builder.saved') }}</span>
            </div>
            <div class="wf-builder-topbar-actions">
                <x-button variant="ghost" size="sm" icon="corner-up-left" data-role="wf-undo" disabled>
                    {{ __('automations.v2.builder.undo') }}
                </x-button>
                <x-button variant="ghost" size="sm" icon="corner-up-right" data-role="wf-redo" disabled>
                    {{ __('automations.v2.builder.redo') }}
                </x-button>
                <x-button variant="outline" size="sm" icon="play" data-role="wf-test-workflow">
                    {{ __('automations.v2.builder.test_workflow') }}
                </x-button>
                <x-button variant="primary" size="sm" icon="upload" data-role="wf-publish">
                    {{ __('automations.v2.builder.publish') }}
                </x-button>
            </div>
        </div>

        <x-tabs :tabs="[
            'builder' => __('automations.v2.builder.tab_builder'),
            'settings' => __('automations.v2.builder.tab_settings'),
            'enrollments' => __('automations.v2.builder.tab_enrollment_history'),
            'logs' => __('automations.v2.builder.tab_execution_logs'),
        ]" active="builder" id="wf-tabs">
            <div class="tab-pane fade show active" id="wf-tabs-builder" role="tabpanel">
                <div class="alert alert-danger d-none" data-role="wf-document-errors" role="alert"></div>

                <div class="wf-canvas-viewport" data-role="wf-canvas-viewport">
                    <div class="wf-zoom-controls">
                        <x-button variant="secondary" size="sm" icon="zoom-out" data-role="wf-zoom-out" aria-label="{{ __('automations.v2.builder.zoom_out') }}" />
                        <x-button variant="secondary" size="sm" icon="maximize" data-role="wf-zoom-fit" aria-label="{{ __('automations.v2.builder.zoom_fit') }}" />
                        <x-button variant="secondary" size="sm" icon="zoom-in" data-role="wf-zoom-in" aria-label="{{ __('automations.v2.builder.zoom_in') }}" />
                        <x-button variant="secondary" size="sm" icon="refresh-cw" data-role="wf-zoom-reset" aria-label="{{ __('automations.v2.builder.zoom_reset') }}" />
                    </div>
                    <div class="wf-canvas-surface" data-role="wf-canvas-surface">
                        <ol class="wf-steps" data-role="wf-canvas-root"></ol>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="wf-tabs-settings" role="tabpanel">
                <x-card>
                    <dl class="row mb-0">
                        <dt class="col-4 col-md-3 text-label">{{ __('locale.labels.name') }}</dt>
                        <dd class="col-8 col-md-9" data-role="wf-settings-name">{{ $workflow->name }}</dd>
                        <dt class="col-4 col-md-3 text-label">{{ __('locale.labels.status') }}</dt>
                        <dd class="col-8 col-md-9" data-role="wf-settings-status">{{ ucfirst($statusValue) }}</dd>
                    </dl>
                </x-card>
            </div>

            <div class="tab-pane fade" id="wf-tabs-enrollments" role="tabpanel">
                <x-empty-state icon="users" title="Enrollment history" description="Available once this workflow's history endpoint (V2-E) is wired up." />
            </div>

            <div class="tab-pane fade" id="wf-tabs-logs" role="tabpanel">
                <x-empty-state icon="list" title="Execution logs" description="Available once this workflow's logs endpoint (V2-E) is wired up." />
            </div>
        </x-tabs>

        {{-- Bootstrap 5 offcanvas drawer (contract §13.1) — one shared shell,
             its body populated per node type from the hidden templates
             below. --}}
        <div class="offcanvas offcanvas-end" tabindex="-1" id="wf-drawer" data-role="wf-drawer" aria-labelledby="wf-drawer-label" style="width: 26rem;">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="wf-drawer-label" data-role="wf-drawer-title">{{ __('automations.v2.builder.configure') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{ __('automations.v2.builder.close') }}"></button>
            </div>
            <div class="offcanvas-body">
                <div class="alert alert-danger d-none" data-role="wf-drawer-errors" role="alert"></div>
                <form data-role="wf-drawer-form"></form>
            </div>
            <div class="offcanvas-footer p-3 border-top d-flex justify-content-between gap-2">
                <x-button variant="ghost" size="sm" data-role="wf-drawer-delete">{{ __('automations.v2.builder.delete_step') }}</x-button>
                <div class="d-flex gap-2">
                    <x-button variant="secondary" size="sm" data-bs-dismiss="offcanvas">{{ __('automations.v2.builder.cancel') }}</x-button>
                    <x-button variant="primary" size="sm" data-role="wf-drawer-save">{{ __('automations.v2.builder.save_step') }}</x-button>
                </div>
            </div>
        </div>

        @include('customer.Automations.Workflows.partials.node-form-trigger')
        @include('customer.Automations.Workflows.partials.node-form-send-sms')
        @include('customer.Automations.Workflows.partials.node-form-update-contact-field')
        @include('customer.Automations.Workflows.partials.node-form-internal-notification')
        @include('customer.Automations.Workflows.partials.node-form-wait')
        @include('customer.Automations.Workflows.partials.node-form-if-else')
        @include('customer.Automations.Workflows.partials.node-form-end')
    </div>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/automations/workflow-builder.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.AutomationsWorkflowBuilder && window.AutomationsWorkflowBuilder.initBuilder) {
                window.AutomationsWorkflowBuilder.initBuilder(document.getElementById('wf-builder'));
            }
        });
    </script>
@endsection
