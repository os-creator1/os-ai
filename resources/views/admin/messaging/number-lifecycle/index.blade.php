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
                        <x-table :headers="['ID', 'Business', 'Phone number', 'Suspended', 'Grace ends', 'Release notice sent', 'Release']">
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
                                    <td class="text-caption">{{ $number->release_notice_sent_at?->format('Y-m-d H:i') ?? 'Not sent' }}</td>
                                    <td style="min-width: 260px;">
                                        @if ($number->graceHasExpired() && $number->release_notice_sent_at !== null)
                                            <form method="POST" action="{{ route('admin.messaging-number-lifecycle.release', $number->id) }}">
                                                @csrf
                                                <input type="text" name="note" class="form-control form-control-sm transition-fast mb-1" placeholder="What was checked before releasing" required maxlength="5000">
                                                <div class="form-check mb-1">
                                                    <input type="checkbox" class="form-check-input" id="release-confirmed-{{ $number->id }}" name="release_confirmed" value="1" required>
                                                    <label class="form-check-label text-caption" for="release-confirmed-{{ $number->id }}">
                                                        I confirm this Business has been notified and given a fair chance to pay or port out before release.
                                                    </label>
                                                </div>
                                                <x-button type="submit" variant="danger" size="sm">Release number</x-button>
                                            </form>
                                        @else
                                            <span class="text-caption text-muted">Not yet eligible — grace period must expire and a release notice must be sent first.</span>
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
