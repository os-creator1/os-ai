<?php

namespace App\Library\NicheBlueprint\Adapters;

/**
 * Marker: the component is a reusable suggestion, never an automatically
 * installed Business row. The installer neither calls `install()` nor records
 * an installation for it, so no run (creation, plan assignment, recovery) can
 * create customer data from it.
 */
interface TemplateOnlyComponent {}
