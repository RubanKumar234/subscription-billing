<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    protected $table = 'usage_events';

    protected $guarded = [];

    public $timestamps = false;
}
