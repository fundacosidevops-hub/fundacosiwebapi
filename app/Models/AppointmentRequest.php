<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentRequest extends Model
{
    public const STATUSES = ['pending', 'contacted', 'scheduled', 'cancelled', 'no_answer'];

    protected $fillable = [
        'code',
        'patient_name',
        'phone',
        'catalog_services_id',
        'service_name',
        'doctor_id',
        'doctor_name',
        'preferred_date',
        'preferred_time',
        'reason',
        'status',
        'staff_notes',
        'handled_by',
        'handled_at',
        'source',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public function catalogService()
    {
        return $this->belongsTo(CatalogServices::class, 'catalog_services_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
