@php
    // The builder header carries the page's name; the layout's own title row
    // would only repeat it above.
    $pageConfigs = ['pageHeader' => false];
@endphp

@extends('layouts/contentLayoutMaster')

{{--
    Automations V2 (contract §5, §13, §14.4, V2-D) — the builder shell.

    This view is pure presentation: it receives an already-validated,
    already-tenancy-checked document and catalogs, and hands them to the JS
    module as one JSON blob. It performs no query of its own — the controller
    owns the Builder-load budget (§18); this template cannot violate it because
    it runs no query at all.

    Layout: a header (back, name, status, save state, undo/redo, Test, Pause /
    Resume, Publish), the canvas, and one panel docked to its right — the step
    inspector or the Test workflow panel — so the workflow stays in view while a
    step is configured.

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
      iterable $crmPipelines            optional; CRM sales pipelines of this Business,
                                          each: ->id, ->name, ->archived
      iterable $crmStages               optional; their stages,
                                          each: ->id, ->pipeline_id, ->name, ->archived
--}}

@php
    $statusValue = is_object($workflow->status ?? null) ? $workflow->status->value : ($workflow->status ?? 'draft');
    $isArchived = $statusValue === 'archived';

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
            'crmPipelines' => $toArrayList($crmPipelines ?? [], ['id', 'name', 'archived']),
            'crmStages' => $toArrayList($crmStages ?? [], ['id', 'pipeline_id', 'name', 'archived']),
        ],
        'limits' => [
            'maxNodes' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_NODES_PER_VERSION,
            'maxBranchDepth' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_BRANCH_DEPTH,
            'maxConditionsPerBranch' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_CONDITIONS_PER_BRANCH,
        ],
        'dateOffsets' => \App\Library\Automation\Workflow\NodeTypeRegistry::DATE_OFFSET_ALLOWLIST,
        'contactSources' => \App\Library\Automation\Workflow\NodeTypeRegistry::CONTACT_SOURCES,
    ];

    // Every icon the canvas, picker and panels draw, rendered once through the
    // design system's icon seam and cloned by workflow-builder/dom.js.
    $builderIcons = [
        'zap', 'message-square-text', 'bell', 'user-pen', 'clock', 'split', 'circle-stop',
        'plus', 'flag', 'triangle-alert', 'ellipsis-vertical', 'chevron-up', 'chevron-down',
        'trash-2', 'search', 'user', 'user-plus', 'message-square-reply', 'calendar-clock', 'hand', 'x',
        'briefcase-business', 'arrow-right-left', 'trophy', 'circle-x',
    ];

    $statusLabel = __('automations.v2.list.status_' . $statusValue);
@endphp

@section('title', $workflow->name)

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/automations-workflow-builder.css')) }}">
@endsection

@section('content')
    <div class="wf-builder" id="wf-builder" data-role="wf-builder" data-narrow-readonly="true">
        <script type="application/json" id="wf-builder-data">{!! json_encode($builderData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

        @foreach ($builderIcons as $iconName)
            <template id="wf-icon-{{ $iconName }}"><x-ds-icon :name="$iconName" size="18" /></template>
        @endforeach

        <div class="wf-narrow-notice alert alert-warning" role="status" data-role="wf-narrow-notice">
            {{ __('automations.v2.builder.narrow_viewport_notice') }}
        </div>

        <header class="wf-header">
            <div class="wf-header__start">
                <a class="wf-header__back" href="{{ $basePath }}" data-role="wf-back" aria-label="{{ __('automations.v2.builder.back_to_workflows') }}" title="{{ __('automations.v2.builder.back_to_workflows') }}">
                    <x-ds-icon name="arrow-left" size="18" />
                </a>
                <div class="wf-header__identity">
                    <p class="wf-header__eyebrow">{{ __('automations.v2.list.heading') }}</p>
                    <div class="wf-header__title-row">
                        <h1 class="wf-header__name" data-role="wf-name">{{ $workflow->name }}</h1>
                        <span class="wf-status" data-role="wf-status" data-status="{{ $statusValue }}">{{ $statusLabel }}</span>
                    </div>
                </div>
            </div>

            <div class="wf-header__actions">
                <span class="wf-save-state" data-role="wf-save-state" data-state="saved" aria-live="polite">
                    <span class="wf-save-state__dot" aria-hidden="true"></span>
                    <span data-role="wf-save-state-label">{{ __('automations.v2.builder.saved') }}</span>
                </span>
                <div class="wf-header__history" role="group" aria-label="{{ __('automations.v2.builder.history') }}">
                    <button type="button" class="wf-icon-button" data-role="wf-undo" aria-label="{{ __('automations.v2.builder.undo') }}" title="{{ __('automations.v2.builder.undo') }}" disabled>
                        <x-ds-icon name="undo-2" size="18" />
                    </button>
                    <button type="button" class="wf-icon-button" data-role="wf-redo" aria-label="{{ __('automations.v2.builder.redo') }}" title="{{ __('automations.v2.builder.redo') }}" disabled>
                        <x-ds-icon name="redo-2" size="18" />
                    </button>
                </div>
                <x-button variant="secondary" size="sm" icon="flask-conical" data-role="wf-test-workflow">
                    {{ __('automations.v2.builder.test_workflow') }}
                </x-button>
                <x-button variant="secondary" size="sm" icon="circle-pause" data-role="wf-pause" :hidden="$statusValue !== 'published'">
                    {{ __('automations.v2.builder.pause') }}
                </x-button>
                <x-button variant="secondary" size="sm" icon="circle-play" data-role="wf-resume" :hidden="$statusValue !== 'paused'">
                    {{ __('automations.v2.builder.resume') }}
                </x-button>
                <x-button variant="primary" size="sm" icon="rocket" data-role="wf-publish" :hidden="$isArchived">
                    <span data-role="wf-publish-label">{{ $statusValue === 'draft' ? __('automations.v2.builder.publish') : __('automations.v2.builder.publish_changes') }}</span>
                </x-button>
            </div>
        </header>

        <p class="wf-header-notice" data-role="wf-header-notice" role="status" aria-live="polite" hidden></p>

        <x-tabs :tabs="[
            'builder' => __('automations.v2.builder.tab_builder'),
            'settings' => __('automations.v2.builder.tab_settings'),
            'enrollments' => __('automations.v2.builder.tab_enrollment_history'),
            'logs' => __('automations.v2.builder.tab_execution_logs'),
        ]" active="builder" id="wf-tabs" class="wf-tabs">
            <div class="tab-pane fade show active" id="wf-tabs-builder" role="tabpanel">
                <div class="alert alert-danger d-none" data-role="wf-document-errors" role="alert"></div>

                <div class="wf-workspace" data-role="wf-workspace">
                    <div class="wf-canvas-viewport" data-role="wf-canvas-viewport">
                        <div class="wf-zoom-controls" role="group" aria-label="{{ __('automations.v2.builder.zoom') }}">
                            <button type="button" class="wf-icon-button" data-role="wf-zoom-out" aria-label="{{ __('automations.v2.builder.zoom_out') }}" title="{{ __('automations.v2.builder.zoom_out') }}"><x-ds-icon name="zoom-out" size="16" /></button>
                            <button type="button" class="wf-icon-button" data-role="wf-zoom-reset" aria-label="{{ __('automations.v2.builder.zoom_reset') }}" title="{{ __('automations.v2.builder.zoom_reset') }}"><x-ds-icon name="refresh-cw" size="16" /></button>
                            <button type="button" class="wf-icon-button" data-role="wf-zoom-fit" aria-label="{{ __('automations.v2.builder.zoom_fit') }}" title="{{ __('automations.v2.builder.zoom_fit') }}"><x-ds-icon name="maximize" size="16" /></button>
                            <button type="button" class="wf-icon-button" data-role="wf-zoom-in" aria-label="{{ __('automations.v2.builder.zoom_in') }}" title="{{ __('automations.v2.builder.zoom_in') }}"><x-ds-icon name="zoom-in" size="16" /></button>
                        </div>
                        <div class="wf-canvas-surface" data-role="wf-canvas-surface">
                            <ol class="wf-flow wf-flow--main" data-role="wf-canvas-root" aria-label="{{ __('automations.v2.builder.canvas_label') }}"></ol>
                        </div>
                    </div>

                    {{-- The step inspector (contract §13.1 "Configure a step"): docked
                         beside the canvas rather than over it, its body filled per
                         node type from the templates below. --}}
                    <aside class="wf-panel" data-role="wf-drawer" aria-labelledby="wf-drawer-label" hidden>
                        <div class="wf-panel__header">
                            <span class="wf-panel__icon" data-role="wf-drawer-icon"></span>
                            <div class="wf-panel__heading">
                                <p class="wf-panel__eyebrow" data-role="wf-drawer-eyebrow"></p>
                                <h2 class="wf-panel__title" id="wf-drawer-label" data-role="wf-drawer-title">{{ __('automations.v2.builder.configure') }}</h2>
                            </div>
                            <button type="button" class="wf-icon-button" data-role="wf-drawer-close" aria-label="{{ __('automations.v2.builder.close') }}" title="{{ __('automations.v2.builder.close') }}">
                                <x-ds-icon name="x" size="18" />
                            </button>
                        </div>
                        <p class="wf-panel__description" data-role="wf-drawer-description"></p>
                        <div class="wf-panel__body">
                            <div class="alert alert-danger d-none" data-role="wf-drawer-errors" role="alert"></div>
                            <form data-role="wf-drawer-form" novalidate></form>
                        </div>
                        <div class="wf-panel__footer">
                            <x-button variant="ghost" size="sm" icon="trash-2" data-role="wf-drawer-delete" class="text-danger">{{ __('automations.v2.builder.delete_step') }}</x-button>
                            <div class="d-flex gap-2 ms-auto">
                                <x-button variant="secondary" size="sm" data-role="wf-drawer-cancel">{{ __('automations.v2.builder.cancel') }}</x-button>
                                <x-button variant="primary" size="sm" data-role="wf-drawer-save">{{ __('automations.v2.builder.save_step') }}</x-button>
                            </div>
                        </div>
                    </aside>

                    {{-- Test workflow (contract §13.1, §16): pick a contact, see the path. --}}
                    <aside class="wf-panel wf-test" data-role="wf-test-panel" aria-labelledby="wf-test-label" hidden>
                        <div class="wf-panel__header">
                            <span class="wf-panel__icon"><span class="wf-icon wf-tone wf-tone--test"><x-ds-icon name="flask-conical" size="18" /></span></span>
                            <div class="wf-panel__heading">
                                <p class="wf-panel__eyebrow">{{ __('automations.v2.test.eyebrow') }}</p>
                                <h2 class="wf-panel__title" id="wf-test-label">{{ __('automations.v2.test.title') }}</h2>
                            </div>
                            <button type="button" class="wf-icon-button" data-role="wf-test-close" aria-label="{{ __('automations.v2.builder.close') }}" title="{{ __('automations.v2.builder.close') }}">
                                <x-ds-icon name="x" size="18" />
                            </button>
                        </div>
                        <p class="wf-panel__description">{{ __('automations.v2.test.description') }}</p>
                        <div class="wf-panel__body">
                            <div data-role="wf-test-pick">
                                <label class="form-label" for="wf-test-search">{{ __('automations.v2.test.choose_contact') }}</label>
                                <input type="search" class="form-control" id="wf-test-search" data-role="wf-test-search" placeholder="{{ __('automations.v2.test.search_placeholder') }}" autocomplete="off">
                                <div class="wf-test__contacts" data-role="wf-test-contacts"></div>
                            </div>
                            <div class="wf-test__result" data-role="wf-test-result" hidden></div>
                        </div>
                        <div class="wf-panel__footer">
                            <x-button variant="secondary" size="sm" data-role="wf-test-clear" hidden>{{ __('automations.v2.test.try_another') }}</x-button>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="tab-pane fade" id="wf-tabs-settings" role="tabpanel">
                <x-card>
                    <dl class="row mb-0">
                        <dt class="col-4 col-md-3 text-label">{{ __('locale.labels.name') }}</dt>
                        <dd class="col-8 col-md-9" data-role="wf-settings-name">{{ $workflow->name }}</dd>
                        <dt class="col-4 col-md-3 text-label">{{ __('locale.labels.status') }}</dt>
                        <dd class="col-8 col-md-9" data-role="wf-settings-status">{{ $statusLabel }}</dd>
                    </dl>
                </x-card>
            </div>

            <div class="tab-pane fade" id="wf-tabs-enrollments" role="tabpanel">
                <x-empty-state icon="users" :title="__('automations.v2.builder.tab_enrollment_history')" :description="__('automations.v2.builder.enrollment_history_empty')" />
            </div>

            <div class="tab-pane fade" id="wf-tabs-logs" role="tabpanel">
                <x-empty-state icon="list" :title="__('automations.v2.builder.tab_execution_logs')" :description="__('automations.v2.builder.execution_logs_empty')" />
            </div>
        </x-tabs>

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
