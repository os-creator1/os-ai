<p>Your business profile is ready. Finish setup to activate your business and open your dashboard.</p>

<form method="POST" action="{{ route('customer.onboarding.complete') }}">
    @csrf
    <x-button type="submit" variant="primary">Finish setup</x-button>
</form>
