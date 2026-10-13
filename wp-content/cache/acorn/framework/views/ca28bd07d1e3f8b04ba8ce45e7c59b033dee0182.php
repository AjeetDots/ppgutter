<?php $__env->startSection('content'); ?>
<section class="p-clamp bg-secondary">
  <div class="container-xxl mw-900 mx-auto">
    <h1><?php echo $page_title; ?></h1>
    <?php if ($__env->exists('components.search-form')) echo $__env->make('components.search-form', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
  </div>
</section>
<?php if($_SERVER['REQUEST_URI'] !== '/search/'): ?>
<section class="p-clamp">
  <div class="container-xxl">
    <?php if($_SERVER['REQUEST_URI'] !== '/search/'): ?>
      <?php if(! have_posts()): ?>
      <div class="row">
        <?php if (isset($component)) { $__componentOriginald4c8f106e1e33ab85c5d037c2504e2574c1b0975 = $component; } ?>
<?php $component = $__env->getContainer()->make(App\View\Components\Alert::class, ['type' => 'warning']); ?>
<?php $component->withName('alert'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php $component->withAttributes([]); ?>
          <?php echo __('Sorry, no results were found. Please search for something else.', 'sage'); ?>

         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald4c8f106e1e33ab85c5d037c2504e2574c1b0975)): ?>
<?php $component = $__componentOriginald4c8f106e1e33ab85c5d037c2504e2574c1b0975; ?>
<?php unset($__componentOriginald4c8f106e1e33ab85c5d037c2504e2574c1b0975); ?>
<?php endif; ?>
      </div>
      <?php else: ?> 
      <div class="row g-4">
        <?php $post_type = ''; ?>
        <?php while(have_posts()): ?> <?php (the_post()); ?>
          <?php if($post_type !== get_post_type(get_the_ID())): ?>
          <?php $post_type = get_post_type(get_the_ID()); ?>
            <div class="col-12 mt-5">
              <h2><?php echo get_post_type_object($post_type)->label; ?></h2>
            </div>
          <?php endif; ?>
          <?php echo $__env->first(['partials.content-' . $post_type, 'partials.content'], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        <?php endwhile; ?>
        <?php echo get_the_posts_navigation(); ?>

      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app-wide', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/search.blade.php ENDPATH**/ ?>