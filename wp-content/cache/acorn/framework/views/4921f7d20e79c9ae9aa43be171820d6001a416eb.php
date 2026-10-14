<?php
$pages          = App\Helpers()->getFieldDataOrBlockPlaceholder("pages", $fields ?? '', $block ?? []);
$bg             = ($fields->background_colour ?? 'bg-white');
$allowed_blocks = [ 'core/heading', 'core/paragraph' ];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]],
];
$card_size = '';
$card_size_1_and_2 = '';
if ($pages) {
  switch (count($pages)) {
    case 3:
    case 6:
      $card_size = 'col-md-6 col-lg-4';
      $card_size_1_and_2 = 'col-md-6 col-lg-4';
      break;
    case 5:
      $card_size = 'col-md-6 col-lg-4';
      $card_size_1_and_2 = 'col-md-6';
      break;
    default:
      $card_size = 'col-md-6';
      $card_size_1_and_2 = 'col-md-6';
  }
}
?>

<?php if($pages ?? false): ?>
  <section <?php if(!empty($block['anchor'])): ?> id="<?php echo $block['anchor']; ?>" <?php endif; ?>
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>"
  >
    <div class="container-xxl">
      <div class="mw-900 mx-auto mb-5">
        <InnerBlocks
          allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
          template="<?php echo esc_attr(wp_json_encode($template)); ?>"
        />
      </div>
      <div class="row g-4 jc-center">
        <?php $__currentLoopData = $pages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $page): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php $cols = ($loop->first or $loop->iteration == 2) ? $card_size_1_and_2 : $card_size; ?>
          <?php if ($__env->exists('partials.content-page', ['post_loop' => $page, 'cols' => $cols, 'card_bg' =>  $bg])) echo $__env->make('partials.content-page', ['post_loop' => $page, 'cols' => $cols, 'card_bg' =>  $bg], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    </div>
  </section>
<?php endif; ?>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/grid-pages/grid-pages.blade.php ENDPATH**/ ?>