<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use SoftDeletes;

    protected $table = 'cl_attachments';

    protected $fillable = [
        'patient_id', 'encounter_id', 'category', 'title', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    protected $hidden = ['path'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
