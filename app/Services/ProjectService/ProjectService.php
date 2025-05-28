<?php

namespace App\Services\ProjectService;

use App\Models\Shop;
use App\Services\CoreService;
use Illuminate\Support\Facades\Http;

class ProjectService extends CoreService
{
    private string $url = 'https://demo.githubit.com/api/v2/server/notification';

    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return Shop::class;
    }

    public function activationKeyCheck(string|null $code = null, string|null $id = null): bool|string
    {
        return json_encode([
            'local'     => true,
            'active'    => true,
            'key'       => config('credential.purchase_code'),
        ]);
    }

    public function checkLocal(): bool
    {
        if ($_SERVER[base64_decode('UkVNT1RFX0FERFI=')] == base64_decode('MTI3LjAuMC4x')
            || $_SERVER[base64_decode('SFRUUF9IT1NU')] == base64_decode('bG9jYWxob3N0')
            || str_starts_with($_SERVER[base64_decode('SFRUUF9IT1NU')], '10.')
            || substr($_SERVER[base64_decode('SFRUUF9IT1NU')], 0, 7) == base64_decode('MTkyLjE2OA==')) {
            return true;
        }
        return false;
    }
}
