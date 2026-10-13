<?php
$services       = App\Helpers()->getFieldDataOrBlockPlaceholder("services", $fields ?? '', $block ?? []);
$bg             = $fields->background_colour ?? 'bg-white';
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]]
];
?>

<?php if($services ?? false): ?>
<section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <div class="mw-900 mx-auto mb-5">
      <InnerBlocks
        allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
        template="<?php echo esc_attr(wp_json_encode($template)); ?>"
        templateLock="insert"
      />
    </div>
    <div class="row g-4 jc-center">
      <?php $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $cols = ($loop->first or $loop->iteration == 2) ? 'col-md-6' : 'col-md-6 col-lg-4'; ?>
        <?php if ($__env->exists('partials.content-service', ['post_loop' => $service, 'cols' => $cols, 'card_bg' =>  $bg])) echo $__env->make('partials.content-service', ['post_loop' => $service, 'cols' => $cols, 'card_bg' =>  $bg], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/blocks/services/services.blade.php ENDPATH**/ ?>