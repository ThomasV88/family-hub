<?php

namespace App\Http\Requests\Todo;

/** Same rules as creating a task; the title stays required. Unknown keys never reach validated(). */
class UpdateTaskRequest extends StoreTaskRequest {}
