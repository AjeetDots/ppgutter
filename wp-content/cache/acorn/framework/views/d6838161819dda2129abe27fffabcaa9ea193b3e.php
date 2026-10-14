<?php
$args  = ['numberposts' => 3, 'post_type' => 'post', 'fields' => 'ids', 'post__not_in' => [get_the_ID()]];
$posts = get_posts($args);
?>

<?php if($posts ?? false): ?>
  <section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?> pt-0"
  >
    <div class="container-xxl">
      <h2 class="mb-5 text-center">Latest Posts</h2>
      <div class="row g-4 jc-center">
        <?php $__currentLoopData = $posts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $post): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php $cols = ($loop->first or $loop->iteration == 2) ? 'col-md-6' : 'col-md-6 col-lg-4'; ?>
          <?php if ($__env->exists('partials.content-post', ['post_loop' => $post])) echo $__env->make('partials.content-post', ['post_loop' => $post], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/latest-posts/latest-posts.blade.php ENDPATH**/ ?>