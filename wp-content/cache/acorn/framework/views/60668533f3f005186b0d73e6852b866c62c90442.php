<?php echo $__env->make('sections.header', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<div class="wrap container-fluid px-0" role="document">
  <div class="content">
    <main class="main">
      <?php echo $__env->yieldContent('content'); ?>
    </main>
  </div>
</div>
<?php echo $__env->make('sections.footer', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/layouts/app-wide.blade.php ENDPATH**/ ?>