@php
    $templateHeaders = $contact->getFields->pluck('label')->values()->all();
@endphp
@if ($import_jobs->count() === 0)
    <section class="mgx-card mgx-gd-empty" data-role="imports-empty">
        <span class="mgx-gd-emptyico" aria-hidden="true"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg></span>
        <div class="mgx-gd-emptytext">
            <b>No imports yet</b>
            <span>Upload a CSV or Excel file to add many contacts at once. Each import shows here with how many worked and any that failed.</span>
        </div>
        <div class="mgx-gd-emptyactions">
            <a class="mgx-btn is-primary" data-role="imports-import" href="{{ \App\Library\CrmRouting::route('contact.import', $contact->uid) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>Import contacts</a>
            <button type="button" class="mgx-btn is-secondary" data-role="imports-template" data-template-name="{{ \Illuminate\Support\Str::slug($contact->name) ?: 'contacts' }}-template.csv" data-template-headers="{{ json_encode($templateHeaders) }}">Download template</button>
        </div>
    </section>
@else
    <div class="mgx-gd-kwbar">
        <span class="mgx-mut mgx-gd-kwhint">Each import shows how many contacts worked and how many failed.</span>
        <button type="button" class="mgx-btn is-secondary" data-role="imports-template" data-template-name="{{ \Illuminate\Support\Str::slug($contact->name) ?: 'contacts' }}-template.csv" data-template-headers="{{ json_encode($templateHeaders) }}">Download template</button>
        <a class="mgx-btn is-primary" data-role="imports-import" href="{{ \App\Library\CrmRouting::route('contact.import', $contact->uid) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>Import contacts</a>
    </div>
    <div class="mgx-card mgx-gd-card mgx-gd-table">
        <table class="table mb-0">
            <thead>
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
                                    <x-button :href="\App\Library\CrmRouting::route('contacts.download_failed', ['contact' => $contact->uid , 'job_id' => $job->id])"
                                       size="sm" icon="download" data-bs-toggle="tooltip" data-bs-placement="top"
                                       title="Download Failed Records">
                                    </x-button>
                                @else
                                    <span class="mgx-iconbtn is-disabled" data-bs-toggle="tooltip"
                                          data-bs-placement="top" title="No failed records to download">
                                            <x-ds-icon name="download" />
                                        </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
            </tbody>
        </table>
    </div>
@endif
