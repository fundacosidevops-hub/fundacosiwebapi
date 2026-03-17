<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItems extends Model
{
    use HasFactory;

    protected $table = 'in_invoice_items';

    protected $fillable = [
        'invoice_id',
        'medical_study_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'insurance_coverage',
        'patient_amount',
        'total',
        'status',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'insurance_coverage' => 'decimal:2',
        'patient_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function invoice()
    {
        return $this->belongsTo(Invoices::class);
    }

    public function medicalStudy()
    {
        return $this->belongsTo(MedicalStudies::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function calculateTotal()
    {
        return ($this->unit_price * $this->quantity) - $this->discount;
    }
}
