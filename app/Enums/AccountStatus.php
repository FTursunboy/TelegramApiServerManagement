<?php

namespace App\Enums;

enum AccountStatus: string
{
    case CREATING = 'creating';
    case WAITING_CODE = 'waiting_code';
    case WAITING_2FA = 'waiting_2fa';
    case READY = 'ready';
    case ERROR = 'error';
    case STOPPED = 'stopped';
}