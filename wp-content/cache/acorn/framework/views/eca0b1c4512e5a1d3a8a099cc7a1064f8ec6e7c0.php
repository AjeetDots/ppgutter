<?php
$image          = App\Helpers()->getFieldDataOrBlockPlaceholder("image", $fields ?? '', $block ?? []);
$numbers        = ($fields->include_numbers ?? false) ? $fields->numbers : false;
$cards          = ($fields->include_cards ?? false) ? $fields->cards : false;
$bg             = ($block['backgroundColor'] ?? 'white');
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]]
];
?>

<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp bg-<?php echo $bg; ?> <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <InnerBlocks
      class="mw-900 mx-auto mb-5"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr(wp_json_encode($template)); ?>"
      templateLock="insert"
    />
    <?php if($numbers ?? false): ?>
      <div class="row g-4 div-numbers jc-between ai-center">
        <?php $__currentLoopData = $numbers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $n): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <div class="col-6 col-lg-3 box-number d-flex ai-center">
            <?php if($n['image'] ?? false): ?>
              <?php echo wp_get_attachment_image($n['image'], 'full', '', ['class' => 'w-100 img-number']); ?>

            <?php endif; ?>
            <?php if($n['number'] ?? false): ?>
              <div class="box-text ms-3 <?php echo ($bg === 'white') ? 't-dark' : 't-white'; ?>">
                <p class="h4 <?php echo ($bg === 'primary') ? 't-white' : 't-primary'; ?> mb-0"><?php echo $n['number']; ?></p>
                <?php echo apply_filters('the_content_lite', $n['text']) ?? ''; ?>

              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    <?php endif; ?>
    <?php if($image ?? false): ?>
      <?php echo wp_get_attachment_image($image, 'full', '', ['class' => 'w-100 mt-5']); ?>

    <?php endif; ?>
    <?php if($cards ?? false): ?>
      <div id="slider-cards" class="row g-3 mt-1" data-slick="<?php echo e(json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1,'mobileFirst' => true, 'dots' => true, 'arrows' => false, 'responsive' => [['breakpoint' => 768, 'settings' => 'unslick']]])); ?>">
        <?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <div class="col-md-6 col-lg-4">
            <div class="<?php echo ($bg === 'dark') ? 'bg-primary' : 'bg-dark'; ?> p-5 t-white h-100">
              <?php if($c['icon'] ?? false): ?>
                <?php echo wp_get_attachment_image($c['icon'], 'full', '', ['class' => 'w-100 img-number mb-3']); ?>

              <?php endif; ?>
              <?php echo apply_filters('the_content_lite', $c['text']) ?? ''; ?>

            </div>
          </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/numbers-cards/numbers-cards.blade.php ENDPATH**/ ?>