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
        foreach (['welcome_new_contact', 'notify_team_new_contact', 'check_in_after_days', 'date_reminder', 'new_lead_follow_up', 'booking_follow_up', 'proposal_follow_up', 'signed_to_payment', 'payment_complete'] as $recipeKey) {
            $recipeLabels[$recipeKey] = [
                'title' => __('automations.v2.recipes.' . $recipeKey),
                'description' => __('automations.v2.recipes.' . $recipeKey . '_description'),
            ];
        }

        $triggerTitles = [];
        foreach ((array) __('automations.v2.triggers') as $triggerKey => $triggerCopy) {
            if (is_array($triggerCopy) && isset($triggerCopy['title'])) {
                $triggerTitles[$triggerKey] = $triggerCopy['title'];
            }
        }

        // Strings the script needs; counts are pluralised client-side from the
        // two {1}/[2,*] forms so no number is ever fixed in the page.
        $chooserText = [];
        foreach (['ready_heading', 'setup_heading', 'banner_connect', 'banner_plan', 'noun_sms', 'noun_email', 'noun_payments', 'banner_sms', 'banner_email', 'banner_payments', 'chip_ready', 'chip_sms', 'chip_email', 'chip_payments', 'chip_plan', 'trigger_prefix', 'use_template', 'action_sms', 'action_email', 'action_payments', 'none_ready', 'creating'] as $textKey) {
            $chooserText[$textKey] = __('automations.v2.chooser.' . $textKey);
        }
        $chooserText['template_count_one'] = trans_choice('automations.v2.chooser.template_count', 1, ['count' => 1]);
        $chooserText['template_count_other'] = trans_choice('automations.v2.chooser.template_count', 2, ['count' => ':count']);
        $chooserText['banner_title_one'] = trans_choice('automations.v2.chooser.banner_title', 1, ['count' => 1]);
        $chooserText['banner_title_other'] = trans_choice('automations.v2.chooser.banner_title', 2, ['count' => ':count']);

        $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    @endphp

    <div id="wf-chooser" class="wf-chooser" data-role="wf-chooser" data-create-url="{{ $basePath }}">
        <script type="application/json" id="wf-recipe-labels">{!! json_encode($recipeLabels, $jsonFlags) !!}</script>
        {{-- What the account can use, so a recipe it cannot run is shown disabled with the reason. --}}
        <script type="application/json" id="wf-capabilities" data-unavailable-prefix="{{ __('automations.v2.recipes.unavailable_prefix') }}">{!! json_encode($capabilities ?? [], $jsonFlags) !!}</script>
        <script type="application/json" id="wf-chooser-config">{!! json_encode(['text' => $chooserText, 'triggers' => (object) $triggerTitles, 'setupLinks' => (object) ($setupLinks ?? [])], $jsonFlags) !!}</script>

        <a class="wf-chooser-back" data-role="wf-chooser-back" href="{{ route('customer.workspaces.businesses.automations.workflows.index', [$workspaceUid, $businessUid]) }}">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" /> {{ __('automations.v2.chooser.back') }}
        </a>
        <h1 class="wf-chooser-title">{{ __('automations.v2.chooser.heading') }}</h1>
        <p class="wf-chooser-subtitle">{{ __('automations.v2.chooser.subtitle') }}</p>

        <div class="wf-chooser-create">
            <div class="wf-chooser__name">
                <label class="wf-field__label" for="wf-chooser-name">{{ __('automations.v2.chooser.name_label') }} <span class="wf-chooser-optional">{{ __('automations.v2.chooser.optional') }}</span></label>
                <input type="text" class="form-control" id="wf-chooser-name" data-role="wf-chooser-name" maxlength="255" placeholder="{{ __('automations.v2.chooser.name_placeholder') }}">
                <p class="wf-help mb-0">{{ __('automations.v2.chooser.name_help') }}</p>
            </div>
            <button type="button" class="btn btn-primary wf-chooser-scratch" data-role="wf-chooser-scratch">
                <x-ds-icon name="plus" size="18" aria-hidden="true" />
                <span class="wf-chooser-scratch__text">
                    <span class="wf-chooser-scratch__title">{{ __('automations.v2.chooser.from_scratch_title') }}</span>
                    <span class="wf-chooser-scratch__desc">{{ __('automations.v2.chooser.from_scratch_description') }}</span>
                </span>
                <x-ds-icon class="wf-chooser-scratch__arrow" name="arrow-right" size="16" aria-hidden="true" />
            </button>
        </div>

        <p class="alert alert-danger" data-role="wf-chooser-error" role="alert" hidden></p>

        <h2 class="wf-chooser-section-title">{{ __('automations.v2.chooser.recipe_heading') }}</h2>

        <div class="wf-chooser-banner" data-role="wf-chooser-banner" hidden></div>

        <section class="wf-chooser-group" data-role="wf-chooser-ready-group" hidden>
            <h3 class="wf-chooser-group__title"><span data-role="wf-chooser-ready-title"></span> <span class="wf-chooser-group__count" data-role="wf-chooser-ready-count"></span></h3>
            <div class="wf-chooser-grid" data-role="wf-chooser-recipes"></div>
        </section>

        <section class="wf-chooser-group" data-role="wf-chooser-setup-group" hidden>
            <h3 class="wf-chooser-group__title"><span data-role="wf-chooser-setup-title"></span> <span class="wf-chooser-group__count" data-role="wf-chooser-setup-count"></span></h3>
            <div class="wf-chooser-grid" data-role="wf-chooser-setup-recipes"></div>
        </section>

        {{-- Icons the script clones; rendered by the design system so they match the shell. --}}
        <template id="wf-chooser-icons">
            @foreach (['check-circle', 'alert-circle', 'message-circle', 'mail', 'credit-card', 'lock', 'zap', 'arrow-right'] as $iconName)
                <span data-icon="{{ $iconName }}"><x-ds-icon :name="$iconName" size="16" aria-hidden="true" /></span>
            @endforeach
        </template>
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
