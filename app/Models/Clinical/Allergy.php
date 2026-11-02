<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Allergy extends Model
{
    protected $table = 'cl_allergies';

    protected $fillable = ['patient_id', 'substance', 'reaction', 'severity', 'created_by'];
}
