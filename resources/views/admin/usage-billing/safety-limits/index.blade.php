@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Feature-Usage Safety Limits')

@section('content')
    <section id="admin-usage-billing-safety-limits-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card title="Configured Platform Safety Limits">
                    @if ($safetyLimits->isEmpty())
                        <x-empty-state icon="shield" title="No platform feature-usage safety limits configured." />
                    @else
                        <x-table :headers="['Feature key', 'Max monthly limit', 'Updated by']">
                            @foreach ($safetyLimits as $limit)
                                <tr>
                                    <td>{{ $limit->feature_key }}</td>
                                    <td class="text-numeric">{{ $limit->max_monthly_limit_micro }}</td>
                                    <td class="text-numeric">{{ $limit->updated_by_user_id }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Set / Update a Platform Safety Limit">
                    <form method="POST" action="{{ route('admin.usage-billing.safety-limits.update') }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-3">
                                <label class="text-label">Feature key</label>
                                <input type="text" name="feature_key" class="form-control transition-fast" required maxlength="64" value="{{ old('feature_key') }}">
                            </div>
                            <div class="col-sm-3">
                                <label class="text-label">Max monthly limit (micros)</label>
                                <input type="number" name="max_monthly_limit_micro" class="form-control transition-fast" min="0" required value="{{ old('max_monthly_limit_micro') }}">
                            </div>
                            <div class="col-sm-4">
                                <label class="text-label">Reason</label>
                                <input type="text" name="reason" class="form-control transition-fast" required maxlength="5000" value="{{ old('reason') }}">
                            </div>
                            <div class="col-sm-2">
                                <x-button type="submit" variant="primary" size="sm" class="w-100">Set Limit</x-button>
                            </div>
                        </div>
                    </form>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Platform Safety-Limit History">
                    @if ($history->isEmpty())
                        <x-empty-state icon="clock" title="No platform safety-limit changes recorded." />
                    @else
                        <x-table :headers="['Feature', 'From', 'To', 'Actor', 'Reason', 'When']">
                            @foreach ($history as $transition)
                                <tr>
                                    <td>{{ $transition->feature_key }}</td>
                                    <td class="text-numeric">{{ $transition->from_value_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $transition->to_value_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $transition->actor_user_id }}</td>
                                    <td>{{ $transition->reason }}</td>
                                    <td class="text-caption">{{ $transition->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
