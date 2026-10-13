<?php
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/buttons', 'core/image'];
$template       = [
  ['core/image', ['className' => 'bg-img-banner', "url" => "/wp-content/uploads/2023/12/ppgutters-home-banner.jpg"]],
  ['acf/module-column', ['className' => 'col-md-7']]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
class="block-{!! App\Helpers()->kebabCase($block['title']) !!} mnh-400 p-rel d-flex-column flex-md-row ai-center p-clamp bg-dark {!! (is_front_page()) ? 'hero-home' : '' !!} {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <InnerBlocks
      class="row"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr( wp_json_encode($template)) !!}"
    />
  </div>
</section>
<style>.bg-ovr-dark::before {background:linear-gradient(90deg,#333 43%,rgba(30,30,30,.5) 57%,transparent 65%)!important}</style>
