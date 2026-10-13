<?php if ($__env->exists('components.header-icons')) echo $__env->make('components.header-icons', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<header id="global-header" class="sticky-top bg-white">
  <aside class="preheader bg-dark p-clamp-x py-3">
    <div class="container-xxl t-white d-flex ai-center jc-between">
      <?php if($stars = get_field('stars', 'option') ?? false): ?>
        <div class="trust-pilot d-flex ai-start align-items-xl-center">
          <div class="d-flex flex-column flex-xl-row me-3 me-xl-0 ai-center">
            <?php echo e(__('Google rating:', 'sage')); ?>

            <?php echo wp_get_attachment_image($stars, 'full', '', ['class' => 'mx-1 mx-md-2']); ?>

          </div>
          <?php if($rating = get_field('rating', 'option') ?? false): ?>
            <?php echo $rating; ?>

          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if(has_nav_menu('social_navigation')): ?>
        <div id="box-socials" class="social d-none d-lg-block">
          <nav class="nav-social">
            <?php echo wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex jc-center']); ?>

          </nav>
        </div>
      <?php endif; ?>
      <div id="box-buttons" class="contact-box d-flex-column flex-lg-row ai-center d-none d-xxl-block">
        <?php if($email = get_field('email', 'option') ?? false): ?>
          <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="mailto:<?php echo $email; ?>">
            <?php echo App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]); ?>

            <span class="ms-2"><?php echo $email; ?></span>
          </a>
        <?php endif; ?>
        <?php if($phone = get_field('phone', 'option') ?? false): ?>
          <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="tel:<?php echo App\helpers()->getSanitisedTel($phone); ?>">
            <?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]); ?>

            <span class="ms-2"><?php echo $phone; ?></span>
          </a>
        <?php endif; ?>
        <?php if($phone_2 = get_field('phone_2', 'option') ?? false): ?>
          <a class="btn btn-dark btn-sm" href="tel:<?php echo App\helpers()->getSanitisedTel($phone_2); ?>">
            <?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]); ?>

            <span class="ms-2"><?php echo $phone_2; ?></span>
          </a>
        <?php endif; ?>
      </div>
      <div class="box-mobile d-flex d-xxl-none">
        <?php if($email = get_field('email', 'option') ?? false): ?>
          <a class="btn-header me-2" href="mailto:<?php echo $email; ?>">
            <?php echo App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]); ?>

          </a>
        <?php endif; ?>
        <?php if($phone = get_field('phone', 'option') ?? false): ?>
          <a class="btn-header" href="tel:<?php echo App\helpers()->getSanitisedTel($phone); ?>">
            <?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]); ?>

          </a>
        <?php endif; ?>
      </div>
    </div>
  </aside>
  <section class="main-header p-clamp-x py-2">
    <div class="container-xxl d-flex jc-between ai-center">
      <?php echo $site_logo; ?>

      <div class="navbar navbar-expand-lg">
        <a href="/search" class="btn-search-mobile d-lg-none bg-primary d-flex flex-center me-3"><?php echo App\helpers()->getSvg([ 'icon' => 'search', 'width' => 24, 'height' => 24]); ?></a>
        <?php if ($__env->exists('components.header-offcanvas-toggler')) echo $__env->make('components.header-offcanvas-toggler', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        <div class="offcanvas offcanvas-start-lg" tabindex="-1" id="offcanvasExample" aria-labelledby="offcanvasExampleLabel">
          <div class="offcanvas-body ai-center">
            <?php if ($__env->exists('components.header-offcanvas-nav')) echo $__env->make('components.header-offcanvas-nav', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
            <?php if(has_nav_menu('social_navigation')): ?>
              <div id="box-socials" class="social d-lg-none my-5">
                <nav class="nav-social">
                  <?php echo wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex jc-center']); ?>

                </nav>
              </div>
            <?php endif; ?>
            <div id="box-buttons" class="contact-box d-flex-column flex-lg-row ai-center d-lg-none">
              <?php if($email = get_field('email', 'option') ?? false): ?>
                <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="mailto:<?php echo $email; ?>">
                  <?php echo App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]); ?>

                  <span class="ms-2"><?php echo $email; ?></span>
                </a>
              <?php endif; ?>
              <?php if($phone = get_field('phone', 'option') ?? false): ?>
                <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="tel:<?php echo App\helpers()->getSanitisedTel($phone); ?>">
                  <?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]); ?>

                  <span class="ms-2"><?php echo $phone; ?></span>
                </a>
              <?php endif; ?>
              <?php if($phone_2 = get_field('phone_2', 'option') ?? false): ?>
                <a class="btn btn-dark btn-sm" href="tel:<?php echo App\helpers()->getSanitisedTel($phone_2); ?>">
                  <?php echo App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]); ?>

                  <span class="ms-2"><?php echo $phone_2; ?></span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="search-box d-none d-lg-block"><?php if ($__env->exists('components.search-form')) echo $__env->make('components.search-form', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?></div>
    </div>
  </section>
</header>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/sections/header.blade.php ENDPATH**/ ?>