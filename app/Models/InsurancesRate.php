<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsurancesRate extends Model
{
    protected $fillable = ['description'];

    public function medialStudy()
    {
        return $this->belongsTo(MedicalStudy::class);
    }
}
