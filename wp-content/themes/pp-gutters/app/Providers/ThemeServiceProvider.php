<?php

namespace App\Providers;

use Roots\Acorn\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use function App\helpers;

class ThemeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Blade::directive('svgIcon', function ($icon) {
            $args = explode('|', $icon);
            if (is_array($args)) {
                $count = count($args);
                $icon_args['icon'] = str_replace("'", "", $args[0]);
                if ($count > 1) $icon_args['width'] = (int) $args[1];
                if ($count > 2) $icon_args['height'] = (int) $args[2];
                if ($count > 3) $icon_args['title'] = $args[3];
                return helpers()->getSvg($icon_args);
            } else {
                $icon = str_replace("'", "", $icon); // explicitly do this in case it's not quoted
                return "<?= App\helpers()->getSvg(['icon' => '$icon']) ?>";
            }
        });
    }
}
