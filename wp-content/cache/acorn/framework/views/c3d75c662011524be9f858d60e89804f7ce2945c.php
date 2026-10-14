<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/html', 'contact-form-7/contact-form-selector', 'fluentfom/guten-block'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "className" => "has-text-align-center"]],
  ['fluentfom/guten-block', ["className" => "brand-form"]]
];
?>

<section id="enquiry-form"
  class="block-<?php echo App\Helpers()->kebabCase($block['title']); ?> p-clamp <?php echo $block['className'] ?? ''; ?>">
  <div class="container-xxl">
    <InnerBlocks
      class="mw-700 mx-auto"
      allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
      template="<?php echo esc_attr(wp_json_encode($template)); ?>"
    />
  </div>
</section>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/blocks/form/form.blade.php ENDPATH**/ ?>