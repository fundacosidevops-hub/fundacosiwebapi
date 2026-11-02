<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ArsPayment extends Model
{
    protected $table = 'acc_ars_payments';

    protected $fillable = [
        'claim_id', 'date', 'amount_paid', 'amount_glosa', 'bank_account_id', 'reference', 'items', 'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'amount_paid' => 'decimal:2',
        'amount_glosa' => 'decimal:2',
        'items' => 'array',
    ];

    public function claim()
    {
        return $this->belongsTo(ArsClaim::class, 'claim_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
