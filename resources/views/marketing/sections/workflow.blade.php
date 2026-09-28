@php
    $steps = [
        ['label' => __('Publish'), 'body' => __('Your business website goes live with your services, packages, and a way to reach you.')],
        ['label' => __('Capture'), 'body' => __('A visitor submits an inquiry from your site — it lands in your account immediately.')],
        ['label' => __('Reply'), 'body' => __('You reply from Conversations, one inbox for every channel a customer used to reach you.')],
        ['label' => __('Book'), 'body' => __('Confirm the job on your Calendar, right from the same conversation.')],
        ['label' => __('Propose & pay'), 'body' => __('Send a proposal, and get paid once it is accepted — still one connected thread.')],
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
