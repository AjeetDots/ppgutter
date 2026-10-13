<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/buttons', 'core/image'];
$template       = [
  ['core/image', ['className' => 'bg-img-banner', "url" => "/wp-content/uploads/2023/12/ppgutters-home-banner.jpg"]],
  ['acf/module-column', ['className' => 'col-md-7']]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> mnh-400 p-rel d-flex-column flex-md-row ai-center p-clamp bg-dark <?php echo (is_front_page()) ? 'hero-home' : ''; ?> <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <InnerBlocks
      class="row"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr( wp_json_encode($template)); ?>"
    />
  </div>
</section>
<style>.bg-ovr-dark::before {background:linear-gradient(90deg,#333 43%,rgba(30,30,30,.5) 57%,transparent 65%)!important}</style>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/hero-v2/hero-v2.blade.php ENDPATH**/ ?>