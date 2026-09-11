<?php

    namespace App\Http\Controllers\User;

    use App\Http\Controllers\Controller;
    use App\Library\Dashboard\AccountHomePresenter;
    use App\Library\Dashboard\BusinessHomePresenter;
    use App\Library\Navigation\CustomerShellComposer;
    use App\Models\User;
    use Illuminate\Contracts\View\View;
    use Illuminate\Support\Facades\Auth;

    /**
     * Customer dashboard (user.home).
     *
     * Customer Experience Slice 4 (docs/automation/CUSTOMER-EXPERIENCE-
     * REDESIGN-SLICE-4-DASHBOARD.md §3, §13): the page renders by the
     * resolved CustomerContext frame and by nothing else — the Business Home
     * for a selected Business (the viewed client's, while viewing as one),
     * the Agency Account Home, the Account-frame chooser, or the zero-Business
     * state. The controller only resolves the context, asks the presenter and
     * returns the view: every figure is assembled before the view runs, and
     * the view executes no query.
     *
     * Auth::user() is the capability actor, never the data scope. The former
     * user-scoped tiles (invoices, campaigns, templates, contact groups,
     * blocklist), the announcements card (still one click away in the navbar)
     * and the sole-Business Opportunity panel with its primary-Business guess
     * are gone with this rebuild.
     */
    class UserController extends Controller
    {
        public function index(
            CustomerShellComposer $shell,
            BusinessHomePresenter $businessHome,
            AccountHomePresenter $accountHome,
        ): View {
            /** @var User $user */
            $user = Auth::user();
            $context = $shell->currentContext($user);

            $dashboard = $businessHome->present($context, $user) ?? $accountHome->present($context, $user);

            return view('customer.dashboard', [
                'dashboard' => $dashboard,
                // The page's own <h1> names the frame and the Business, so the
                // shared title bar (an <h2> ahead of it) is not rendered here.
                'pageConfigs' => ['pageHeader' => false],
            ]);
        }
    }
