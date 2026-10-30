<?php

namespace App\Enums;

enum TaskCompletion: string
{
    case Pending = 'pending';
    case Done = 'done';
    case NotDone = 'not_done';

    public function label(): string
    {
        return __('enums.task_completions.'.$this->value);
    }
}
