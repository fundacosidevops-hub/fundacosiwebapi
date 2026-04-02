<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasRoles;

    protected $guard_name = 'api';

    protected $fillable = [
        'avatar',
        'name',
        'last_name',
        'email',
        'position_id',
        'password',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'password',
    ];

    public function position()
    {
        return $this->belongsTo(Positions::class);
    }

    public function maritalStatus()
    {
        return $this->belongsTo(MaritalStatuses::class);
    }

    public function insurance()
    {
        return $this->belongsTo(Insurances::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function nationalities()
    {
        return $this->belongsTo(Nationalities::class);
    }

    public function userLocations()
    {
        return $this->belongsTo(UserLocations::class);
    }

    public function userType()
    {
        return $this->belongsTo(UserType::class);
    }

    public function catalogServices()
    {
        return $this->belongsTo(CatalogServices::class);
    }

    public function medicalCatalogServices()
    {
        return $this->belongsTo(MedicalCatalogServices::class);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
