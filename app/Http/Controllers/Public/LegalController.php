<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Legal\LegalDocuments;
use App\Library\Marketing\MarketingSite;
use Illuminate\Contracts\View\View;

/**
 * The public MotionGrove Legal Center. Read-only. Served only on the marketing hosts, so a customer's
 * custom domain or an Agency site can never present MotionGrove's legal documents as its own.
 */
class LegalController extends Controller
{
    public function __construct(private readonly MarketingSite $site, private readonly LegalDocuments $documents)
    {
    }

    public function index(): View
    {
        abort_unless($this->site->servesRequest(request()), 404);

        return view('marketing.legal.index', [
            'mg' => $this->site,
            'page' => 'legal',
            'meta' => [
                'title' => 'Legal Center | MotionGrove',
                'description' => 'The terms, policies and addenda that govern the use of MotionGrove.',
                'canonical' => url('legal'),
                'image' => $this->site->asset((string) $this->site->get('site.og_image')),
            ],
            'primary' => $this->documents->tier('primary'),
            'supporting' => $this->documents->tier('supporting'),
            'company' => app(\App\Library\Legal\LegalCompany::class)->current(),
        ]);
    }

    public function show(string $slug): View
    {
        abort_unless($this->site->servesRequest(request()), 404);
        abort_unless($this->documents->meta($slug) !== null, 404);

        $doc = $this->documents->render($slug);

        return view('marketing.legal.show', [
            'mg' => $this->site,
            'page' => 'legal',
            'meta' => [
                'title' => $doc['meta']['seo_title'],
                'description' => $doc['meta']['description'],
                'canonical' => url($slug),
                'image' => $this->site->asset((string) $this->site->get('site.og_image')),
            ],
            'doc' => $doc,
            'company' => $doc['company'],
            'related' => $this->documents->all(),
        ]);
    }
}
