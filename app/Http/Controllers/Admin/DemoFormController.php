<?php

namespace App\Http\Controllers\Admin;

use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\DemoBooking\Form\DemoFormSchema;
use App\Library\Marketing\MarketingSite;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Platform Owner → Marketing Website → Demo Form. Edits the questions on the public /book-demo form. Inside the
 * EnsureUserIsAdministrator route group (the Platform Owner boundary) and behind 'access backend', like the
 * other Platform Owner configuration screens. Changes are saved as a draft and only go live on Publish; every
 * publish is written to the Platform Owner audit trail.
 */
class DemoFormController extends AdminBaseController
{
    public function __construct(
        private readonly DemoFormRepository $forms,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    public function edit(): View
    {
        $this->authorize('access backend');

        return view('admin.platform-owner.demo-form', [
            'fields' => old('fields') !== null ? (array) json_decode((string) old('fields'), true) : $this->forms->draft(),
            'hasDraft' => $this->forms->hasDraft(),
            'types' => [
                'short_text' => 'Short text', 'long_text' => 'Long text', 'email' => 'Email', 'phone' => 'Phone',
                'dropdown' => 'Dropdown', 'checkboxes' => 'Checkboxes', 'radio' => 'Radio selection',
            ],
            'breadcrumbs' => [
                ['link' => \App\Helpers\Helper::adminHomeUrl(), 'name' => __('locale.menu.Dashboard')],
                ['name' => 'Marketing Website — Demo Form'],
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->authorize('access backend');

        $result = $this->normalized($request);
        if ($result['errors'] !== []) {
            return back()->withInput()->withErrors(['fields' => $result['errors']]);
        }
        $this->forms->saveDraft($result['fields'], (int) Auth::id());

        return redirect()->route('admin.platform-owner.demo-form')->with(['status' => 'success', 'message' => 'Draft saved. It is not live until you publish.']);
    }

    public function publish(Request $request): RedirectResponse
    {
        $this->authorize('access backend');

        // Publishing saves what is on screen first, so the owner publishes what they see.
        $result = $this->normalized($request);
        if ($result['errors'] !== []) {
            return back()->withInput()->withErrors(['fields' => $result['errors']]);
        }
        $this->forms->saveDraft($result['fields'], (int) Auth::id());
        $id = $this->forms->publish((int) Auth::id());
        if ($id !== null) {
            $this->audit->record((int) Auth::id(), 'demo_form.published', 'demo_form', (string) $id,
                'Published the Book a Demo form ('.count($result['fields']).' fields).',
                null, ['version' => $id, 'fields' => array_column($result['fields'], 'key')]);
        }

        return redirect()->route('admin.platform-owner.demo-form')->with(['status' => 'success', 'message' => 'Published. The new form is live on /book-demo.']);
    }

    public function discard(): RedirectResponse
    {
        $this->authorize('access backend');
        $this->forms->discardDraft();

        return redirect()->route('admin.platform-owner.demo-form')->with(['status' => 'success', 'message' => 'Draft discarded. The editor now shows the live form.']);
    }

    /** The saved draft, rendered by the public form's own partial inside the public card styles. */
    public function preview(MarketingSite $site): View
    {
        $this->authorize('access backend');

        return view('marketing.book-demo.preview', [
            'mg' => $site,
            'page' => 'book-demo',
            'meta' => $site->meta('book-demo') + ['robots' => 'noindex'],
            'formFields' => DemoFormSchema::visible($this->forms->draft()),
        ]);
    }

    /** @return array{fields: array<int, array<string, mixed>>, errors: array<int, string>} */
    private function normalized(Request $request): array
    {
        $decoded = json_decode((string) $request->input('fields'), true);

        return is_array($decoded)
            ? DemoFormSchema::normalize($decoded)
            : ['fields' => [], 'errors' => ['The form could not be read. Reload the page and try again.']];
    }
}
