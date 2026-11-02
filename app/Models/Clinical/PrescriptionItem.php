<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PrescriptionItem extends Model
{
    protected $table = 'cl_prescription_items';

    protected $fillable = ['encounter_id', 'medication', 'presentation', 'dose', 'frequency', 'duration', 'route', 'quantity', 'instructions', 'position'];
}
