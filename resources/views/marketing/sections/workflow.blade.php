@php
    // Public Marketing Homepage contract, correction: a website form
    // submission is saved to Forms and creates a Contact (plus an
    // Opportunity when a CRM pipeline exists) — it does not appear in
    // Conversations, which is the separate SMS/chat inbox. See
    // resources/views/marketing/sections/photo-booth-example.blade.php
    // for the same correction and docs/automation/PUBLIC-MARKETING-HOMEPAGE.md.
    $steps = [
        ['label' => __('Publish'), 'body' => __('Your business website goes live with your services, packages, and a way to reach you.')],
        ['label' => __('Capture'), 'body' => __('A visitor submits an inquiry from your site — it is saved to Forms and a Contact appears in your CRM.')],
        ['label' => __('Follow up'), 'body' => __('Reach out to the new contact and reply to their messages from Conversations.')],
        ['label' => __('Book'), 'body' => __('Confirm the job on your Calendar.')],
        ['label' => __('Propose & pay'), 'body' => __('Send a proposal, and get paid once it is accepted.')],
    ];
@endphp
<div class="marketing-workflow">
    @foreach ($steps as $index => $step)
        <div class="marketing-workflow__step">
            <span class="marketing-workflow__number">{{ $index + 1 }}</span>
            <h3>{{ $step['label'] }}</h3>
            <p>{{ $step['body'] }}</p>
        </div>
    @endforeach
</div>
