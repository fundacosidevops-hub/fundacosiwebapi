<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalCatalogServices extends Model
{
    protected $fillable = ['catalog_services_id', 'users_id'];

    public function catalogServices()
    {
        return $this->belongsTo(CatalogServices::class);
    }

    public function users()
    {
        return $this->belongsTo(User::class);
    }
}
