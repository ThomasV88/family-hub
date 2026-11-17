<?php

namespace App\Medical\Ai\Eval;

use RuntimeException;

/** Ends an evaluation run on purpose. The message is the stop reason (max_calls, quota, auth, projected_over_cap). */
final class EvalStopped extends RuntimeException {}
