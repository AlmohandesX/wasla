<?php

namespace App\Enums;

enum ModerationDecision: string
{
    case Allow = 'allow';
    case AllowFlagged = 'allow_flagged';
    case Block = 'block';
}
