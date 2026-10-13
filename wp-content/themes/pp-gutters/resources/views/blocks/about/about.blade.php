<?php
$allowed_blocks = ['acf/module-partial-image-video', 'acf/module-partial-about-right-side'];
$template       = [
  ['acf/module-partial-image-video', ['className' => 'col-lg-6']],
  ['acf/module-partial-about-right-side', ['className' => 'col-lg-6']]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp bg-primary">
  <div class="container-xxl">
    <InnerBlocks
      class="row g-5"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
      templateLock="all"
    />
  </div>
</section>
