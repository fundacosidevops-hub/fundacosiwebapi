<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NcfSequences extends Model
{
    protected $table = 'in_ncf_sequences';

    protected $fillable = [
        'type',
        'prefix',
        'from_number',
        'to_number',
        'current_number',
        'expires_at',
        'is_active',
    ];
}
