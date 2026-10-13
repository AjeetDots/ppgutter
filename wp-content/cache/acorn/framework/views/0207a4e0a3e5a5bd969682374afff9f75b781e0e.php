<?php
$images = App\Helpers()->getFieldDataOrBlockPlaceholder("images", $fields ?? '', $block ?? []);
// if the second image exists, grab the first image and use its width and height to set the ratio of both images
$image_1 = isset($images[1]) ? wp_get_attachment_image($images[0], 'large', '', ['class' => 'image-before slider-image']) : false;
$ratio = $image_1 ? \App\helpers()->calculateEmbedRatio($image_1, true) : false;
$is_admin_screen = function_exists('get_current_screen');
?>

<?php if($image_1 || $is_admin_screen): ?>
<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
      <div class="before-after">
        <?php if($image_1): ?>
        <div class="container-slider">
            <div class="image-container ratio" style="--bs-aspect-ratio:<?php echo $ratio ?: 56.25; ?>%">
              <?php echo $image_1; ?>

              <?php echo wp_get_attachment_image($images[1], 'large', '', ['class' => 'image-after slider-image']); ?>

            </div>
            <input type="range" min="0" max="100" value="50" aria-label="Percentage of before photo shown" class="slider" />
            <div class="slider-line" aria-hidden="true">
            <div class="slider-button" aria-hidden="true">
            <?php echo App\helpers()->getSvg([ 'icon' => 'arrow-left', 'width' => 18, 'height' => 18]); ?>

            <?php echo App\helpers()->getSvg([ 'icon' => 'arrow-right', 'width' => 18, 'height' => 18]); ?>

            </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
  </div>
</section>
<?php endif; ?>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/before-after/before-after.blade.php ENDPATH**/ ?>