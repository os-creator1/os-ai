<?php

namespace App\Enums\Business;

enum OnboardingStep: string
{
    case Goals = 'goals';
    case Business = 'business';
    case Location = 'location';
    case Services = 'services';
    case Assets = 'assets';
    case Analysis = 'analysis';
    case Results = 'results';
    case Complete = 'complete';
}
