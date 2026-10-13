<?php

namespace App;

/**
 * Set site type
 *
 * IS_ONE_PAGER will only be true if the option exists and it set to true.
 */
define('IS_ONE_PAGER', (bool) get_option('options_is_one_pager'));

/**
 * Loads custom dependencies
 * - classes
 * - traits
 * - hooks
 */

/**
 * Helpers
 * - traits
 */
foreach (glob(__DIR__ . '/Traits/helpers/*.php') as $file) require_once $file;

/**
 * Hooks
 */
foreach (glob(__DIR__ . '/Traits/filters/*.php') as $file) require_once $file;

/**
 * Blocks
 */
foreach (glob(__DIR__ . '/Traits/blocks/*.php') as $file) require_once $file;

/**
 * Classes
 */
foreach (glob(__DIR__ . '/Classes/*.php') as $file) require_once $file;