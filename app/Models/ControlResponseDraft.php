<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ControlResponseDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'control_id',
        'supervisor_id',
        'cleaner_id',
        'apartment_id',
        'is_assigned',
        'previous_cleaner',
        'cleaning_date',
        'inspection_date',
        'comment',
        'responses',
        'schema_snapshot',
    ];

    protected $casts = [
        'responses' => 'array',
        'schema_snapshot' => 'array',
        'is_assigned' => 'boolean',
        'cleaning_date' => 'date',
        'inspection_date' => 'date',
    ];

    public function control()
    {
        return $this->belongsTo(Control::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function cleaner()
    {
        return $this->belongsTo(User::class, 'cleaner_id');
    }
}