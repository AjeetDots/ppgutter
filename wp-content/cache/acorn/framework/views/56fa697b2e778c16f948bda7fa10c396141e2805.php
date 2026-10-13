<?php if ($__env->exists('components.global-cta')) echo $__env->make('components.global-cta', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<footer>
  <section class="p-clamp-sm bg-primary">
    <div class="container-xxl">
      <div class="row g-5 t-white">
        <div class="col-md-6 col-lg-3 d-flex flex-column flex-md-column-reverse">
          <div class="box-logo-hours mb-5 mb-md-0">
            <?php if($logo_white = get_field('logo_white', 'option') ?? false): ?>
              <a href="/" aria-label="Logo PP Gutters Ltd."><?php echo wp_get_attachment_image($logo_white, 'full', '', ['class' => 'mb-4']); ?></a>
            <?php endif; ?>
            <?php if($opening_hours = get_field('opening_hours', 'option') ?? false): ?>
              <?php echo apply_filters('the_content_lite', $opening_hours) ?? ''; ?>

            <?php endif; ?>
          </div>
          <?php if(has_nav_menu('footer_navigation')): ?>
          <div class="box-footer-nav mb-4">
            <h3 class="h5 mb-4"><?php echo e(__('Useful Pages', 'sage')); ?></h3>
            <nav class="nav-footer">
              <?php echo wp_nav_menu(['theme_location' => 'footer_navigation', 'menu_class' => 'nav d-flex-column']); ?>

            </nav>
          </div>
          <?php endif; ?>
        </div>
        <?php if(is_nav_menu('Menu Services')): ?>
        <div class="col-md-6 col-lg-3">
          <h3 class="h5 mb-4"><?php echo e(__('Our Services', 'sage')); ?></h3>
          <nav class="nav-about">
            <?php echo wp_nav_menu(['menu' => 'Menu Services', 'menu_class' => 'nav d-flex-column']); ?>

          </nav>
        </div>
        <?php endif; ?>
        <?php if(is_nav_menu('Menu Areas')): ?>
          <div class="col-md-6 col-lg-3">
            <h3 class="h5 mb-4"><?php echo e(__('Areas We Cover', 'sage')); ?></h3>
            <nav class="nav-about">
              <?php echo wp_nav_menu(['menu' => 'Menu Areas', 'menu_class' => 'nav d-flex-column']); ?>

            </nav>
          </div>
        <?php endif; ?>
        <div class="col-md-6 col-lg-3 box-connect d-flex-column">
          <h3 class="h5 mb-4"><?php echo e(__('Contact us', 'sage')); ?></h3>
          <?php if($email = get_field('email', 'option') ?? false): ?>
            <a href="mailto:<?php echo $email; ?>" class="t-white mb-2 d-flex ai-center"><?php echo App\helpers()->getSvg([ 'icon' => 'email', 'width' => 18, 'height' => 18]); ?> <span class="ms-2"><?php echo $email; ?></span></a>
          <?php endif; ?>
          <?php if($phone = get_field('phone', 'option') ?? false): ?>
            <a href="tel:<?php echo App\helpers()->getSanitisedTel($phone); ?>" class="t-white mb-3 d-flex ai-center"><?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]); ?> <span class="ms-2"><?php echo $phone; ?><span class="ms-2"></a>
          <?php endif; ?>
          <?php if($phone_2 = get_field('phone_2', 'option') ?? false): ?>
            <a href="tel:<?php echo App\helpers()->getSanitisedTel($phone_2); ?>" class="t-white mb-3 d-flex ai-center"><?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]); ?> <span class="ms-2"><?php echo $phone_2; ?><span class="ms-2"></a>
          <?php endif; ?>
          <?php if(has_nav_menu('social_navigation')): ?>
            <div id="box-socials" class="social">
              <nav class="nav-social">
                <?php echo wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex']); ?>

              </nav>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
  <section class="copyright p-clamp-x bg-white">
    <div class="container-xxl d-flex flex-column flex-md-row ai-center jc-between py-2">
      PP Gutters Ltd <?php echo e(__('© Copyrights 2026', 'sage')); ?>

    </div>
  </section>
</footer>

<?php if ($__env->exists('components.footer-icons')) echo $__env->make('components.footer-icons', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/sections/footer.blade.php ENDPATH**/ ?>