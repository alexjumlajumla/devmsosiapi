<?php
declare(strict_types=1);

namespace App\Services\ComboService;

use DB;
use Throwable;
use App\Models\Combo;
use App\Models\ComboStock;
use App\Services\CoreService;
use App\Helpers\ResponseError;
use App\Traits\SetTranslations;

class ComboService extends CoreService
{
	use SetTranslations;

    protected function getModelClass(): string
    {
        return Combo::class;
    }

    /**
     * @param array $data
     * @return array
     */
    public function create(array $data): array
    {
        try {

			/** @var Combo $model */
			$model = $this->model()->create($data);

			$this->setTranslations($model, $data);

			if ($model && data_get($data, 'images.0')) {
				$model->update(['img' => data_get($data, 'images.0')]);
				$model->uploads(data_get($data, 'images'));
			}

			foreach ($data['stocks'] ?? [] as $stock) {
				ComboStock::create([
					'combo_id' => $model->id,
					'stock_id' => $stock
				]);
			}

            return [
                'status'  => true,
                'message' => ResponseError::NO_ERROR,
            ];
        } catch (Throwable $e) {

            $this->error($e);

            return ['status' => false, 'message' => ResponseError::ERROR_501, 'code' => ResponseError::ERROR_501];
        }
    }

    public function update(Combo $model, array $data): array
    {
        try {

			$model->update($data);

			$this->setTranslations($model, $data);

			if (data_get($data, 'images.0')) {
				$model->galleries()->delete();
				$model->update(['img' => data_get($data, 'images.0')]);
				$model->uploads(data_get($data, 'images'));
			}

			$stocks = $data['stocks'] ?? [];

			if (count($stocks) > 0) {
				DB::table('combo_stocks')->where('combo_id', $model->id)->delete();
			}

			foreach ($stocks as $stock) {
				ComboStock::create([
					'combo_id' => $model->id,
					'stock_id' => $stock
				]);
			}

            return [
                'status'  => true,
                'message' => ResponseError::NO_ERROR,
            ];
        } catch (Throwable $e) {

            $this->error($e);

            return ['status' => false, 'code' => ResponseError::ERROR_501, 'message' => ResponseError::ERROR_501];
        }
    }

    public function delete(?array $ids = [], ?int $shopId = null) {

        $combos = Combo::with(['translations'])
			->when($shopId, fn($q) => $q->where('shop_id', $shopId))
			->find((array)$ids);

        foreach ($combos as $combo) {
			DB::table('combo_stocks')->where('combo_id', $combo->id)->delete();
			$combo->translations()->delete();
			$combo->delete();
        }

    }
}
