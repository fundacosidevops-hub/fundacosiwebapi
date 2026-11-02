<?php

namespace App\Models\Accounting;

use App\Models\Payments;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    protected $table = 'acc_cash_sessions';

    protected $fillable = [
        'user_id', 'location_id', 'status', 'opened_at', 'opening_amount', 'closed_at', 'closed_by',
        'expected_cash', 'counted_cash', 'difference', 'totals_by_method', 'payments_count', 'notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_amount' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'difference' => 'decimal:2',
        'totals_by_method' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments()
    {
        return $this->hasMany(Payments::class, 'cash_session_id');
    }
}
