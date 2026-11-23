<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FocusV2MediaUpload extends Model
{
    protected $table = 'focus_v2_media_uploads';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'control_id', 'supervisor_id', 'draft_id', 'control_response_id', 'run_id',
        'schema_version', 'room_index', 'question_index', 'original_name',
        'declared_mime', 'size_bytes', 'chunk_bytes', 'total_chunks',
        'status', 'stored_path', 'actual_mime',
    ];

    protected $casts = [
        'room_index' => 'integer',
        'question_index' => 'integer',
        'size_bytes' => 'integer',
        'chunk_bytes' => 'integer',
        'total_chunks' => 'integer',
    ];
}
