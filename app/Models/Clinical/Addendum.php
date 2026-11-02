<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Addendum extends Model
{
    protected $table = 'cl_addenda';

    protected $fillable = ['encounter_id', 'text', 'created_by'];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
