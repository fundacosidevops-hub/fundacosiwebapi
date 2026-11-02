<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VitalSign extends Model
{
    protected $table = 'cl_vital_signs';

    protected $fillable = ['patient_id', 'encounter_id', 'weight_kg', 'height_cm', 'bmi', 'temperature_c', 'systolic', 'diastolic', 'heart_rate', 'respiratory_rate', 'spo2', 'glucose', 'notes', 'taken_by', 'taken_at'];
    protected $casts = ['taken_at' => 'datetime', 'weight_kg' => 'float', 'height_cm' => 'float', 'bmi' => 'float', 'temperature_c' => 'float'];

    public function taker()
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
