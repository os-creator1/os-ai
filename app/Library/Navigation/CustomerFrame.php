<?php

namespace App\Library\Navigation;

/**
 * Customer Experience contract §8.1 — the shell renders exactly one of two
 * frames at any moment. The Account frame is the Agency/Workspace-level
 * frame (client accounts, staff, plan); the Business frame is everything
 * scoped to one selected Business. A Business route never highlights an
 * Account-frame item and vice versa (T-CTX-5).
 */
enum CustomerFrame: string
{
    case Account = 'account';
    case Business = 'business';
}
