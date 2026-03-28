<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueueManager extends Model
{
    use HasFactory;

    protected $fillable = [
        'queue_code',
        'curr_number',
        'ticket',
        'status',
        'patient_id',
        'assign_user_id',
        'billing_type',
        'insurance_id',
        'catalog_services_id',
        'doctor_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function insurance()
    {
        return $this->belongsTo(Insurances::class);
    }

    public function catalogServices()
    {
        return $this->belongsTo(CatalogServices::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'assign_user_id');
    }
}
