{{-- Settings tab (design: FormSettings.dc.html). Every control keeps the builder's
     existing data hooks (data-bind / data-design-seg / #fb-swatches / #fb-s-bg), so
     edits flow through the same autosave + version-pinning path as the Edit tab.
     "Accepting responses" is the real lifecycle (activate / switch off), posted to
     the existing endpoints after any pending autosave is flushed. --}}
@php
    $accepting = $form->isActive();
    $stateRoute = $accepting ? 'deactivate' : 'activate';
    $stateCopy = match ($form->lifecycle_state->value) {
        'draft' => 'Off while this form is a draft. Turn on to make its links live.',
        'inactive' => 'Switched off — its links accept no responses.',
        default => 'Turn off to pause the form everywhere.',
    };
@endphp
<div class="mgf-cols">
    <div class="mgf-colmain">
        <section class="mgf-card" aria-labelledby="mgf-s-basics">
            <h2 id="mgf-s-basics">Basics</h2>
            <div class="mgf-fl">
                <label for="fb-s-name">Form name</label>
                <input type="text" id="fb-s-name" class="mgf-in" maxlength="120" data-bind="name">
            </div>
            <div class="mgf-fl">
                <label for="fb-s-intro">Intro <span>(optional, shown above the form)</span></label>
                <textarea id="fb-s-intro" class="mgf-in" rows="3" maxlength="1000" data-bind="intro"></textarea>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.businesses.forms.'.$stateRoute, $formScope) }}" class="mgf-toggle fb-nav-form" data-role="forms-accepting">
                @csrf
                <div>
                    <b id="mgf-accept-l">Accepting responses</b>
                    <small id="mgf-accept-d">{{ $stateCopy }}</small>
                </div>
                <button type="submit" class="mgf-sw" role="switch" aria-checked="{{ $accepting ? 'true' : 'false' }}" aria-labelledby="mgf-accept-l" aria-describedby="mgf-accept-d"
                        data-role="{{ $accepting ? 'forms-deactivate' : 'forms-activate' }}"></button>
            </form>
        </section>

        <section class="mgf-card" aria-labelledby="mgf-s-after">
            <h2 id="mgf-s-after">After they submit</h2>
            <div class="mgf-fl">
                <label for="fb-s-success">Thank-you message</label>
                <textarea id="fb-s-success" class="mgf-in" rows="3" maxlength="300" data-bind="success_message"></textarea>
                <span class="mgf-hint">Shown on the thank-you page.</span>
            </div>
        </section>

        <section class="mgf-card" data-role="forms-style" aria-labelledby="mgf-s-look">
            <h2 id="mgf-s-look">Look</h2>
            <div class="mgf-fl">
                <label for="fb-s-button">Button text</label>
                <input type="text" id="fb-s-button" class="mgf-in" maxlength="40" data-bind="submit_label">
            </div>
            <div class="mgf-fl">
                <span class="mgf-lbl" id="mgf-accent-l">Accent color</span>
                <div class="mgf-swatches" id="fb-swatches" role="group" aria-labelledby="mgf-accent-l"></div>
            </div>
            <div class="mgf-segs">
                <div class="mgf-fl"><span class="mgf-lbl">Button position</span>
                    <div class="mgf-seg" role="group" aria-label="Button position" data-design-seg="button_align">
                        <button type="button" data-v="left">Left</button><button type="button" data-v="center">Center</button><button type="button" data-v="right">Right</button><button type="button" data-v="full">Full</button>
                    </div></div>
                <div class="mgf-fl"><span class="mgf-lbl">Corners</span>
                    <div class="mgf-seg" role="group" aria-label="Corners" data-design-seg="radius">
                        <button type="button" data-v="none">Square</button><button type="button" data-v="sm">Slight</button><button type="button" data-v="md">Rounded</button><button type="button" data-v="pill">Pill</button>
                    </div></div>
                <div class="mgf-fl"><span class="mgf-lbl">Width</span>
                    <div class="mgf-seg" role="group" aria-label="Width" data-design-seg="width">
                        <button type="button" data-v="narrow">Narrow</button><button type="button" data-v="medium">Medium</button><button type="button" data-v="wide">Wide</button>
                    </div></div>
                <div class="mgf-fl"><span class="mgf-lbl">Background</span>
                    <div class="mgf-bgrow">
                        <label class="mgf-bgchip" id="fb-s-bg-chip" style="background:#f6f4f1"><input type="color" id="fb-s-bg" value="#f6f4f1" aria-label="Page background color"></label>
                        <button type="button" class="mgf-link" id="fb-s-bg-reset">Reset</button>
                    </div></div>
            </div>
        </section>

        <section class="mgf-card" aria-labelledby="mgf-s-opp">
            <h2 id="mgf-s-opp">Opportunities</h2>
            <div class="mgf-toggle is-bare">
                <div>
                    <b id="mgf-opp-l">Create an opportunity for each response</b>
                    <small id="mgf-opp-d">Needs a phone question so we can match the person.</small>
                </div>
                <button type="button" class="mgf-sw" id="fb-s-opp" role="switch" aria-checked="false" aria-labelledby="mgf-opp-l" aria-describedby="mgf-opp-d"></button>
            </div>
            <div class="mgf-fl" id="fb-s-pipeline-wrap">
                <label for="fb-s-pipeline">Add to pipeline</label>
                <select id="fb-s-pipeline" class="mgf-in" data-bind="opportunity_pipeline_id"></select>
            </div>
        </section>

        <section class="mgf-card" aria-labelledby="mgf-s-where">
            <h2 id="mgf-s-where">Where it's offered</h2>
            <span style="font-size:13.5px;color:var(--mgf-mute2);margin-top:-8px">Each location gets its own link, so you know where every response came from.</span>
            @include('customer.business.forms._deployments', ['formScope' => $formScope])
        </section>
    </div>

    <aside class="mgf-colside" aria-label="Live preview">
        <span class="mgf-eyebrow">Live preview</span>
        <div class="mgf-previewbox">
            <iframe id="fb-settings-preview" title="Live preview of this form" sandbox="" data-role="forms-settings-preview"></iframe>
        </div>
        <span class="mgf-savenote" id="fb-settings-note" data-state="saved" role="status" aria-live="polite">@include('customer.business.forms._icon', ['n' => 'check']) <span>Changes save automatically</span></span>
    </aside>
</div>
