<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Library\PlatformOwner\PlatformSupportSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform Owner V1 final — Support. A finder plus links into the surfaces
 * that already hold the legitimate support actions (Workspace cockpit: access
 * state + restore; Users: reset link, verification, suspend; Audit Logs).
 */
class PlatformSupportController extends Controller
{
    public function __construct(private readonly PlatformSupportSearch $search)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('view workspace');

        $term = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($term) > 100, 422);

        return view('admin.platform-support.index', [
            'term' => $term,
            'results' => $term === '' ? null : $this->search->search($term),
            'breadcrumbs' => [['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'], ['name' => 'Support']],
        ]);
    }
}
