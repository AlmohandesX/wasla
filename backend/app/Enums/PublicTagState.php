<?php

namespace App\Enums;

enum PublicTagState: string
{
    case Unclaimed = 'unclaimed';
    case Active = 'active';
    case Neutral = 'neutral';
}
