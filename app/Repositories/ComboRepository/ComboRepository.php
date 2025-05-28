<?php
declare(strict_types=1);

namespace App\Repositories\ComboRepository;

use Schema;
use App\Models\Combo;
use App\Models\Language;
use App\Repositories\CoreRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ComboRepository extends CoreRepository
{
    protected function getModelClass(): string
    {
        return Combo::class;
    }

    /**
     * @param array $filter
     * @return LengthAwarePaginator
     */
    public function paginate(array $filter = []): LengthAwarePaginator
    {
		$locale = data_get(Language::languagesList()->where('default', 1)->first(), 'locale');

		$column = data_get($filter, 'column','id');

        if ($column !== 'id') {
            $column = Schema::hasColumn('combos', $column) ? $column : 'id';
        }

        return Combo::filter($filter)
			->with([
			'shop.translation' => fn($q) => $q
				->select('id', 'shop_id', 'locale', 'title')
				->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale))
        ])
            ->orderBy($column, $filter['sort'] ?? 'desc')
            ->paginate($filter['perPage'] ?? 10);
    }

	/**
	 * @param Combo $combo
	 * @return Combo
	 */
    public function show(Combo $combo): Combo
    {
		$locale = data_get(Language::languagesList()->where('default', 1)->first(), 'locale');

		return $combo->loadMissing([
			'shop.translation' => fn($q) => $q
				->select('id', 'shop_id', 'locale', 'title')
				->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),
			'stocks.product:id,uuid,slug',
			'stocks.product.translation' => fn($q) => $q
				->select('id', 'shop_id', 'locale', 'title')
				->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),
		]);
    }
}
