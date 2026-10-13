<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/buttons', 'acf/module-partial-about-cta'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "white"]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph.", 'textColor' => 'white']],
  ['core/buttons', [], [['core/button', ['text' => 'Read More', 'url' => '#', 'className' => 'is-style-fill', 'backgroundColor' => 'white']]]],
  ['acf/module-partial-about-cta']
];
?>

<div class="<?php echo $block['className'] ?? 'col-lg-6'; ?>">
  <InnerBlocks
    allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
    template="<?php echo esc_attr(wp_json_encode($template)); ?>"
  />
</div>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/partials/about-right-side/about-right-side.blade.php ENDPATH**/ ?>