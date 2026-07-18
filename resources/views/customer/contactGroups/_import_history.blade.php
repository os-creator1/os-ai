<div class="row">
    <div class="col-12">
        <div class="card">
            <table class="table mb-0">
                <thead class="thead-primary">
                <tr>
                    <th scope="col">{{ __('locale.labels.submitted') }}</th>
                    <th scope="col">{{ __('locale.labels.status') }}</th>
                    <th scope="col">{{ __('locale.labels.total') }}</th>
                    <th scope="col">{{ __('locale.labels.processed') }}</th>
                    <th scope="col">{{ __('locale.labels.failed') }}</th>
                    <th scope="col">{{ __('locale.labels.message') }}</th>
                    <th scope="col">{{ __('locale.labels.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @if ($import_jobs->count() > 0)
                    @foreach($import_jobs as $job)
                        @php
                            $progress = $contact->getProgress($job);
                        @endphp
                        <tr>
                            <td>{{ \App\Library\Tool::customerDateTime($job->created_at) }}</td>
                            <td>
                                    <span class="badge {{ $job->status == 'done' ? 'bg-success' : ($job->status == 'failed' ? 'bg-danger' : ($job->status == 'running' ? 'bg-info' : 'bg-secondary')) }} text-uppercase">
    {{ $job->status }}
</span>
                            </td>
                            <td>{{ $progress['total'] }}</td>
                            <td>{{ $progress['processed'] }}</td>
                            <td>{{ $progress['failed'] }}</td>
                            <td>{{ strtoupper($progress['message']) }}</td>
                            <td>
                                @if($job->status == 'done' && $progress['failed'] > 0)
                                    <a href="{{ route('customer.contacts.download_failed', ['contact' => $contact->uid , 'job_id' => $job->id]) }}"
                                       class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top"
                                       title="Download Failed Records">
                                        <i data-feather="download"></i>
                                    </a>
                                @else
                                    <span class="btn btn-secondary btn-sm disabled" data-bs-toggle="tooltip"
                                          data-bs-placement="top" title="No failed records to download">
                                            <i data-feather="download"></i>
                                        </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td class="text-center" colspan="7">
                            {{ __('locale.datatables.no_results') }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
