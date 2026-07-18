<?php

namespace App\Enums\Business;

enum BusinessServiceMode: string
{
    case Storefront = 'storefront';
    case ServiceArea = 'service_area';
    case Hybrid = 'hybrid';
    case Online = 'online';
}
