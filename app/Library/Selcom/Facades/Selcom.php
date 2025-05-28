<?php

namespace App\Library\Selcom\Facades;

use Illuminate\Support\Facades\Facade;

class Selcom extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'Selcom';
    }
}