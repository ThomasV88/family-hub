<?php

namespace App\Shopping;

enum MemberRole: string
{
    case Contributor = 'contributor';
    case Viewer = 'viewer';
}
