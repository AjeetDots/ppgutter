<?php
$allowed_blocks = ['acf/module-partial-image-video', 'acf/module-partial-about-right-side'];
$template       = [
  ['acf/module-partial-image-video', ['className' => 'col-lg-6']],
  ['acf/module-partial-about-right-side', ['className' => 'col-lg-6']]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp bg-primary">
  <div class="container-xxl">
    <InnerBlocks
      class="row g-5"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr(wp_json_encode($template)); ?>"
      templateLock="all"
    />
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/about/about.blade.php ENDPATH**/ ?>