<?php
$gallery        = App\Helpers()->getFieldDataOrBlockPlaceholder("gallery", $fields ?? '', $block ?? []);
$bg             = ($block['backgroundColor'] ?? 'white');
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "dark", "textAlign" => "center"]]
];
$image_1        = isset($gallery[1]) ? wp_get_attachment_image($gallery[0], 'large', '', ['class' => 'image-before slider-image']) : false;
$ratio          = $image_1 ? \App\helpers()->calculateEmbedRatio($image_1, true) : false;
$slides_count   = $image_1 ? count($gallery) : 0;
$is_admin_screen = function_exists('get_current_screen');
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> d-flex ai-center p-clamp bg-<?php echo $bg; ?> <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <div class="mw-950 mx-auto mb-5">
      <InnerBlocks
        allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
        template="<?php echo esc_attr(wp_json_encode($template)); ?>"
      />
    </div>
    <?php if($image_1): ?>
    <div class="row p-rel">
      <div id="slider-arrow-left"><?php echo App\helpers()->getSvg([ 'icon' => 'arrow-left', 'width' => 24, 'height' => 24]); ?></div>
        <div id="slider-for-gallery" class="gallery-block slider-gallery slider-for" data-slick="<?php echo e(json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1, 'dots' => false, 'arrows' => false, 'fade' => true, 'asNavFor' => '.slider-nav', 'autoplay' => false])); ?>">
          <?php $__currentLoopData = $gallery; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $img): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="ratio" style="--bs-aspect-ratio:<?php echo $ratio ?: 56.25; ?>%">
              <?php echo wp_get_attachment_image($img, [1100,0], false, ['class'=>'bar img-obj-cover']); ?>

            </div>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
      <div id="slider-arrow-right"><?php echo App\helpers()->getSvg([ 'icon' => 'arrow-right', 'width' => 24, 'height' => 24]); ?></div>
      <div id="slider-nav-gallery" class="gallery-block slider-gallery slider-nav mt-2" data-slick="<?php echo e(json_encode(['slidesToShow' => 8, 'slidesToScroll' => 1, 'centerMode' => true, 'variableWidth' => true, 'dots' => true, 'arrows' => false, 'focusOnSelect' => true, 'asNavFor' => '.slider-for', 'autoplay' => false, 'responsive' => [['breakpoint' => 1024, 'settings' => ['slidesToShow' => 8]], ['breakpoint' => 767, 'settings' => ['slidesToShow' => 3]]]])); ?>">
          <?php $__currentLoopData = $gallery; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $img): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <?php echo wp_get_attachment_image($img, [0,100], false, ['class'=>'img-obj-cover mx-1', 'style'=>'height:100px']); ?>

          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/gallery/gallery.blade.php ENDPATH**/ ?>