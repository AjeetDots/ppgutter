<?php
$allowed_blocks = ['acf/module-column'];
$template       = [
  ['acf/module-column', ['className' => 'col-lg']],
  ['acf/module-column', ['className' => 'col-lg']]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], '--') : '' !!} bg-{!! $block['backgroundColor'] ?? 'white' !!}">
  <div class="container-xxl">
    <InnerBlocks
      class="row {!! ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], 'row--', false) : 'g-5' !!}"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
    />
  </div>
</section>
