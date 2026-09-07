@php use App\Library\Tool;use App\Models\Campaigns; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('page-style')
    {{-- Page css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/dashboard-ecommerce.css')) }}">
@endsection

@section('content')
    {{-- Dashboard Analytics Start --}}
    <section>

        @unless($userAnnouncements->isEmpty())
            <div class="row match-height">
                <div class="col-12 announcement-card">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex align-items-center">
                                <h4 class="card-title">{{ __('locale.menu.Announcements') }}</h4>
                            </div>
                            <a href="#" class="mark_read h5 text-muted text-uppercase"><x-ds-icon name="x-circle"
                                                                                          class="font-medium-3 cursor-pointer" /> {{ __('locale.buttons.close') }}
                            </a>
                        </div>
                        <hr class="my-0">
                        <div class="card-body alert-primary">
                            <ul class="timeline ms-50">
                                @foreach($userAnnouncements as $announcement)
                                    @if($announcement->pivot->read_at === null)
                                        <li class="timeline-item">
                                            <span class="timeline-point timeline-point-indicator"></span>
                                            <div class="timeline-event">
                                                <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                    <a href="{{ route('user.account.announcement.view', $announcement->uid) }}">
                                                        <h6>{{ $announcement->title }}</h6></a>
                                                    <span class="timeline-event-time me-1">{{ $announcement->created_at->diffForHumans() }}</span>
                                                </div>
                                                <p>{!! $announcement->description !!}</p>

                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @endunless

        @php
            $isAdminImpersonation = session()->has('admin_user_id') && session()->has('temp_user_id');
        @endphp

        @if(Auth::check() && Auth::user()->parent_id !== null && !$isAdminImpersonation)
            <div class="row match-height">
                <div class="col-12 announcement-card">
                    <div class="card border shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">{{ __('locale.sub_accounts.manage_account') }}</h4>
                            <a href="#" class="mark_read h5 text-muted text-uppercase">
                                <x-ds-icon name="x-circle" class="font-medium-3 cursor-pointer" />
                                {{ __('locale.buttons.close') }}
                            </a>
                        </div>
                        <hr class="my-0">

                        <div class="card-body alert-primary text-center">
                            <p class="mb-2">{{ __('locale.sub_accounts.login_as_parent_message') }}</p>
                            <a href="{{ route('user.account.login_as', Auth::user()->parent->uid) }}"
                               class="btn btn-primary">
                                {{ __('locale.sub_accounts.login_as_parent') }}:
                                <strong>{{ Auth::user()->parent->displayName() }}</strong>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif


        <div class="row match-height">
            <div class="col-lg-6 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-body">
                        <h3 class="text-primary">{{ \App\Helpers\Helper::greetingMessage()}}</h3>
                        <p class="font-medium-2 mt-2">{{ __('locale.description.dashboard', ['brandname' => config('app.name')]) }}</p>

                        <div class="row d-flex justify-content-center">
                            @can('sms_quick_send')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.sms.quick_send') }}"
                                       class="btn btn-sm btn-warning text-nowrap"><x-ds-icon
                                                name="send" />

                                        <span>{{__('locale.menu.Quick Send')}}</span>
                                    </a>
                                </div>
                            @endcan

                            @can('sms_campaign_builder')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.sms.campaign_builder') }}"
                                       class="btn btn-sm btn-success text-nowrap"><x-ds-icon
                                                name="server" />

                                        <span>{{__('locale.menu.Campaign Builder')}}</span></a>
                                </div>
                            @endcan

                            @can('view_contact_group')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.contacts.index') }}"
                                       class="btn btn-sm btn-info text-nowrap"><x-ds-icon
                                                name="user" />
                                        <span>{{__('locale.contacts.contact_groups')}}</span>
                                    </a>
                                </div>
                            @endcan


                        </div>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <h3 class="text-primary">{{ __('locale.labels.current_plan')  }}</h3>
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() == null)
                            <h3 class="mt-1 text-danger">{{ __('locale.subscription.no_active_subscription') }}</h3>
                        @else

                            @if(isset(Auth::user()->customer))
                                <p class="mb-2 mt-1 font-medium-2">{!! __('locale.subscription.you_are_currently_subscribed_to_plan',
                                        [
                                                'plan' => auth()->user()->customer->subscription->plan->name,
                                                'price' => Tool::format_price(auth()->user()->customer->subscription->plan->price, auth()->user()->customer->subscription->plan->currency->format),
                                                'remain' => Tool::formatHumanTime(auth()->user()->customer->subscription->current_period_ends_at),
                                                'end_at' => Tool::customerDateTime(auth()->user()->customer->subscription->current_period_ends_at)
                                        ]) !!}</p>
                            @endif
                        @endif

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('customer.subscriptions.index') }}" class="btn btn-sm btn-primary me-1"><x-ds-icon
                                        name="info" /> {{ __('locale.labels.more_info') }}</a>
                            @if (isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription())
                                <a href="{{ route('customer.subscriptions.change_plan', auth()->user()->customer->subscription->uid) }}"
                                   class="btn btn-sm btn-info"><x-ds-icon
                                            name="credit-card" /> {{ __('locale.labels.packages') }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @can('view_reports')
                {{-- B5 Business Analytics (contract §18.5): the legacy
                     user-scoped SMS pie is replaced by the entry point to
                     the Business-scoped Analytics surface. --}}
                <div class="col-lg-3 col-sm-6 col-12">
                    <x-card title="Analytics" data-role="analytics-entry-card">
                        <p class="text-caption mb-2">Messages, campaigns, contacts and automations for each Business, grouped by day in the Business's own timezone.</p>
                        <x-button variant="primary" size="sm" icon="bar-chart-2" :href="route('customer.analytics.entry')">Open Analytics</x-button>
                    </x-card>
                </div>
            @endcan

        </div>

        @if($opportunities !== null)
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title">Opportunities</h4>
                            <a href="{{ route('customer.opportunities.index') }}" class="btn btn-sm btn-outline-primary">
                                View all opportunities
                            </a>
                        </div>
                        <div class="card-body">
                            @forelse($opportunities as $opportunity)
                                <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                    <div>
                                        <a href="{{ route('customer.opportunities.show', $opportunity->id) }}">{{ $opportunity->title }}</a>
                                        <div class="text-muted small">
                                            {{ ucwords(str_replace('_', ' ', $opportunity->status->value)) }}
                                            @if ($opportunity->first_detected_at)
                                                &middot; {{ $opportunity->first_detected_at->format('M j, Y') }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <x-empty-state title="No opportunities are available right now." />
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="row match-height">

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">

                                @php
                                    $campaigns = Campaigns::where('user_id', Auth::user()->id);
                                    $totalCamp = $campaigns->count();
                                    $deliveredCamp = $campaigns->where('status', '!=', Campaigns::STATUS_DONE)->count();
                                @endphp

                                <sup>{{ $deliveredCamp }}</sup>
                                / {{ $totalCamp }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Campaigns')) }}</p>
                        </div>
                        <a href="{{ route('customer.analytics.entry') }}">
                            <div class="avatar bg-light-info p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="pie-chart" class="text-info font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            {{-- The legacy "delivered / failed" cards (user-scoped, `%Delivered%`
                 contains-match) are removed by B5 Business Analytics; provider
                 acceptance and confirmed failures are reported per Business in
                 the Analytics overview. --}}

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->smsTemplateCounts() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.permission.sms_template')) }}</p>
                        </div>
                        <a href="{{ route('customer.templates.index') }}">
                            <div class="avatar bg-light-warning p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="inbox" class="text-warning font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <div class="row match-height">

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() != null)
                            <div>
                                <h2 class="fw-bolder mb-0"> {{ Auth::user()->customer->listsCount() != null ? Tool::format_number(Auth::user()->customer->listsCount()): 0 }}</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0"> 0</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @endif
                        <a href="{{ route('customer.contacts.index') }}">
                            <div class="avatar bg-light-primary p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="users" class="text-primary font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() != null)
                            <div>
                                <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->subscriberCounts() != null ? Tool::format_number(Auth::user()->customer->subscriberCounts()) : 0 }}</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0">0</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @endif
                        <a href="{{ route('customer.contacts.index') }}">
                            <div class="avatar bg-light-success p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="user" class="text-success font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">
                                <sup>{{ \App\Models\Invoices::where('user_id', Auth::user()->id)->where('status', \App\Models\Invoices::STATUS_UNPAID)->orWhere('status', \App\Models\Invoices::STATUS_PENDING)->count() }}</sup>
                                / {{ \App\Models\Invoices::where('user_id', Auth::user()->id)->count() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Invoices')) }}</p>
                        </div>
                        <a href="{{ route('customer.subscriptions.index') }}">
                            <div class="avatar bg-light-info p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="shopping-cart" class="text-info font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->blacklistCounts() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Blacklist')) }}</p>
                        </div>
                        <a href="{{ route('customer.blacklists.index') }}">
                            <div class="avatar bg-light-danger p-50 m-0">
                                <div class="avatar-content">
                                    <x-ds-icon name="user-x" class="text-danger font-medium-5" />
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>


        {{-- The seven per-SMS-type monthly charts are removed by B5 Business
             Analytics (contract §18.4/§18.5): message volume is charted per
             Business, in the Business timezone, in the Analytics overview. --}}

    </section>
    <!-- Dashboard Analytics end -->
@endsection


@section('page-script')

    <script>
      $(window).on("load", function() {

        $(".mark_read").on("click", function(e) {
          e.stopPropagation();

          $.ajax({
            url: "{{ route('user.account.announcement.mark-all-as-read') }}",
            type: "POST",
            data: {
              _token: "{{csrf_token()}}"
            },
            success: function(response) {
              if (response.success) {
                $(".announcement-card").fadeOut(1000);
              } else {
                toastr["warning"](response.message, "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            },
            error: function(error) {
              toastr["warning"](error.responseText, "{{__('locale.labels.attention')}}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          });

        });

      });

    </script>

@endsection
