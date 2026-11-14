<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademySearchStatistic extends Model
{
    protected $primaryKey = 'query_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
