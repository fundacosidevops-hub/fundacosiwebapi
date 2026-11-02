<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $table = 'acc_periods';

    protected $fillable = ['year', 'month', 'status', 'closed_at', 'closed_by'];

    protected $casts = ['closed_at' => 'datetime'];
}
