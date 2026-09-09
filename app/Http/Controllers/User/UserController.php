<?php

    namespace App\Http\Controllers\User;

    use App\Http\Controllers\Controller;
    use App\Library\Workspace\WorkspaceManager;
    use App\Models\Business;
    use App\Models\Invoices;
    use App\Repositories\Contracts\OpportunityRepository;
    use App\Repositories\Contracts\UserRepository;
    use App\Repositories\Contracts\WorkspaceRepository;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Cache;

    /**
     * Customer dashboard.
     *
     * B5 Business Analytics (contract §18.4): the seven per-SMS-type
     * monthly charts, the delivered/undelivered pie and their user-scoped
     * `%Delivered%` counts are removed from this controller — every
     * message figure now lives in the Business-scoped Analytics surface
     * (customer.workspaces.businesses.analytics.*), which this dashboard
     * links to. The Opportunity panel no longer infers a "primary"
     * Business through BusinessRepository::findPrimaryByCustomer(); it
     * renders only when the actor can access exactly one Business, the
     * same 0 / 1 / many convention every Business chooser in this
     * application follows.
     */
    class UserController extends Controller
    {
        /**
         * Fixed, source-controlled top-N count for the dashboard Opportunity
         * panel (RFC-002 §43) — matches the existing userAnnouncements
         * ->take(5) convention already used in this same method, never
         * request input.
         */
        private const OPPORTUNITY_PANEL_LIMIT = 5;

        protected UserRepository $users;

        protected WorkspaceRepository $workspaceRepository;

        protected WorkspaceManager $workspaceManager;

        protected OpportunityRepository $opportunityRepository;

        /**
         * UserController constructor.
         */
        public function __construct(
            UserRepository $users,
            WorkspaceRepository $workspaceRepository,
            WorkspaceManager $workspaceManager,
            OpportunityRepository $opportunityRepository
        ) {
            $this->users = $users;
            $this->workspaceRepository = $workspaceRepository;
            $this->workspaceManager = $workspaceManager;
            $this->opportunityRepository = $opportunityRepository;
        }


        public function index()
        {
            $userId = Auth::id();
            $opportunities = $this->opportunityPanel();

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => Auth::user()->displayName()],
            ];

            // Cache announcements (5 minutes)
            $userAnnouncements = Cache::remember("customer_{$userId}_announcements", now()->addMinutes(5), function () {
                return Auth::user()->announcements()
                    ->wherePivot('read_at', '=', null)
                    ->latest()
                    ->take(5)
                    ->get();
            });

            // Security Remediation Slice 0 §16.A.2 (D-19) — computed here,
            // once, with an explicit actor argument, rather than left as an
            // inline Blade query. whereIn() replaces the former
            // ->where('status', UNPAID)->orWhere('status', PENDING), whose
            // ungrouped OR let the second disjunct run with no ownership
            // predicate at all (AND binds tighter than OR), disclosing every
            // tenant's unpaid/pending invoice count to every other tenant.
            // whereIn() applies the SAME $userId predicate to both statuses
            // by construction, not by a closure a future edit could
            // unbalance again. The invoices table has only user_id — no
            // business_id/workspace_id — so this is Business/Account-scoped
            // only by way of $userId already identifying one paying user;
            // re-keying invoices to a Business or Account is a schema
            // migration and a billing-model change, out of this slice's
            // scope (§16.A.2), tracked at the navigation layer in §9/Slice 5.
            $unpaidAndPendingInvoiceCount = Invoices::where('user_id', $userId)
                ->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])
                ->count();
            $totalInvoiceCount = Invoices::where('user_id', $userId)->count();

            return view('customer.dashboard', compact(
                'breadcrumbs',
                'userAnnouncements',
                'opportunities',
                'unpaidAndPendingInvoiceCount',
                'totalInvoiceCount'
            ));
        }

        /**
         * The bounded top-N actionable Opportunity list for the dashboard
         * panel (RFC-002 §43) — null whenever the panel must not render at
         * all: the feature is disabled, or the authenticated actor does not
         * have access to exactly one Business (zero, or several — a
         * dashboard panel must never guess which one). Never queries
         * Opportunities (or any Business) when disabled — the config check
         * is this method's first statement.
         */
        private function opportunityPanel(): ?Collection
        {
            if (! config('opportunity.enabled', false)) {
                return null;
            }

            $business = $this->soleAccessibleBusiness((int) Auth::id());

            if ($business === null) {
                return null;
            }

            return $this->opportunityRepository->topForCustomer($business, self::OPPORTUNITY_PANEL_LIMIT);
        }

        /**
         * Exactly one accessible Business, or null. The same enumeration
         * the Business choosers use (WorkspaceRepository::allForUser →
         * businessesForWorkspace → WorkspaceManager::userCanAccessBusiness);
         * Auth::id() is the capability actor here, never a tenant key.
         */
        private function soleAccessibleBusiness(int $userId): ?Business
        {
            $accessible = [];

            foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
                foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                    if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                        $accessible[] = $business;

                        if (count($accessible) > 1) {
                            return null;
                        }
                    }
                }
            }

            return count($accessible) === 1 ? $accessible[0] : null;
        }
    }
