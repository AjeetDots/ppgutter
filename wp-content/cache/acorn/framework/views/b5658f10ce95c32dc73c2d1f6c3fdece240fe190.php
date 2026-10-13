<?php $__env->startSection('content'); ?>
  <section class="p-clamp bg-secondary">
    <div class="container-xxl mw-700 mx-auto">
      <?php if ($__env->exists('components.search-form')) echo $__env->make('components.search-form', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
      <div class="d-flex-column text-center ai-center">
        <?php if($img = get_field('404_image', 'option') ?? false): ?>
          <?php echo wp_get_attachment_image($img, 'full', '', ['class' => 'mx-auto my-5']); ?>

        <?php endif; ?>
        <h2><?php echo __('Ooops!', 'sage'); ?></h2>
        <p><?php echo __('The page you are looking for doesn’t exist or an other error occurred. <br>Go back to the home page or search for another page.', 'sage'); ?></p>
        <a href="/" class="btn btn-primary btn-full-mobile"><?php echo __('Visit Homepage', 'sage'); ?></a>
      </div>
    </div>
  </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app-wide', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/404.blade.php ENDPATH**/ ?>