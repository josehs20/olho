<?php

namespace App\Sources;

enum SourceRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Skipped = 'skipped';
}
