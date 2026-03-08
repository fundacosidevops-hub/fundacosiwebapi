<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalStudy extends Model
{
    protected $fillable = ['catalog_service_id', 'description'];

    public function catalogServices()
    {
        return $this->belongsTo(CatalogServices::class);
    }
}
