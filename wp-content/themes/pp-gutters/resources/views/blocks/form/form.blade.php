<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'contact-form-7/contact-form-selector', 'fluentfom/guten-block'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "className" => "has-text-align-center"]],
  ['fluentfom/guten-block', ["className" => "brand-form"]]
];
?>

<section id="enquiry-form"
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <InnerBlocks
      class="mw-700 mx-auto"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
    />
  </div>
</section>
