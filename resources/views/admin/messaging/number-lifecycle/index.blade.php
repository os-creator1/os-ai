@extends('layouts/contentLayoutMaster')

@section('title', 'Messaging Number Lifecycle')

@section('content')
    <section id="admin-messaging-number-lifecycle-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card title="Suspended Numbers">
                    @if ($numbers->isEmpty())
                        <x-empty-state icon="inbox" title="No suspended numbers." />
                    @else
                        <x-table :headers="['ID', 'Business', 'Phone number', 'Suspended', 'Grace ends', 'Release notice', 'Release decision']">
                            @foreach ($numbers as $number)
                                <tr>
                                    <td class="text-numeric">{{ $number->id }}</td>
                                    <td>{{ $number->identity?->business?->name ?? '—' }}</td>
                                    <td class="text-caption">{{ $number->phone_number }}</td>
                                    <td class="text-caption">{{ $number->suspended_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>
                                        <x-badge variant="{{ $number->graceHasExpired() ? 'danger' : 'warning' }}">
                                            {{ $number->grace_expires_at?->format('Y-m-d') ?? '—' }}
                                        </x-badge>
                                    </td>
                                    <td class="text-caption">
                                        @if ($number->release_notice_delivered_at !== null)
                                            <x-badge variant="success">Delivered {{ $number->release_notice_delivered_at->format('Y-m-d H:i') }}</x-badge>
                                        @elseif ($number->release_notice_failed_at !== null)
                                            <x-badge variant="danger">Failed {{ $number->release_notice_failed_at->format('Y-m-d H:i') }}</x-badge>
                                        @else
                                            <x-badge variant="neutral">Not yet sent</x-badge>
                                        @endif
                                    </td>
                                    <td style="min-width: 260px;">
                                        @if ($number->release_decided_at !== null)
                                            <span class="text-caption text-muted">Decision recorded on {{ $number->release_decided_at->format('Y-m-d H:i') }} — awaiting confirmed carrier release.</span>
                                        @elseif ($number->isEligibleForReleaseDecision())
                                            <form method="POST" action="{{ route('admin.messaging-number-lifecycle.release', $number->id) }}">
                                                @csrf
                                                <input type="text" name="note" class="form-control form-control-sm transition-fast mb-1" placeholder="What was checked before deciding" required maxlength="5000">
                                                <div class="form-check mb-1">
                                                    <input type="checkbox" class="form-check-input" id="release-confirmed-{{ $number->id }}" name="release_confirmed" value="1" required>
                                                    <label class="form-check-label text-caption" for="release-confirmed-{{ $number->id }}">
                                                        I confirm this Business was notified, has had a meaningful opportunity to pay or port out, and no active port-out request is being overridden.
                                                    </label>
                                                </div>
                                                <x-button type="submit" variant="danger" size="sm">Record release decision</x-button>
                                            </form>
                                            <span class="text-caption text-muted d-block mt-1">This records a decision only — it does not itself release the number with the carrier.</span>
                                        @else
                                            <span class="text-caption text-muted">Not yet eligible — grace period must expire and the release notice must be confirmed delivered for the required minimum notice period first.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $numbers->links() }}
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
