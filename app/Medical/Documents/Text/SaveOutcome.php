<?php

namespace App\Medical\Documents\Text;

/** What DocumentTextStore::save did with a recognised text. Only Saved wrote a row. */
enum SaveOutcome
{
    case Saved;
    case ConsentOff;
    case Archived;
    case Missing;
}
