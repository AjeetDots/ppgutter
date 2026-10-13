<?php

namespace App\Helpers;

use App\Helpers\Traits\blocks;
use App\Helpers\Traits\icons;
use App\Helpers\Traits\shortcodes;
use App\Helpers\Traits\strings;
use App\Helpers\Traits\widgets;
use App\Helpers\Traits\media;

if (!class_exists(__NAMESPACE__.'\\Helpers')) {
    /**
     * Class Helpers
     *
     * @since ITD Web v1.1
     * @package App\Helpers
     */
    class Helpers
    {
        private static $instance;

        use blocks;
        use widgets;
        use icons;
        use shortcodes;
        use strings;
        use media;

        protected function __construct() {
            // set a bool to check if the current screen is for editing
            $this->is_editor_mode = function_exists('get_current_screen');
        }

        /**
         * Singleton
         *
         * @return Helpers
         */
        public static function instance()
        {
            if ( ! ( self::$instance instanceof self ) ) {
                self::$instance = new self;
            }
            return self::$instance;
        }
    }
}
