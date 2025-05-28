<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    protected $guarded = [];

    protected $casts = [
        'channels' => 'array',
        'groups'   => 'array',
        'stats'    => 'array',
        'custom_emails' => 'array',
    ];
} 