<?php

namespace Tests\Feature\Workspace\Support;

use App\Library\Workspace\Migration\WorkspaceBackfillV1;

/**
 * Test-only subclass that shrinks the internal cursor page size (protected
 * for exactly this purpose) so a page-boundary crossing can be exercised
 * without inserting hundreds of rows. Production always uses the default.
 */
class SmallPageWorkspaceBackfillV1 extends WorkspaceBackfillV1
{
    protected const CHUNK_SIZE = 2;
}
