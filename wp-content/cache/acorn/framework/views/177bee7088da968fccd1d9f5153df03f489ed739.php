<?php
$locations        = (get_field('locations', 'option') ?? '');
$locations_images = (get_field('locations_images', 'option') ?? '');
$review_badges    = (get_field('review_badges', 'option') ?? '');
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>"
  >
  <div class="container-xxl">
    <div class="row ai-center flex-lg-row-reverse">
      <div class="col-lg-4 py-5">
        <?php if($locations ?? false): ?>
          <h2><?php echo e(__('Our Offices', 'sage')); ?></h2>
          <?php $__currentLoopData = $locations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <p class="location d-flex"><?php echo App\helpers()->getSvg([ 'icon' => 'location', 'width' => 18, 'height' => 18]); ?> <span class="ms-2"><?php echo $l['location']; ?></span></p>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php endif; ?>
        <?php if($google_reviews = get_field('google_reviews', 'option') ?? false): ?>
            <a href="<?php echo $google_reviews; ?>" class="btn btn-white" target="_blank"><?php echo e(__('View our Google Reviews', 'sage')); ?></a>
        <?php endif; ?>
      </div>
      <div class="col-lg-8">
        <?php if($locations_images ?? false): ?>
          <div id="slider-locations" data-slick="<?php echo e(json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1, 'dots' => true, 'arrows' => false, 'autoplay' => false, 'infinite' => true])); ?>">
            <?php $__currentLoopData = $locations_images; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <?php echo wp_get_attachment_image($l, 'full', ''); ?>

            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if($review_badges ?? false): ?>
      <div class="col-12 d-flex-column flex-md-row jc-between ai-center p-clamp-t">
        <?php $__currentLoopData = $review_badges; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php echo wp_get_attachment_image($r, 'full', '', ['class' => 'w-100 img-obj-contain my-4 mx-2 img-review-badge']); ?>

        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/locations/locations.blade.php ENDPATH**/ ?>