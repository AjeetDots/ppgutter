<?php $__env->startSection('content'); ?>
<section class="p-clamp bg-secondary">
  <div class="container-xxl">
    <h1><?php echo the_title(); ?></h1>
  </div>
</section>
<?php if ($__env->exists('components.breadcrumbs')) echo $__env->make('components.breadcrumbs', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<section class="p-clamp pt-0">
    <div class="container-xxl mw-900 mx-auto">
      <?php echo the_content(); ?>

    </div>
  </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app-wide', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/tpl-legal.blade.php ENDPATH**/ ?>