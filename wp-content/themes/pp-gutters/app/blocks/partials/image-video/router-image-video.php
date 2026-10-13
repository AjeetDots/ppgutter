<?php
/**
 * Loads the blade partials from the View directory.
 *
 * - includes the main corresponding blade partial for the block
 * - conditionally loads the footer icons partial if in the WP editor
 */
if ( isset( $block['data']['preview_image_help'] ) ) {
  echo '<img src="' . esc_url( get_template_directory_uri() ) . $block['data']['preview_image_help'] .'" style="width: 100%; height:auto;">';
} else {
  echo isset($block) ? view('blocks/partials/image-video/image-video', ['block' => $block]) : '';
  if (function_exists('get_current_screen') && (get_current_screen() ?? false))
		echo view('components/footer-icons');
}
