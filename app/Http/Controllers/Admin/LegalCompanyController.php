<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Settings\UpdateLegalCompanyRequest;
use App\Library\Legal\LegalCompany;
use Illuminate\Http\RedirectResponse;

/**
 * Platform Owner → Settings → Legal Company Information. Inside the EnsureUserIsAdministrator group;
 * UpdateLegalCompanyRequest and LegalCompany::update() each re-check the Platform Owner rule.
 */
class LegalCompanyController extends AdminBaseController
{
    public function __construct(private readonly LegalCompany $company)
    {
    }

    public function save(UpdateLegalCompanyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $result = $this->company->update((int) $request->user()->id, $data, (int) $data['base_revision']);

        return redirect()->route('admin.settings.general', ['tab' => 'legal'])->with([
            'status' => 'success',
            'message' => $result['changed']
                ? 'Legal company information saved. Public legal pages now show the new details.'
                : 'No changes to save: the legal company information is already up to date.',
        ]);
    }
}
