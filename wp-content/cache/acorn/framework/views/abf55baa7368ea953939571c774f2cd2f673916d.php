<?php
$allowed_blocks = ['acf/module-column'];
$template       = [
  ['acf/module-column', ['className' => 'col-lg']],
  ['acf/module-column', ['className' => 'col-lg']]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], '--') : ''; ?> bg-<?php echo $block['backgroundColor'] ?? 'white'; ?>">
  <div class="container-xxl">
    <InnerBlocks
      class="row <?php echo ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], 'row--', false) : 'g-5'; ?>"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr(wp_json_encode($template)); ?>"
    />
  </div>
</section>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/columns/columns.blade.php ENDPATH**/ ?>