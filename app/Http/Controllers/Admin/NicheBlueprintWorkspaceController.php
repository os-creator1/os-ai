<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NicheBlueprint\BlueprintAuthoringException;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Library\NicheBlueprint\Workspace\BlueprintSurfaces;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Blueprint Workspace — the Platform Owner's place to configure a niche.
 *
 * "Blueprint Scope": configuration surfaces over a DRAFT version, no fake
 * Business, Workspace or Location. Every route here runs under the
 * EnterBlueprintMode middleware (Blueprint Safety Mode), and every write goes
 * through BlueprintWorkspaceService -> NicheBlueprintPublisher. Thin by design.
 */
class NicheBlueprintWorkspaceController extends AdminBaseController
{
    public function __construct(private readonly BlueprintWorkspaceService $workspace) {}

    public function show(NicheBlueprint $blueprint, ?string $surface = null): View|RedirectResponse
    {
        $surface ??= BlueprintSurfaces::keys()[0];
        abort_unless(BlueprintSurfaces::has($surface), 404);

        $draft = $this->workspace->existingDraft($blueprint);

        if ($draft === null) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)
                ->with('flash_error', 'Enter the Blueprint Workspace first to start a draft.');
        }

        $grouped = $this->workspace->bySurface($draft);

        return view('admin.niche-blueprints.workspace.surface', [
            'blueprint' => $blueprint,
            'draft' => $draft,
            'published' => $this->workspace->published($blueprint),
            'surface' => $surface,
            'surfaces' => BlueprintSurfaces::ALL,
            'counts' => array_map('count', $grouped),
            'rows' => $grouped[$surface],
            'definitions' => $this->workspace->definitionsForSurface($surface),
            'breadcrumbs' => [
                ['link' => route('admin.niche-blueprints.index'), 'name' => 'Niche Blueprints'],
                ['link' => route('admin.niche-blueprints.show', $blueprint), 'name' => $blueprint->display_name],
                ['name' => 'Blueprint Workspace'],
            ],
        ]);
    }

    /** Starts (or resumes) the draft — a POST because it can create a version. */
    public function enter(NicheBlueprint $blueprint): RedirectResponse
    {
        try {
            $this->workspace->draftFor($blueprint, (int) Auth::id());
        } catch (BlueprintAuthoringException|InvalidArgumentException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.workspace.show', [$blueprint, BlueprintSurfaces::keys()[0]]);
    }

    public function storeComponent(Request $request, NicheBlueprint $blueprint, string $surface): RedirectResponse
    {
        abort_unless(BlueprintSurfaces::has($surface), 404);
        $type = (string) $request->input('component_type');
        abort_unless(isset($this->workspace->definitionsForSurface($surface)[$type]), 422);

        return $this->guarded($blueprint, $surface, function () use ($request, $blueprint, $type): string {
            $draft = $this->workspace->existingDraft($blueprint) ?? throw new InvalidArgumentException('Enter the Blueprint Workspace first to start a draft.');
            $this->workspace->saveComponent((int) Auth::id(), $draft, $type, null, $request->input('c', []));

            return 'Added to the draft.';
        }, $request);
    }

    public function updateComponent(Request $request, NicheBlueprint $blueprint, string $surface, NicheBlueprintComponent $component): RedirectResponse
    {
        abort_unless(BlueprintSurfaces::has($surface), 404);
        abort_unless((int) $component->blueprint_id === (int) $blueprint->id, 404);

        return $this->guarded($blueprint, $surface, function () use ($request, $blueprint, $component): string {
            $draft = $this->workspace->existingDraft($blueprint) ?? throw new InvalidArgumentException('Enter the Blueprint Workspace first to start a draft.');
            abort_unless((int) $component->blueprint_version_id === (int) $draft->id, 404);
            $this->workspace->saveComponent((int) Auth::id(), $draft, (string) $component->component_type, $component, $request->input('c', []));

            return 'Draft saved.';
        }, $request);
    }

    public function destroyComponent(NicheBlueprint $blueprint, string $surface, NicheBlueprintComponent $component): RedirectResponse
    {
        abort_unless(BlueprintSurfaces::has($surface), 404);
        abort_unless((int) $component->blueprint_id === (int) $blueprint->id, 404);

        return $this->guarded($blueprint, $surface, function () use ($component): string {
            $this->workspace->removeComponent((int) Auth::id(), $component);

            return 'Removed from the draft.';
        });
    }

    public function saveDraft(Request $request, NicheBlueprint $blueprint): RedirectResponse
    {
        return $this->guarded($blueprint, (string) $request->input('surface', BlueprintSurfaces::keys()[0]), function () use ($request, $blueprint): string {
            $draft = $this->workspace->existingDraft($blueprint) ?? throw new InvalidArgumentException('Enter the Blueprint Workspace first to start a draft.');
            $this->workspace->saveDraftNotes((int) Auth::id(), $draft, $request->input('notes'));

            return "Draft v{$draft->version_number} saved.";
        });
    }

    public function publish(NicheBlueprint $blueprint, \App\Library\NicheBlueprint\NicheBlueprintPublisher $publisher): RedirectResponse
    {
        try {
            $draft = $this->workspace->existingDraft($blueprint);

            if ($draft === null) {
                return back()->with('flash_error', 'There is no draft to publish.');
            }

            $published = $publisher->publishVersion((int) Auth::id(), $draft);
        } catch (BlueprintAuthoringException|UnknownBlueprintComponentTypeException|InvalidArgumentException $e) {
            return back()->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)
            ->with('flash_success', "Version {$published->version_number} published. New Businesses in this niche will receive it.");
    }

    public function exit(NicheBlueprint $blueprint): RedirectResponse
    {
        return redirect()->route('admin.niche-blueprints.show', $blueprint);
    }

    /** Runs a write, turning refusals into a flash back to the same surface with the input kept. */
    private function guarded(NicheBlueprint $blueprint, string $surface, callable $write, ?Request $request = null): RedirectResponse
    {
        try {
            $message = $write();
        } catch (BlueprintAuthoringException|UnknownBlueprintComponentTypeException|InvalidArgumentException $e) {
            $back = redirect()->route('admin.niche-blueprints.workspace.show', [$blueprint, $surface])->with('flash_error', $e->getMessage());

            return $request === null ? $back : $back->withInput();
        }

        return redirect()->route('admin.niche-blueprints.workspace.show', [$blueprint, $surface])->with('flash_success', $message);
    }
}
