<?php

namespace App\Domain\Message;

enum MessageDirection: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
}