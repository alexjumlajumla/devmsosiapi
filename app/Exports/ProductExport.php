<?php

namespace App\Exports;

use App\Models\Discount;
use App\Models\Language;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductExport extends BaseExport implements FromCollection, WithHeadings
{
    protected array $filter;

    public function __construct(array $filter, private ?Collection $languages = null, private mixed $products = [])
    {
        $this->filter 	 = $filter;
		$this->languages = Language::where('active', true)->pluck('locale');
		$this->products  = Product::filter($this->filter)
			->with([
				'discounts' => fn($q) => $q->where([
					['start', '<=', today()],
					['end', '>=', today()],
					['active', 1]
				]),
				'properties',
				'tags.translations',
				'stocks.addons.addon.stock',
				'stocks.addons.addon.translations',
				'stocks.addons.addon.unit.translations',
				'stocks.stockExtras',
			])
			->orderBy('id')
			->get();
    }

    public function collection(): Collection
    {
        return $this->products->map(fn(Product $product) => $this->tableBody($product));
    }

    public function headings(): array
    {
		$headings = [
			'Id',
			'Shop Id',
			'Brand Id',
			'Category Id',
			'Tax',
			'Active',
			'Status',
			'Min Qty',
			'Max Qty',
			'Images',
			'Vegetarian',
			'Kcal',
			'Carbs',
			'Protein',
			'Fats',
			'Addon',
			'Discount price',
			'Discount type',
			'Discount active',
			'Discount expired at',
			'Properties',
			'Interval',
			'Unit',
			'Title',
			'Description',
		];

		foreach ($this->products as $product) {

			foreach ($product->stocks as $key => $stock) {

				$headings["stock $key extras"] 	 = "Stock $key extras";
				$headings["stock $key price"] 	 = "Stock $key price";
				$headings["stock $key quantity"] = "Stock $key quantity";
				$headings["stock $key sku"] 	 = "Stock $key sku";
				$headings["stock $key addon"] 	 = "Stock $key addon";
				$headings["stock $key img"] 	 = "Stock $key img";

				/** @var Stock $stock */
				foreach ($stock->addons as $addonKey => $addon) {

					$addon 		= $addon?->addon;
					$addonStock = $addon?->stock;

					if (empty($addon)) {
						continue;
					}

					if (empty($addonStock)) {
						continue;
					}

					$headings["stock $key addon $addonKey id"] 	 	 = "Stock $key addon $addonKey id";
					$headings["stock $key addon $addonKey title"] 	 = "Stock $key addon $addonKey title";
					$headings["stock $key addon $addonKey desc"] 	 = "Stock $key addon $addonKey desc";
					$headings["stock $key addon $addonKey unit"] 	 = "Stock $key addon $addonKey unit";
					$headings["stock $key addon $addonKey interval"] = "Stock $key addon $addonKey interval";
					$headings["stock $key addon $addonKey tax"] 	 = "Stock $key addon $addonKey tax";
					$headings["stock $key addon $addonKey active"] 	 = "Stock $key addon $addonKey active";
					$headings["stock $key addon $addonKey status"] 	 = "Stock $key addon $addonKey status";
					$headings["stock $key addon $addonKey price"] 	 = "Stock $key addon $addonKey price";
					$headings["stock $key addon $addonKey quantity"] = "Stock $key addon $addonKey quantity";
					$headings["stock $key addon $addonKey sku"] 	 = "Stock $key addon $addonKey sku";
					$headings["stock $key addon $addonKey addon"] 	 = "Stock $key addon $addonKey addon";
					$headings["stock $key addon $addonKey img"] 	 = "Stock $key addon $addonKey img";

				}

			}

		}

		return $headings;
    }

    private function tableBody(Product $product): array
    {
		/** @var Discount $discount */
		$discount = $product->discounts?->first();

		$properties = '';

		$lastKey = array_key_last($product->properties?->toArray());

		foreach ($product->properties as $key => $property) {
			$properties .= "$property->locale:$property->key,$property->value" . ($key !== $lastKey ? ';' : '');
		}

		$body = [
			'id'       				=> $product->id,
			'shop_id'  				=> $product->shop_id,
			'category_id'    		=> $product->category_id,
			'brand_id'    			=> $product->brand_id,
			'tax'         			=> $product->tax ?? 0,
			'active'      			=> $product->active ? 'active' : 'inactive',
			'status'      			=> $product->status ?? Product::PENDING,
			'min_qty'     			=> $product->min_qty ?? 0,
			'max_qty'     			=> $product->max_qty ?? 0,
			'images'    			=> $this->imageUrl($product->galleries) ?? '',
			'vegetarian'  			=> $product->vegetarian,
			'kcal'        			=> $product->kcal,
			'carbs'       			=> $product->carbs,
			'protein'     			=> $product->protein,
			'fats'        			=> $product->fats,
			'addon'       			=> $product->addon,
			'discount_price'    	=> $discount?->price,
			'discount_type'	    	=> $discount?->type,
			'discount_active'	   	=> $discount?->active,
			'discount_expired_at'	=> $discount?->end,
			'properties'    		=> $properties,
			'interval'    			=> $product->interval,
			'unit'    				=> '',
			'title'    				=> '',
			'desc'    				=> '',
		];

		foreach ($this->languages as $language) {

			$unit = $product->unit?->translations?->where('locale', $language)->first();

			$body['unit'] .= "$language:$unit?->title;";

			$translation = $product->translations->where('locale', $language)->first();

			$body['title'] .= "$language:$translation?->title;";
			$body['desc']  .= "$language:$translation?->description;";

		}

		foreach ($product->stocks as $key => $stock) {

			$extras = '';

			foreach ($stock->stockExtras->groupBy('extra_group_id') as $groupId => $extra) {
				$extras .= "$groupId:" . $extra->implode('value', ',') . ';';
			}

			$body["stock $key extras"] 	 = $extras;
			$body["stock $key price"] 	 = $stock->price;
			$body["stock $key quantity"] = $stock->quantity;
			$body["stock $key sku"] 	 = $stock->sku;
			$body["stock $key addon"] 	 = $stock->addon;
			$body["stock $key img"] 	 = $stock->img;

			foreach ($stock->addons as $addonKey => $addon) {

				$addon 		= $addon->addon;
				$addonStock = $addon?->stock;

				if (empty($addon)) {
					continue;
				}

				if (empty($addonStock)) {
					continue;
				}

				$body["stock $key addon $addonKey id"] 	  = $addon->id;
				$body["stock $key addon $addonKey title"] = '';
				$body["stock $key addon $addonKey desc"]  = '';
				$body["stock $key addon $addonKey unit"]  = '';

				foreach ($this->languages as $language) {

					$addonTranslation 	  = $addon->translations->where('locale', $language)->first();
					$addonUnitTranslation = $addon->translations->where('locale', $language)->first();

					$body["stock $key addon $addonKey title"] .= "$language:$addonTranslation?->title;";
					$body["stock $key addon $addonKey desc"]  .= "$language:$addonTranslation?->description;";
					$body["stock $key addon $addonKey unit"]  .= "$language:$addonUnitTranslation?->title;";

				}

				$body["stock $key addon $addonKey interval"] = $addon->interval;
				$body["stock $key addon $addonKey tax"] 	 = $addon->tax;
				$body["stock $key addon $addonKey active"] 	 = $addon->active;
				$body["stock $key addon $addonKey status"] 	 = $addon->status;
				$body["stock $key addon $addonKey price"] 	 = $addonStock->price;
				$body["stock $key addon $addonKey quantity"] = $addonStock->quantity;
				$body["stock $key addon $addonKey sku"] 	 = $addonStock->sku;
				$body["stock $key addon $addonKey addon"] 	 = $addonStock->addon;
				$body["stock $key addon $addonKey img"] 	 = $addonStock->img;

			}

		}

		return $body;
    }
}
