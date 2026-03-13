<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Insurances extends Model
{
    protected $fillable = [
        'rnc',
        'description',
        'abbreviation',
        'is_active',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function rates()
    {
        return $this->hasMany(InsurancesRate::class);
    }
}
