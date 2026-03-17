<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'insurance_id',
        'status_id',
        'authorization_number',
        'billing_type',
        'invoice_number',
        'subtotal',
        'discount',
        'tax',
        'total',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function insurance()
    {
        return $this->belongsTo(Insurances::class);
    }

    public function status()
    {
        return $this->belongsTo(InvoiceStatus::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItems::class);
    }

    public function payments()
    {
        return $this->hasMany(Payments::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function getPaidAmount()
    {
        return $this->payments()->sum('amount');
    }

    public function getBalance()
    {
        return $this->total - $this->getPaidAmount();
    }

    public function isPaid()
    {
        return $this->getBalance() <= 0;
    }
}
