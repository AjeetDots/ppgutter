<?php
$allowed_blocks = [ 'acf/module-column' ];
$template       = [
  ['acf/module-column', ['className' => 'col-md-4 col-lg-3']],
  ['acf/module-column', ['className' => 'col-md-8 col-lg']],
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp bg-primary <?php echo $block['className'] ?? ''; ?>"
  >
  <div class="container-xxl">
    <div class="mw-950 mx-auto mb-5 t-white text-center">
      <h2><?php echo e(__('What Our Customers Say', 'sage')); ?></h2>
    </div>
    <InnerBlocks
      class="row g-4 block-reviews pb-5 pb-lg-0"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr(wp_json_encode($template)); ?>"
    />
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/reviews/reviews.blade.php ENDPATH**/ ?>