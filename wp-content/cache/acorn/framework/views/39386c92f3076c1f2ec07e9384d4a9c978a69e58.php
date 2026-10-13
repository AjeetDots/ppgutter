<?php
$allowed_blocks = ['acf/module-partial-additional-cards', 'core/heading', 'core/paragraph', 'core/buttons'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]],
  ['acf/module-partial-additional-cards'],
  ['core/buttons', ['contentJustification' => 'center', 'className' => 'mt-5'], [['core/button', ['text' => 'Button Text',  'url' => '#', 'className' => 'is-style-fill btn-full-mobile', 'backgroundColor' => 'primary']]]]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <InnerBlocks
    class="container-xxl"
    allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
    template="<?php echo esc_attr(wp_json_encode($template)); ?>"
  />
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/additional-cards/additional-cards.blade.php ENDPATH**/ ?>