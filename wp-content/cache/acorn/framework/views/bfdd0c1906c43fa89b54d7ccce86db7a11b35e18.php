<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/buttons'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "white", 'level' => 3]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph.", 'textColor' => 'white', 'className' => 'mb-0']],
  ['core/buttons', [], [['core/button', ['text' => 'Contact Us', 'url' => '#', 'className' => 'is-style-fill btn-cta btn-primary-inv btn-full-mobile', 'backgroundColor' => 'primary']]]]
];
?>

<div class="cta-wrapper bg-dark mt-5 p-5 p-rel">
  <InnerBlocks
    class="cta-inner"
    allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
    template="<?php echo esc_attr(wp_json_encode($template)); ?>"
  />
</div>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/partials/about-cta/about-cta.blade.php ENDPATH**/ ?>