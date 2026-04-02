<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'avatar' => $this->avatar,
            'email' => $this->email,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'birth_date' => $this->birth_date,
            'policy' => $this->policy,
            'position' => $this->position,
            'nationalities' => $this->nationalities,
            'marital_statuses' => $this->maritalStatus,
            'insurance' => $this->insurance,
            'userLocations' => $this->userLocations,
            'document_types' => $this->documentType,
            'document_number' => $this->document_number,
            'user_type' => $this->userType,
            'is_active' => $this->is_active,
            'roles' => $this->roles->pluck('name')->first(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'created_user' => $this->created_user,
            'created_at' => $this->created_at,
        ];
    }
}
