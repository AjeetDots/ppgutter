<?php $archive_content = App\helpers()->mpxGetArchivePageContent('Blog'); ?>


<?php $__env->startSection('content'); ?>
  <?php if($archive_content['hero_banner_block'] ?? false): ?>
    <?php echo $archive_content['hero_banner_block']; ?>

  <?php endif; ?>
  <?php if ($__env->exists('components.breadcrumbs')) echo $__env->make('components.breadcrumbs', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
  <section class="p-clamp pt-0">
    <div class="container-xxl">
      <div class="row g-4">
        <?php while(have_posts()): ?> <?php (the_post()); ?>
          <?php echo $__env->first(['partials.content-post', 'partials.content'], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        <?php endwhile; ?>
        <?php if(paginate_links() ?? false): ?>
        <div class="pagination p-clamp-t text-center d-flex-center flex-wrap">
          <?php echo paginate_links(['prev_text' => __('&#8249;'), 'next_text' => __('&#8250;')]); ?>

        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php if($archive_content['remaining_content'] ?? false): ?>
    <?php echo $archive_content['remaining_content']; ?>

  <?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app-wide', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/index.blade.php ENDPATH**/ ?>