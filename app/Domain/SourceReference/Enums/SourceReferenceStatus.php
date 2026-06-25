<?php

namespace App\Domain\SourceReference\Enums;

enum SourceReferenceStatus: string
{
    case New = 'NEW';
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
