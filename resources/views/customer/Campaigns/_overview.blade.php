@php use App\Library\Tool;use App\Models\Campaigns; @endphp
<div class="row match-height">
    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $campaign->readCache('ContactCount') }}</h2>
                    <p class="card-text">{{ __('locale.campaigns.total_recipients') }}</p>
                </div>

                <div class="avatar bg-light-info p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="check-square" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->delivered_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.delivered') }}</p>
                </div>

                <div class="avatar bg-light-success p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="check-square" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->enroute_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.enroute') }}</p>
                </div>

                <div class="avatar bg-light-primary p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="truck" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row match-height">

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->undelivered_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.undelivered') }}</p>
                </div>

                <div class="avatar bg-light-danger p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="x-square" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->rejected_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.rejected') }}</p>
                </div>

                <div class="avatar bg-light-danger p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="x-circle" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->accepted_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.accepted') }}</p>
                </div>

                <div class="avatar bg-light-primary p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="check-circle" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row match-height">

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->expired_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.expired') }}</p>
                </div>

                <div class="avatar bg-light-warning p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="alert-triangle" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->skipped_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.skipped') }}</p>
                </div>

                <div class="avatar bg-light-warning p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="skip-forward" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6 col-12">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->failed_count }}</h2>
                    <p class="card-text">{{ __('locale.labels.failed') }}</p>
                </div>

                <div class="avatar bg-light-danger p-50 m-0">
                    <div class="avatar-content">
                        <i data-feather="x-octagon" class="font-medium-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>


<div class="row match-height">
    <div class="col-lg-4 col-md-6 col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-end">
                <h4 class="mb-0 text-uppercase text-primary">{{__('locale.menu.Overview')}}</h4>
            </div>
            <div class="card-content">
                <div class="card-body">
                    <h5 class="mb-1">{{ __('locale.campaigns.campaign_reference') }}:
                        <span> {{ $campaign->campaign_name }}</span></h5>
                    <h5 class="mb-1">{{ __('locale.labels.campaign_id') }}: <span> {{ $campaign->uid }}</span></h5>
                    <h5 class="mb-1">{{ __('locale.labels.campaigns_type') }}:
                        <span> {!! $campaign->getCampaignType() !!}</span></h5>
                    <h5 class="mb-1">{{ __('locale.labels.status') }}: <span
                                class="text-capitalize"> {{ $campaign->status }}</span></h5>
                    <h5 class="mb-1">{{ __('locale.labels.created_at') }}:
                        <span> {{ Tool::customerDateTime($campaign->created_at) }}</span></h5>
                    <h5 class="mb-1">{{ __('locale.labels.run_at') }}:
                        <span> {{ Tool::customerDateTime($campaign->run_at) }}</span></h5>

                    @if($campaign->upload_type == 'normal')
                        <h5 class="mb-1">{{ __('locale.contacts.contact_groups') }}:
                            <span> {!! $campaign->contactGroupsName() !!}</span></h5>
                    @endif



                    @if($campaign->status == Campaigns::STATUS_FAILED || $campaign->status == Campaigns::STATUS_CANCELLED || $campaign->status == Campaigns::STATUS_PAUSED)
                        <h5 class="mb-1">{{ __('locale.labels.reason') }}: <code> {{ $campaign->reason }}</code></h5>
                    @endif

                    @if($campaign->status == Campaigns::STATUS_ERROR)
                        <h5 class="mb-1">{{ __('locale.labels.reason') }}: <code> {{ $campaign->last_error }}</code>
                        </h5>
                    @endif

                    @if($campaign->status == Campaigns::STATUS_DELIVERED || $campaign->status == Campaigns::STATUS_DONE)
                        <h5 class="mb-1">{{ __('locale.labels.delivered_at') }}:
                            <span> {{ Tool::customerDateTime($campaign->delivery_at) }}</span></h5>
                    @endif


                </div>
            </div>
        </div>
    </div>


    <div class="col-lg-4 col-md-6 col-12">
        <div class="card">
            <div class="card-header flex-column align-items-start">
                <h4 class="card-title mb-75">{{ __('locale.labels.reports_ratio') }}</h4>
            </div>
            <div class="card-body">
                <div id="reports-ratio"></div>
            </div>
        </div>
    </div>


    <div class="col-lg-4 col-md-6 col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-end">
                <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_reports') }}</h4>
            </div>
            <div class="card-content">
                <div class="card-body p-0">
                    <div id="sms-reports" class="my-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

