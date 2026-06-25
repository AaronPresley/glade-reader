<?php

namespace App\Domain\SourceReference\Enums;

enum SourceReferenceStepStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
