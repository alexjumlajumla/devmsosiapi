<?php

namespace App\Imports;

use App\Models\ExtraValue;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\UnitTranslation;
use DB;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Str;
use Throwable;

class ProductImport extends BaseImport implements ToCollection, WithHeadingRow, WithBatchInserts, WithChunkReading
{
    use Importable, Dispatchable;

    public function __construct(private ?int $shopId, private string $language) {}

    /**
     * @param Collection $collection
     * @return void
     * @throws Throwable
     */
    public function collection(Collection $collection): void
    {
        foreach ($collection as $row) {

            DB::transaction(function () use ($row) {

				if (empty($this->shopId)) {
					$this->shopId = data_get($row,'shop_id');
				}

                $isAddon = false;

                if (in_array(data_get($row, 'addon'), ['=TRUE', 'TRUE', '=true', 'true'])) {
                    $isAddon = true;
                }

                $data = [
                    'shop_id'     => $this->shopId ?? data_get($row,'shop_id'),
                    'category_id' => data_get($row, 'category_id'),
                    'brand_id'    => data_get($row, 'brand_id'),
                    'keywords'    => data_get($row, 'keywords', ''),
                    'tax'         => data_get($row, 'tax', 0),
                    'active'      => data_get($row, 'active') === 'active' ? 1 : 0,
                    'img'         => data_get($row, 'img'),
                    'qr_code'     => data_get($row, 'qr_code', ''),
                    'status'      => in_array(data_get($row, 'status'), Product::STATUSES) ? data_get($row, 'status') : Product::PENDING,
                    'min_qty'     => data_get($row, 'min_qty', 1),
                    'max_qty'     => data_get($row, 'max_qty', 1000000),
                    'addon'       => $isAddon,
                    'vegetarian'  => (boolean)data_get($row, 'vegetarian', false),
                    'kcal'        => data_get($row, 'kcal'),
                    'carbs'       => data_get($row, 'carbs'),
                    'protein'     => data_get($row, 'protein'),
                    'fats'        => data_get($row, 'fats'),
                ];

				$unit = null;
				$units  = explode(';', data_get($row, 'unit'));

				foreach ($units as $unitTitles) {

					if (empty($unitTitles)) {
						continue;
					}

					@[$unitLocale, $unitTitle] = explode(':', $unitTitles);

					if (empty($unitLocale)) {
						continue;
					}

					if (empty($unitTitle)) {
						continue;
					}

					/** @var UnitTranslation $unitTranslation */
					$unitTranslation = UnitTranslation::with(['unit'])
						->where([
							'locale' => $unitLocale,
							'title'  => $unitTitle,
						])
						->first();

					if (empty($unit)) {

						$unit = $unitTranslation?->unit ?? Unit::create([
							'active' 	=> true,
							'position'  => 1,
						]);

					}

					$unit->translations()->updateOrCreate([
						'unit_id' => $unit->id,
						'locale'  => $unitLocale,
					], [
						'title'   => $unitTitle,
					]);

				}

                $product = Product::withTrashed()
					->updateOrCreate(['id' => data_get($row, 'id')], $data + [
                        'deleted_at' => null,
						'unit_id' 	 => $unit?->id
                    ]);

				if (isset($row['images'])) {

					foreach (explode(',', $row['images']) as $key => $imgUrl) {

						if ($key === 0) {
							$product->update(['img' => $imgUrl]);
						}

						$product->galleries()->updateOrCreate([
							'path' => $imgUrl
						], [
							'title' => Str::of($imgUrl)->after('/storage/images/'),
							'type'  => 'products',
						]);

					}

				}

				$properties	  = explode(';', data_get($row, 'properties'));

				foreach ($properties as $property) {

					$propertyByLocale = explode(':', $property);
					$propertyItems    = explode(',', $propertyByLocale[1] ?? '');

					if (empty($propertyByLocale[0] ?? '')) {
						continue;
					}

					if (empty($propertyItems[0] ?? '')) {
						continue;
					}

					try {
						$product->properties()->updateOrCreate([
							'locale' => $propertyByLocale[0],
							'key' 	 => $propertyItems[0] ?? '',
							'value'  => $propertyItems[1] ?? '',
						]);
					} catch (Throwable) {}

				}

				$titles 	  = explode(';', data_get($row, 'title'));
				$descriptions = explode(';', $row['description'] ?? data_get($row, 'desc'));

				foreach ($titles as $key => $title) {

					@[$locale, $title] = explode(':', $title);

					if (empty($title) || empty($locale)) {
						continue;
					}

					$desc = '';

					if (isset($descriptions[$key])) {
						@[$localeDesc, $desc] = explode(':', $descriptions[$key]);
					}

					$product->translation()->updateOrCreate([
						'locale'        => $locale,
					], [
						'title'         => $title,
						'description'   => $desc
					]);

				}

				//stocks,addons,properties
				$stocksGroup = [];

				foreach ($row as $key => $value) {

					if (!str_starts_with($key, 'stock_')) {
						continue;
					}

					$parts = explode('_', $key);
					$stockIndex = $parts[1];

					if (!isset($stocksGroup[$stockIndex])) {
						$stocksGroup[$stockIndex] = [];
					}

					$stocksGroup[$stockIndex][$key] = $value;

				}

				if (count($stocksGroup) > 0) {
					$product->stocks()->delete();
				}

				foreach ($stocksGroup as $groupKey => $stocks) {

					$groupKey = "stock_$groupKey";

					$isStockAddon = false;

					if (in_array($stocks["{$groupKey}_addon"] ?? false, ['=TRUE', 'TRUE', '=true', 'true'])) {
						$isStockAddon = true;
					}

					$stock = Stock::create([
						'countable_type' => Product::class,
						'countable_id' 	 => $product->id,
						'sku' 			 => $stocks["{$groupKey}_sku"] ?? 0,
						'price' 		 => $stocks["{$groupKey}_price"] ?? 0,
						'quantity' 		 => $stocks["{$groupKey}_quantity"] ?? 0,
						'addon' 		 => $isStockAddon,
						'img'	 		 => $stocks["{$groupKey}_img"] ?? '',
					]);

					$this->createExtra($stock, $product, $stocks, $groupKey);
					$this->createAddons($stock, $stocks, $isAddon, $groupKey);

				}

			});

        }
    }

	/**
	 * @param Stock $stock
	 * @param Product $product
	 * @param array $stocks
	 * @param string|null $groupKey
	 * @return void
	 */
	private function createExtra(Stock $stock, Product $product, array $stocks, ?string $groupKey = ''): void
	{
		$extras = explode(';', $stocks["{$groupKey}_extras"] ?? '');

		$extraIds = [];
		$extraGroupIds = [];

		foreach ($extras as $extraGroups) {

			$extraGroup = explode(':', $extraGroups);

			$groupId = $extraGroup[0] ?? null;
			$value   = $extraGroup[1] ?? null;

			$getValue = ExtraValue::where('extra_group_id', $groupId)->where('value', $value)->first();

			if (!empty($getValue)) {
				$extraIds[] = $getValue->id;
				$extraGroupIds[] = $getValue->extra_group_id;
			}

		}

		$product->extras()->sync($extraGroupIds);
		$stock->stockExtras()->sync($extraIds);
	}

	/**
	 * @param Stock $stock
	 * @param array $stocks
	 * @param bool $isAddon
	 * @param string|null $groupKey
	 * @return void
	 */
	private function createAddons(Stock $stock, array $stocks, bool $isAddon = false, ?string $groupKey = ''): void
	{

		if ($isAddon) {
			return;
		}

		$addonGroups = [];

		foreach ($stocks as $key => $value) {

			if (!str_starts_with($key, "{$groupKey}_addon_")) {
				continue;
			}

			$parts = explode('_', $key);

			$addonIndex = $parts[3];

			if (!isset($addonGroups[$addonIndex])) {
				$addonGroups[$addonIndex] = [];
			}

			$addonGroups[$addonIndex][$key] = $value;

		}

		if (count($addonGroups) > 0) {
			$stock->addons()->delete();
		}

		foreach ($addonGroups as $addonKey => $addonGroup) {

			$addonKey = "{$groupKey}_addon_$addonKey";

			$addonUnits = explode(';', data_get($addonGroup, "{$addonKey}_unit"));

			$addonUnit = null;

			foreach ($addonUnits as $addonUnitTitles) {

				@[$addonLocale, $addonUnitTitle] = explode(':', $addonUnitTitles);

				if (empty($addonLocale)) {
					continue;
				}

				if (empty($addonUnitTitle)) {
					continue;
				}

				/** @var UnitTranslation $addonUnitTranslation */
				$addonUnitTranslation = UnitTranslation::with(['unit'])
					->where([
						'locale' => $addonLocale,
						'title'  => $addonUnitTitle,
					])
					->first();

				if (empty($addonUnit)) {

					$addonUnit = $addonUnitTranslation?->unit ?? Unit::create([
						'active' 	=> true,
						'position'  => 1,
					]);

				}

				$addonUnit->translations()->updateOrCreate([
					'locale'  => $addonLocale,
				], [
					'title'  => $addonUnitTitle,
				]);

			}

			$addon = Product::updateOrCreate([
				'id' 		=> data_get($addonGroup, "{$addonKey}_id")
			], [
				'interval'	=> data_get($addonGroup, "{$addonKey}_interval"),
				'tax'		=> data_get($addonGroup, "{$addonKey}_tax"),
				'active'	=> data_get($addonGroup, "{$addonKey}_active"),
				'status'	=> data_get($addonGroup, "{$addonKey}_status"),
				'img'		=> data_get($addonGroup, "{$addonKey}_img"),
				'shop_id'   => $this->shopId,
				'unit_id'   => $addonUnit?->id,
				'addon'     => true,
			]);

			$addonTitles = explode(';', data_get($addonGroup, "{$addonKey}_title"));
			$addonDescs  = explode(';', data_get($addonGroup, "{$addonKey}_desc"));

			foreach ($addonTitles as $addonTranslationKey => $addonTitle) {

				@[$addonLocale, $addonTitle]    = explode(':', $addonTitle);
				@[$addonDescLocale, $addonDesc] = explode(':', $addonDescs[$addonTranslationKey]);

				if (empty($addonLocale) || empty($addonTitle)) {
					continue;
				}

				$addon->translations()->updateOrCreate([
					'locale' 	 => $addonLocale,
				], [
					'title'  	  => $addonTitle,
					'description' => $addonDesc,
				]);

			}

			$addon->stock()->updateOrCreate([
				'id' => $addon->stock?->id
			], [
				'price'		=> data_get($addonGroup, "{$addonKey}_price"),
				'quantity'	=> data_get($addonGroup, "{$addonKey}_quantity"),
				'sku'		=> data_get($addonGroup, "{$addonKey}_sku"),
				'addon'		=> true,
			]);

			$addon->fresh(['stock'])->toArray();

			$stock->addons()->create([
				'addon_id' => $addon->id
			]);

		}

	}

    public function headingRow(): int
    {
        return 1;
    }

    public function batchSize(): int
    {
        return 200;
    }

    public function chunkSize(): int
    {
        return 200;
    }

}
