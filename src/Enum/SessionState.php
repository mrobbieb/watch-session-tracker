<?php

namespace App\Enum;

enum SessionState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Buffering = 'buffering';
    case Ended = 'ended';
}