<?php
$post_used = ($post_loop ?? false ? $post_loop : get_the_ID());
?>
<article class="col-md-6 col-lg-4 service-card">
  <div class="card bg-primary h-100 t-white">
    <?php if(has_post_thumbnail($post_used)): ?>
      <div class="ratio ratio-16x9 service-card-img">
        <?php echo get_the_post_thumbnail( $post_used, 'medium_large', ['class' => 'w-100 img-obj-cover']); ?>

      </div>
    <?php endif; ?>
    <div class="card-content mb-4 p-5 pb-3">
      <?php if(is_home()): ?>
        <h2 class="h4"><?php echo get_the_title($post_used); ?></h2>
      <?php else: ?>
        <h3 class="h4"><?php echo get_the_title($post_used); ?></h3>
      <?php endif; ?>
      <?php echo apply_filters('the_content_lite', get_the_excerpt($post_used)) ?? ''; ?>

    </div>
    <div class="mt-auto p-5 pt-0">
      <a href="<?php echo get_permalink($post_used); ?>" class="btn btn-white stretched-link"><?php echo e(__('Read More', 'sage')); ?></a>
    </div>
  </div>
</article>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/partials/content-post.blade.php ENDPATH**/ ?>