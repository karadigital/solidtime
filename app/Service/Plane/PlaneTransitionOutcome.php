<?php

declare(strict_types=1);

namespace App\Service\Plane;

enum PlaneTransitionOutcome: string
{
    case Moved = 'moved';
    case AlreadyInState = 'already_in_state';
    case WorkItemClosed = 'work_item_closed';
    case StateNotFound = 'state_not_found';
    case WorkItemNotFound = 'work_item_not_found';
}
