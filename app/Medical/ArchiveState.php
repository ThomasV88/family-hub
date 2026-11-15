<?php

namespace App\Medical;

/**
 * Which archive state a scoped medical query reads. Active is the default everywhere: archived people and records are
 * hidden from lists, exports, the emergency card, reminders and AI. Only is for the Archive section and restore, Any for
 * the few checks that must count every stored row (the per-person document cap).
 */
enum ArchiveState
{
    case Active;
    case Only;
    case Any;
}
