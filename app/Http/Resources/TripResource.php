<?php

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trip
 */
class TripResource extends JsonResource
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
            'name' => $this->name,
            'order_id' => $this->order_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'start_address' => $this->start_address,
            'start_lat' => (float)$this->start_lat,
            'start_lng' => (float)$this->start_lng,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'meta' => $this->meta,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'driver' => $this->when($this->relationLoaded('driver'), function () {
                return $this->driver ? [
                    'id' => $this->driver->id,
                    'firstname' => $this->driver->firstname,
                    'lastname' => $this->driver->lastname,
                    'phone' => $this->driver->phone,
                    'img' => $this->driver->img,
                ] : null;
            }),
            'vehicle' => $this->when($this->relationLoaded('vehicle'), function () {
                return $this->vehicle ? [
                    'user_id' => $this->vehicle->user_id,
                    'type' => $this->vehicle->type,
                    'brand' => $this->vehicle->brand,
                    'model' => $this->vehicle->model,
                    'year' => $this->vehicle->year,
                    'color' => $this->vehicle->color,
                    'license_plate' => $this->vehicle->license_plate,
                ] : null;
            }),
            'locations' => TripLocationResource::collection($this->whenLoaded('locations')),
        ];
    }
} 