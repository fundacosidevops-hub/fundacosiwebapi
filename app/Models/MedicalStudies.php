<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalStudies extends Model
{
    protected $fillable = ['catalog_services_id', 'description'];

    public function catalogServices()
    {
        return $this->belongsTo(CatalogServices::class);
    }

    public function insurancesRate()
    {
        return $this->belongsTo(InsurancesRate::class);
    }
}
