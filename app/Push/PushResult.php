<?php

namespace App\Push;

enum PushResult
{
    case Success;
    /** The push service says the subscription is gone (404/410): delete it. */
    case Expired;
    case Failed;
}
