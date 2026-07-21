<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model; 

class MedicalAssistance extends Model
{
    protected $fillable = [
        'doctor_id',
        'start_time',
        'end_time',
        'patient_quantity',
        'next_date',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id', 'id');
    }
}