<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.4. `custom` is the contract's one flagged
 * reasonable-default exercise; deleting it is a one-line change to 12.B.
 */
enum DocumentLineItemSource: string
{
    case Catalog = 'catalog';
    case Custom = 'custom';
}
