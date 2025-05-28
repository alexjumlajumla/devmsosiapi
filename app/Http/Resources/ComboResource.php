<?php
declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Combo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComboResource extends JsonResource
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  Request $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var Combo|JsonResource $this */
		$locales = $this->relationLoaded('translations') ?
			$this->translations->pluck('locale')->toArray() : null;

        return [
            'id'            => $this->when($this->id,         $this->id),
            'active'        => $this->when($this->active,     $this->active),
            'img'           => $this->when($this->img,     	  $this->img),
            'price'         => $this->when($this->shop_id,    $this->shop_id),
            'address'       => $this->when($this->active,     $this->active),
            'location'      => $this->when($this->expired_at, $this->expired_at),
            'created_at'    => $this->when($this->created_at, $this->created_at?->format('Y-m-d H:i:s') . 'Z'),
            'updated_at'    => $this->when($this->updated_at, $this->updated_at?->format('Y-m-d H:i:s') . 'Z'),

			'locales'       => $this->when($locales, $locales),
			'translation'   => TranslationResource::make($this->whenLoaded('translation')),
            'translations'  => TranslationResource::collection($this->whenLoaded('translations')),
            'stocks'  		=> StockResource::collection($this->whenLoaded('stocks')),
            'galleries'		=> GalleryResource::collection($this->whenLoaded('galleries')),
        ];
    }
}
