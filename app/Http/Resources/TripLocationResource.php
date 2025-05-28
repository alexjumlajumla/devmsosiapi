<?php

namespace App\Http\Resources;

use App\Models\TripLocation;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TripLocation
 */
class TripLocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'address' => $this->address,
            'lat' => (float)$this->lat,
            'lng' => (float)$this->lng,
            'sequence' => $this->sequence,
            'eta_minutes' => $this->eta_minutes,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 