<?php

namespace App\Library\Navigation;

/**
 * How the selected Business of a CustomerContext was chosen (contract §3 of
 * the Slice 1B brief, "Canonical context resolution").
 *
 * - Route: the request itself carried an authorized Workspace/Business pair
 *   that the owning controller resolves and enforces (the shell only
 *   displays it).
 * - ViewAs: an active View-as-client session narrows the context to the
 *   viewed Business (contract §5.5).
 * - Preference: the remembered navigation preference, re-authorized through
 *   WorkspaceManager::userCanAccessBusiness() on this request.
 * - Sole: exactly one selectable Business exists, so the choice is
 *   unambiguous; it is still re-authorized canonically.
 * - None: no Business is selected — the Account frame is shown and an
 *   explicit selection is required when more than one choice exists.
 */
enum ContextSource: string
{
    case Route = 'route';
    case ViewAs = 'view_as';
    case Preference = 'preference';
    case Sole = 'sole';
    case None = 'none';
}
