<?php
$allowed_blocks = [ 'acf/module-column' ];
$template       = [
  ['acf/module-column', ['className' => 'col-md-4 col-lg-3']],
  ['acf/module-column', ['className' => 'col-md-8 col-lg']],
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp bg-primary {!! $block['className'] ?? '' !!}"
  >
  <div class="container-xxl">
    <div class="mw-950 mx-auto mb-5 t-white text-center">
      <h2>{{ __('What Our Customers Say', 'sage') }}</h2>
    </div>
    <InnerBlocks
      class="row g-4 block-reviews pb-5 pb-lg-0"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
    />
  </div>
</section>
