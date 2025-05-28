<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Language;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * @param string|null $language
     * @param string|null $currency
     */
    public function __construct(
        protected ?string $language = null,
        protected ?string $currency = null
    )
    {
        // Skip database operations if running artisan route:list command
        if ($this->isRouteListCommand()) {
            return;
        }

        $this->language = $this->setLanguage();
        $this->currency = $this->setCurrency();
    }

    /**
     * Check if current request is from artisan route:list command
     */
    protected function isRouteListCommand(): bool
    {
        return app()->runningInConsole() && 
               isset($_SERVER['argv']) && 
               is_array($_SERVER['argv']) && 
               (in_array('route:list', $_SERVER['argv']) || in_array('route:clear', $_SERVER['argv']));
    }

    /**
     * Set default Currency
     */
    protected function setCurrency(): string|int|null
    {
        return request(
            'currency_id',
            data_get(Currency::currenciesList()->where('default', 1)->first(), 'id')
        );
    }

    /**
     * Set default Language
     */
    protected function setLanguage(): ?string
    {
        return request(
            'lang',
            data_get(Language::languagesList()->where('default', 1)->first(), 'locale')
        );
    }
}
