@extends('layouts/contentLayoutMaster')

{{--
    Automations V2 (contract §13.3, V2-D) — "New workflow": start from
    scratch, or from a recipe. A recipe is only a starting document (JS,
    resources/js/automations/workflow-builder/recipes.js) built from the
    SAME schema §5.3 shapes as everything else — never a second engine.

    Props:
      string $workspaceUid
      string $businessUid
      string $basePath   the workflow collection endpoint, POST creates
--}}

@section('title', __('automations.v2.chooser.heading'))

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/automations-workflow-builder.css')) }}">
@endsection

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">{{ __('automations.v2.chooser.heading') }}</h4>
        </div>
    </div>

    @php
        $recipeLabels = [];
        foreach (['welcome_new_contact', 'notify_team_new_contact', 'check_in_after_days', 'date_reminder'] as $recipeKey) {
            $recipeLabels[$recipeKey] = [
                'title' => __('automations.v2.recipes.' . $recipeKey),
                'description' => __('automations.v2.recipes.' . $recipeKey . '_description'),
            ];
        }
    @endphp

    <div id="wf-chooser" data-role="wf-chooser" data-create-url="{{ $basePath }}">
        <script type="application/json" id="wf-recipe-labels">{!! json_encode($recipeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <x-card :padded="true" data-role="wf-chooser-scratch" class="h-100" style="cursor:pointer">
                    <h5 class="text-section-heading">{{ __('automations.v2.chooser.from_scratch_title') }}</h5>
                    <p class="text-caption mb-0">{{ __('automations.v2.chooser.from_scratch_description') }}</p>
                </x-card>
            </div>
        </div>

        <h5 class="text-section-heading mt-4 mb-3">{{ __('automations.v2.chooser.recipe_heading') }}</h5>
        <div class="row g-3" data-role="wf-chooser-recipes">
            {{-- Populated by JS from recipes.js — the recipe list is a JS
                 concern (see the contract's V2-D allowlist: no PHP outside
                 the lang file belongs to this slice), each entry rendered
                 here as a matching card so the two never drift apart. --}}
        </div>
    </div>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/automations/workflow-builder.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.AutomationsWorkflowBuilder && window.AutomationsWorkflowBuilder.initChooser) {
                window.AutomationsWorkflowBuilder.initChooser(document.getElementById('wf-chooser'));
            }
        });
    </script>
@endsection
