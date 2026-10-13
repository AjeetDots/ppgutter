<?php
$post_used = ($post_loop ?? false ? $post_loop : get_the_ID());
?>

<article class="<?php echo $cols ?? 'col-md-6 col-lg-4'; ?> service-card">
  <div class="card bg-primary h-100 t-white">
    <div class="card-content mb-4">
      <?php if(has_post_thumbnail($post_used)): ?>
        <div class="ratio ratio-16x9 service-card-img">
          <?php echo get_the_post_thumbnail( $post_used, 'medium_large', ['class' => 'w-100 img-obj-cover']); ?>

        </div>
      <?php endif; ?>
      <div class="card-content mb-4 p-5 pb-3">
        <h3 class="h4"><?php echo get_the_title($post_used); ?></h3>
        <?php echo apply_filters('the_content_lite', App\helpers()->mpxStringTrim(get_the_excerpt($post_used), 200) ) ?? ''; ?>

      </div>
      <div class="mt-auto p-5 pt-0">
        <a href="<?php echo get_permalink($post_used); ?>" class="btn btn-white stretched-link"><?php echo e(__('View More', 'sage')); ?></a>
      </div>
    </div>
  </div>
</article>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/partials/content-page.blade.php ENDPATH**/ ?>