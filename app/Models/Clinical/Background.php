<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Background extends Model
{
    protected $table = 'cl_backgrounds';

    protected $fillable = ['patient_id', 'blood_type', 'personal', 'family', 'surgical', 'gyneco', 'habits', 'other', 'updated_by'];
}
