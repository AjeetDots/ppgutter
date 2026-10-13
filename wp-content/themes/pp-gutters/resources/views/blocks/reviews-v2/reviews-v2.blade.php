<?php
$allowed_blocks = ['trustindex/block-selector'];
$template       = [
  ['trustindex/block-selector']
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} d-flex ai-center p-clamp {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <InnerBlocks
      class="col-md-8 col-lg-9 block-reviews"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
    />
  </div>
</section>
