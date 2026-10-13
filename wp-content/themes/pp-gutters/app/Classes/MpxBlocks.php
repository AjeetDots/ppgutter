<?php

namespace App\MpxBlocks;

/**
 * A class to manage 'filter' and 'action' hooks.
 */

use App\MpxBlocks\Traits\blocks;

if (!class_exists(__NAMESPACE__.'\\MpxBlocks')) {
    /**
     * Class MpxBlocks
     *
     * @since ITD Web v1.1
     * @package App
     * @subpackage MpxBlocks
     */
    class MpxBlocks
    {
        private static $instance;

        use blocks;

        /**
         * Filters constructor.
         */
        protected function __construct()
        {
            /**
             * Adding the  Metapixels Blocks category and positioning it at the top of the list.
             */
            $this->addBlocksCategory();

            /**
             * Enqueuing block assets
             */
            $this->enqueueBlockAssets();

            /**
             * Loading Block Assets only if the block is used on the page.
             */
            $this->loadingBlockAssets();

            /**
             * Creating Pattern Categories
             */
            $this->createPatternCategories();

            /**
             * Adding loading eager to image in hero block
             */
            $this->addEagerToHeroBlock();
        }

        /**
         * Singleton
         *
         * @return MpxBlocks
         */
        public static function instance()
        {
            if ( ! ( self::$instance instanceof self ) ) {
                self::$instance = new self;
            }
            return self::$instance;
        }
    }

    MpxBlocks::instance();
}
