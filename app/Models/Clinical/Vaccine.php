<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Vaccine extends Model
{
    protected $table = 'cl_vaccines';

    protected $fillable = ['patient_id', 'name', 'applied_on', 'dose', 'notes', 'created_by'];
    protected $casts = ['applied_on' => 'date:Y-m-d'];
}
