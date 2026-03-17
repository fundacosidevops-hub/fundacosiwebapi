<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogServices extends Model
{
    protected $fillable = ['description'];

    public function medicalStudies()
    {
        return $this->hasMany(MedicalStudies::class);
    }

    public function medicalCatalogServices()
    {
        return $this->hasMany(MedicalCatalogServices::class);
    }

    public function invoice()
    {
        return $this->hasMany(Invoice::class);
    }
}
