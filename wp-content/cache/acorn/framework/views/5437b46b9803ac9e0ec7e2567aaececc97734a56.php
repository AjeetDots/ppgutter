<?php
$certifications = App\Helpers()->getFieldDataOrBlockPlaceholder("certifications", $fields ?? '', $block ?? []);
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "white", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "textColor" => "white", "className" => "has-text-align-center"]]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl bg-dark p-5">
    <div class="mw-700 mx-auto">
      <InnerBlocks
        allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
        template="<?php echo esc_attr(wp_json_encode($template)); ?>"
      />
    </div>
    <?php if($certifications ?? false): ?>
    <div class="row g-4 mt-4">
      <?php $__currentLoopData = $certifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div class="col-md-4">
          <div class="ratio ratio-4x3">
            <?php echo wp_get_attachment_image($c, 'full', ''); ?>

          </div>
        </div>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/certifications/certifications.blade.php ENDPATH**/ ?>