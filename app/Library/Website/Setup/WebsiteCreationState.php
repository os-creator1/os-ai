<?php

namespace App\Library\Website\Setup;

use App\Models\QuestionnaireResponse;
use App\Models\Website;

/**
 * @see WebsiteCreationStateResolver
 */
final readonly class WebsiteCreationState
{
    public function __construct(
        public WebsiteCreationStage $stage,
        public ?Website $website,
        public ?QuestionnaireResponse $response,
    ) {
    }
}
