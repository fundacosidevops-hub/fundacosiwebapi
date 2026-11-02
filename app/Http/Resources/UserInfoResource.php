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
            'medicalCatalogServices' => $this->medicalCatalogServicesByUser,
            'relationShipDependent' => $this->relationShipDependent,
            'dependent_of' => $this->dependent_of,
            'relationship_type' => $this->relationLoaded('relationShipType') ? $this->relationShipType : null,
            'holder' => $this->holderSummary(),
            'user_type' => $this->userType,
            'is_active' => $this->is_active,
            'roles' => $this->roles->pluck('name')->first(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'created_user' => $this->created_user,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Datos básicos del titular y de todos sus dependientes (la familia),
     * para cambiar rápido de paciente en facturación.
     */
    private function holderSummary(): ?array
    {
        if (! $this->relationLoaded('holder') || ! $this->holder) {
            return null;
        }
        $holder = $this->holder;

        return [
            'id' => $holder->id,
            'name' => $holder->name,
            'last_name' => $holder->last_name,
            'phone' => $holder->phone,
            'document_number' => $holder->document_number,
            'user_type_id' => $holder->user_type_id,
            'policy' => $holder->policy,
            'insurance' => $holder->insurance
                ? ['id' => $holder->insurance->id, 'description' => $holder->insurance->description]
                : null,
            'dependents' => $holder->relationShipDependent->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'last_name' => $d->last_name,
                'birth_date' => $d->birth_date,
                'user_type_id' => $d->user_type_id,
                'relation_ship_type' => $d->relationShipType,
            ])->values(),
        ];
    }
}
