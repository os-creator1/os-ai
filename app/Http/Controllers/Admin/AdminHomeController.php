<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Library\Navigation\AdminMenuBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Landing pages for the admin area. Deliberately NOT an AdminBaseController subclass: every admin controller extends
 * that one and overrides index() with its own list view.
 */
class AdminHomeController extends Controller
{
    /**
     * `/dashboard` (admin.home) used to render the inherited Ultimate SMS dashboard and its "getting started"
     * checklist. That page is retired: the URL stays only so bookmarks, old links and any remaining
     * `route('admin.home')` keep resolving, and it sends the signed-in backend user to the landing page they are
     * authorised for - the Platform Owner Home, or the staff landing page.
     */
    public function index(): RedirectResponse
    {
        return redirect()->to(Helper::adminHomeUrl());
    }

    /**
     * The landing page for backend staff who are not Platform Owners (no Platform Owner Home authority). It lists
     * only the sections this user may open - the same visibility rules as the sidebar, which are presentation only;
     * every target route re-checks authority server-side.
     */
    public function staffHome(AdminMenuBuilder $menu): View|RedirectResponse
    {
        $user = Auth::user();

        // A Platform Owner never sees the staff page: send them to the real Home.
        if (Helper::isPlatformOwnerHome($user)) {
            return redirect()->to(route('admin.platform-owner.overview'));
        }

        $sections = collect($menu->build($user, request()->path(), false))
            ->reject(fn (object $entry) => isset($entry->navheader) || ! isset($entry->url) || ($entry->slug ?? null) === config('app.admin_path') . '/staff-home')
            ->values();

        return view('admin.staff-home', [
            'sections'    => $sections,
            'breadcrumbs' => [['name' => __('locale.menu.Home')]],
        ]);
    }
}
