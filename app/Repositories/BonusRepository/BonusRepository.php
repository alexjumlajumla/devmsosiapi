<?php

namespace App\Repositories\BonusRepository;

use App\Models\Bonus;
use App\Models\Language;
use App\Repositories\CoreRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BonusRepository extends CoreRepository
{
    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return Bonus::class;
    }

    public function paginate(array $filter): LengthAwarePaginator
    {
		$locale = Language::where('default', 1)->first()?->locale;

        /** @var Bonus $bonus */
        $bonus = $this->model();

        $productWith = [
            'bonusable' => fn($q) => $q->select([
                'id',
                'price',
                'quantity',
                'countable_id',
                'countable_type'
            ]),
            'bonusable.countable' => fn($q) => $q->select([
                'id',
                'uuid',
            ]),
			'bonusable.stockExtras.group.translation' => fn($q) => $q
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
            'bonusable.countable.translation' => fn($q) => $q
                ->select('id', 'locale', 'title', 'product_id')
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
        ];

        $shopWith = [
            'bonusable' => fn($q) => $q->select([
                'id',
                'uuid',
            ]),
            'bonusable.translation' => fn($q) => $q
                ->select('id', 'locale', 'title', 'shop_id')
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
        ];

		return $bonus
            ->whereShopId(data_get($filter, 'shop_id'))
            ->when(data_get($filter, 'type'),
                fn($query, $type) => $query->where('type', $type === 'product' ? 'count' : 'sum')
            )
            ->when(isset($filter['deleted_at']), fn($q) => $q->onlyTrashed())
            ->with((data_get($filter, 'type') === 'product' ? $productWith : $shopWith) + [
                'stock' => fn($q) => $q->select([
                    'id',
                    'countable_id',
                    'countable_type',
                    'price',
                    'quantity',
                ]),
				'stock.stockExtras.group.translation' => fn($q) => $q
					->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
						$q->where('locale', $this->language)->orWhere('locale', $locale);
					})),
                'stock.countable' => fn($q) => $q->select('id', 'uuid'),
                'stock.countable.translation' => fn($q) => $q
					->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
						$q->where('locale', $this->language)->orWhere('locale', $locale);
					})),
            ])
            ->select([
                'id',
                'bonusable_type',
                'bonusable_id',
                'bonus_quantity',
                'bonus_stock_id',
                'value',
                'type',
                'status',
                'expired_at',
            ])
            ->orderByDesc('id')
            ->paginate(data_get($filter, 'perPage', 10));
    }


    /**
     * Get one brands by Identification number
     */
    public function show(Bonus $bonus): Bonus
    {
		$locale = Language::where('default', 1)->first()?->locale;

		$productWith = [
            'bonusable' => fn($q) => $q->select([
                'id',
                'price',
                'quantity',
                'countable_id',
                'countable_type'
            ]),
            'bonusable.stockExtras',
			'bonusable.stockExtras.group.translation' => fn($q) => $q
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
            'bonusable.countable' => fn($q) => $q->select([
                'id',
                'uuid',
            ]),
            'bonusable.countable.translation' => fn($q) => $q
                ->select('id', 'locale', 'title', 'product_id')
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
        ];

        $shopWith = [
            'bonusable' => fn($q) => $q->select([
                'id',
                'uuid',
            ]),
            'bonusable.translation' => fn($q) => $q
                ->select('id', 'locale', 'title', 'shop_id')
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
        ];

        return $bonus->load(($bonus->type === 'count' ? $productWith : $shopWith) + [
        	'stock',
        	'stock.stockExtras',
			'stock.stockExtras.group.translation' => fn($q) => $q
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
            'stock.countable' => fn($q) => $q->select('id', 'uuid'),
            'stock.countable.translation' => fn($q) => $q
				->when($this->language, fn($q) => $q->where(function ($q) use ($locale) {
					$q->where('locale', $this->language)->orWhere('locale', $locale);
				})),
        ]);
    }

}
