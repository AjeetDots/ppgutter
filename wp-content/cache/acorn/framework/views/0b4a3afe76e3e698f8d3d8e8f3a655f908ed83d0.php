<?php
$cards = App\Helpers()->getFieldDataOrBlockPlaceholder("cards", $fields ?? '', $block ?? []);
$bg    = $fields->background_colour ?? 'bg-primary';
?>

<?php if($cards ?? false): ?>
<div id="slider-additional-cards" class="row g-3 pb-5 pb-md-0" data-slick="<?php echo e(json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1,'mobileFirst' => true, 'dots' => true, 'arrows' => false, 'responsive' => [['breakpoint' => 768, 'settings' => 'unslick']]])); ?>">
  <?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div class="col-md-6 col-lg-3 mx-1 mx-md-0">
      <div class="<?php echo $bg; ?> p-4 p-md-5 t-white h-100">
        <?php if($c['icon'] ?? false): ?>
          <?php echo wp_get_attachment_image($c['icon'], 'full', '', ['class' => 'w-100 img-number mb-3']); ?>

        <?php endif; ?>
        <?php echo apply_filters('the_content_lite', $c['text']) ?? ''; ?>

      </div>
    </div>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>
<?php endif; ?><?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/partials/additional-cards/additional-cards.blade.php ENDPATH**/ ?>