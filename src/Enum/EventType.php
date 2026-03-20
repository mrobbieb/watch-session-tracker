<?php

namespace App\Enum;

enum EventType: string
{
    case Start = 'start';
    case Heartbeat = 'heartbeat';
    case Pause = 'pause';
    case Resume = 'resume';
    case Seek = 'seek';
    case QualityChange = 'quality_change';
    case BufferStart = 'buffer_start';
    case BufferEnd = 'buffer_end';
    case End = 'end';
}