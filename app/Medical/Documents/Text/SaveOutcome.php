<?php

namespace App\Medical\Documents\Text;

/**
 * What DocumentTextStore::save did with a recognised text. Only Saved wrote a row. Kept means a current text of another run
 * holds more pages than this partial batch and stays as it is (the run goes on in memory).
 */
enum SaveOutcome
{
    case Saved;
    case ConsentOff;
    case Archived;
    case Missing;
    case Disabled;
    case NotRunning;
    case Kept;
}
