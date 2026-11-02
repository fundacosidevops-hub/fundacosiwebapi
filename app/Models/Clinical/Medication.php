<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    protected $table = 'cl_medications';

    protected $fillable = ['patient_id', 'name', 'dose', 'frequency', 'active', 'notes', 'created_by'];
    protected $casts = ['active' => 'boolean'];
}
