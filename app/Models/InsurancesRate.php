<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsurancesRate extends Model
{
    protected $fillable = [
        'medical_studies_id',
        'insurances_id',
        'percentage',
    ];

    public function medicalStudies()
    {
        return $this->belongsTo(MedicalStudies::class, 'medical_studies_id');
    }

    public function insurance()
    {
        return $this->belongsTo(Insurances::class, 'insurances_id');
    }
}
