<?php

namespace App\Http\Resources;

use App\Models\DeliveryManDeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryManDeliveryZoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var DeliveryManDeliveryZone|JsonResource $this */
        return $this->address ?? [];
    }
}
