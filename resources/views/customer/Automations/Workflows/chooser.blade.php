@extends('layouts/contentLayoutMaster')

{{--
    Automations V2 (contract §13.3, V2-D) — "New workflow": start from
    scratch, or from a recipe. A recipe is only a starting document (JS,
    resources/js/automations/workflow-builder/recipes.js) built from the
    SAME schema §5.3 shapes as everything else — never a second engine.

    Creating posts the §20.2 body — a name and a trigger type — and a recipe's
    document is then saved into the new draft through the ordinary autosave.

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
    @php
        $recipeLabels = [];
        foreach (['welcome_new_contact', 'notify_team_new_contact', 'check_in_after_days', 'date_reminder'] as $recipeKey) {
            $recipeLabels[$recipeKey] = [
                'title' => __('automations.v2.recipes.' . $recipeKey),
                'description' => __('automations.v2.recipes.' . $recipeKey . '_description'),
            ];
        }
    @endphp

    <div id="wf-chooser" class="wf-chooser" data-role="wf-chooser" data-create-url="{{ $basePath }}">
        <script type="application/json" id="wf-recipe-labels">{!! json_encode($recipeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

        <div class="wf-chooser__name">
            <label class="wf-field__label" for="wf-chooser-name">{{ __('automations.v2.chooser.name_label') }}</label>
            <input type="text" class="form-control" id="wf-chooser-name" data-role="wf-chooser-name" maxlength="255" placeholder="{{ __('automations.v2.chooser.name_placeholder') }}">
            <p class="wf-help mb-0">{{ __('automations.v2.chooser.name_help') }}</p>
        </div>

        <p class="alert alert-danger" data-role="wf-chooser-error" role="alert" hidden></p>

        <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-3">
                <button type="button" class="wf-chooser-card wf-chooser-card--scratch" data-role="wf-chooser-scratch">
                    <span class="wf-icon wf-chooser-card__icon"><x-ds-icon name="plus" size="20" /></span>
                    <span class="wf-chooser-card__title">{{ __('automations.v2.chooser.from_scratch_title') }}</span>
                    <span class="wf-chooser-card__description">{{ __('automations.v2.chooser.from_scratch_description') }}</span>
                </button>
            </div>
        </div>

        <h5 class="text-section-heading mt-4 mb-3">{{ __('automations.v2.chooser.recipe_heading') }}</h5>
        <div class="row g-3" data-role="wf-chooser-recipes">
            {{-- Populated by JS from recipes.js — the recipe list is a JS
                 concern, each entry rendered here as a matching card so the two
                 never drift apart. --}}
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
