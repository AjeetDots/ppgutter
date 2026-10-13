<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <div class="row g-3 t-white">
      <div class="col-lg-4">
        <div class="col-inner bg-primary p-5 h-100">
          <?php if($opening_hours = get_field('opening_hours', 'option') ?? false): ?>
            <h2><?php echo e(__('Working Hours', 'sage')); ?></h2>
            <?php echo apply_filters('the_content_lite', $opening_hours) ?? ''; ?>

          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="col-inner bg-dark p-5 h-100">
          <h2><?php echo e(__('Contact Details', 'sage')); ?></h2>
          <?php if($location = get_field('locations', 'option') ?? false): ?>
            <a class="t-white d-flex ai-start mb-4"><span><?php echo App\helpers()->getSvg([ 'icon' => 'location', 'width' => 18, 'height' => 18]); ?></span> <span class="d-block ps-3 "><strong><?php echo e(__('Headquarters office', 'sage')); ?></strong> <br><?php echo $location[0]['location']; ?></span></a>
          <?php endif; ?>
            <p class="t-white d-flex ai-start mb-4">
              <span><?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]); ?></span>
              <span class="d-block ps-3 "><strong><?php echo e(__('Phone numbers', 'sage')); ?></strong>
                <?php if($phone = get_field('phone', 'option') ?? false): ?>
                  <a href="tel:<?php echo App\helpers()->getSanitisedTel($phone); ?>" class="t-white d-flex ai-center"><?php echo $phone; ?></a>
                <?php endif; ?>
                <?php if($phone_2 = get_field('phone_2', 'option') ?? false): ?>
                  <a href="tel:<?php echo App\helpers()->getSanitisedTel($phone_2); ?>" class="t-white d-flex ai-center"><?php echo $phone_2; ?></a>
                <?php endif; ?>
              </span>
            </p>
          <?php if($email = get_field('email', 'option') ?? false): ?>
            <a href="mailto:<?php echo $email; ?>" class="t-white d-flex ai-start"><span><?php echo App\helpers()->getSvg([ 'icon' => 'email', 'width' => 18, 'height' => 18]); ?></span> <span class="d-block ps-3 "><strong><?php echo e(__('Email', 'sage')); ?></strong> <br><?php echo $email; ?></span></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="col-inner bg-primary p-5 h-100">
          <?php if($pay_your_bill = get_field('pay_your_bill', 'option') ?? false): ?>
            <h2><?php echo e(__('Pay Your Bill', 'sage')); ?></h2>
            <?php echo apply_filters('the_content_lite', $pay_your_bill) ?? ''; ?>

          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/contact-details/contact-details.blade.php ENDPATH**/ ?>