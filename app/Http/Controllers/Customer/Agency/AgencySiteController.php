<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\AgencySite\SitePublishBlockedException;
use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Agency\Concerns\AuthorizesAgencyWorkspace;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\SiteEditorPresenter;
use App\Library\AgencySite\SiteRenderer;
use App\Library\AgencySite\SiteSchema;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * White Label → Landing Page: the editor's JSON endpoints and the authenticated draft preview.
 *
 * READING (the preview, the editor state) is Agency-team work; every WRITE is re-authorised by the managers from
 * the locked Workspace row (owner + eligibility + entitlement), so hiding a control here is presentation only.
 * The Agency is always resolved from the route and the actor's authority over it — never from the body.
 */
class AgencySiteController extends Controller
{
    use AuthorizesAgencyWorkspace;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencySiteManager $sites,
        private readonly SiteRenderer $renderer,
        private readonly SiteEditorPresenter $presenter,
        private readonly AgencySiteBookingService $booking,
    ) {
    }

    /** Draft preview of one page, rendered from the saved draft (never from the live revision). */
    public function preview(string $workspaceUid, string $page = 'home'): Response
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $site = $this->sites->find($agency);
        abort_if($site === null, 404);

        $html = $this->renderer->page($site, AgencySiteManager::values($site), $page, $agency->uid);
        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    /** The draft's stylesheet in the Agency's colours: private, so a draft site is never reachable by its public id. */
    public function previewCss(string $workspaceUid): Response
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $site = $this->sites->find($agency);
        abort_if($site === null, 404);

        $css = $this->renderer->css($this->renderer->site($site, AgencySiteManager::values($site), $agency->uid)->context());

        return response($css)->header('Content-Type', 'text/css; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }

    /** Save answers (JSON). Returns the refreshed checklist so the editor can show status without a reload. */
    public function save(Request $request, string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        $request->validate(['answers' => ['required', 'array', 'max:80'], 'step' => ['nullable', 'integer', 'min:1', 'max:10']]);

        return $this->guard(function () use ($request, $agency) {
            $site = $this->sites->save((int) Auth::id(), $agency, $request->input('answers'), $request->input('step'));

            return $this->state($agency, $site);
        });
    }

    public function step(Request $request, string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $data = $request->validate(['step' => ['required', 'integer', 'min:1', 'max:10']]);

        return $this->guard(fn () => $this->state($agency, $this->sites->save((int) Auth::id(), $agency, [], $data['step'])));
    }

    public function resetGroup(Request $request, string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $data = $request->validate(['group' => ['required', 'string', 'max:40']]);

        return $this->guard(fn () => $this->state($agency, $this->sites->resetGroup((int) Auth::id(), $agency, $data['group'])));
    }

    /** Create a booking type from inside the editor, select it, and report its readiness. */
    public function createBookingType(Request $request, string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'meeting_location_type' => ['nullable', 'string', 'in:'.implode(',', \App\Library\Calendar\Zoom\ZoomHostResolver::TYPES)],
            'meeting_location_custom' => ['nullable', 'string', 'max:500', 'required_if:meeting_location_type,custom'],
            'meeting_instructions' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_hours' => ['nullable', 'boolean'],
        ]);

        // Zoom needs a connected host, which this form cannot create: leave it to the full booking type editor.
        if (($data['meeting_location_type'] ?? null) === 'zoom') {
            return response()->json(['message' => 'Choose a different location here; set up Zoom in the booking type settings.', 'errors' => ['meeting_location_type' => ['Zoom needs a connected host.']]], 422);
        }

        return $this->guard(function () use ($agency, $data) {
            [, $site] = $this->sites->createAndSelectBookingType((int) Auth::id(), $agency, $data);

            return $this->state($agency, $site);
        });
    }

    public function publish(string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        return $this->guard(fn () => $this->state($agency, $this->sites->publish((int) Auth::id(), $agency)));
    }

    public function unpublish(string $workspaceUid): JsonResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        return $this->guard(fn () => $this->state($agency, $this->sites->unpublish((int) Auth::id(), $agency)));
    }

    /** @return array<string, mixed> the small slice of editor state the page updates in place */
    private function state($agency, $site): array
    {
        $data = $this->presenter->present($agency, $site->refresh());

        return [
            'status' => $data['status'],
            'isLive' => $data['isLive'],
            'unpublishedChanges' => $data['unpublishedChanges'],
            'checklist' => $data['checklist'],
            'booking' => $data['booking'],
            'stripe' => $data['stripe'],
            'hostedUrl' => $data['hostedUrl'],
        ];
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $action());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        } catch (AgencyWhiteLabelException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        } catch (SitePublishBlockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'blockers' => $e->blockers], 422);
        }
    }
}
