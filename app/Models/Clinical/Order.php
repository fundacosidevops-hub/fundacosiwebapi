<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'cl_orders';

    protected $fillable = ['encounter_id', 'type', 'description', 'notes', 'position'];
}
