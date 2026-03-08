<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogServices extends Model
{
    protected $fillable = ['description'];

    public function medicalStudies()
    {
        return $this->hasMany(MedicalStudy::class);
    }
}
