<?php
$allowed_blocks = ['core/image', 'core/embed', 'core/video'];
$template       = [
  ['core/image', ["url" => "/wp-content/uploads/2023/12/ppgutters-home-banner.jpg"]],
  ['core/video', ['className' => 'bg-white p-3 video-neg']]
];
?>

<div class="<?php echo $block['className'] ?? 'col-lg-6'; ?>">
  <InnerBlocks
    allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
    template="<?php echo esc_attr(wp_json_encode($template)); ?>"
    templateLock="all"
  />
</div>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/partials/image-video/image-video.blade.php ENDPATH**/ ?>