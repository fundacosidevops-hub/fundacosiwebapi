<?php

namespace App\Models\Accounting;

use App\Models\Insurances;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ArsClaim extends Model
{
    protected $table = 'acc_ars_claims';

    protected $fillable = [
        'number', 'insurance_id', 'period_from', 'period_to', 'status', 'total_claimed',
        'total_paid', 'total_glosa', 'sent_at', 'ars_reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'total_claimed' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'total_glosa' => 'decimal:2',
    ];

    public function insurance()
    {
        return $this->belongsTo(Insurances::class, 'insurance_id');
    }

    public function items()
    {
        return $this->hasMany(ArsClaimItem::class, 'claim_id');
    }

    public function payments()
    {
        return $this->hasMany(ArsPayment::class, 'claim_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
