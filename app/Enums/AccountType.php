<?php

namespace App\Enums;

enum AccountType: string
{
    case USER = 'user';
    case BOT = 'bot';
}