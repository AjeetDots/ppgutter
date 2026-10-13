<?php
$allowed_blocks = [ 'core/heading', 'core/paragraph' ];
$template       = [
  ['core/heading', ["placeholder" => "Subheading Goes Here", 'textColor' => 'white']],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "textColor" => "white"]],
];
?>

<?php if($fields->custom_content ?? false): ?>
<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="p-clamp-sm bg-dark <?php echo $block['className'] ?? ''; ?>"
  >
  <div class="container-xxl">
    <div class="row ai-center g-5">
      <InnerBlocks
        class="col-lg-7"
        allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
        template="<?php echo esc_attr(wp_json_encode($template)); ?>"
      />
      <?php if(($fields->link_1 ?? false) || ($fields->link_2 ?? false)): ?>
        <div class="col-lg-5 d-flex flex-column flex-md-row justify-content-lg-end">
          <?php if($fields->link_1 ?? false): ?>
            <?php echo App\Helpers()->getButtonHtml($fields->link_1, 'btn btn-dark me-md-3 mb-3 mb-md-0' ); ?>

          <?php endif; ?>
          <?php if($fields->link_2 ?? false): ?>
            <?php echo App\Helpers()->getButtonHtml($fields->link_2, 'btn btn-primary btn-primary-inv' ); ?>

          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<?php if ($__env->exists('components.global-cta')) echo $__env->make('components.global-cta', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php endif; ?>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/cta/cta.blade.php ENDPATH**/ ?>