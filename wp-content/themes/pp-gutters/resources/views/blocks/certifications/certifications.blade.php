<?php
$certifications = App\Helpers()->getFieldDataOrBlockPlaceholder("certifications", $fields ?? '', $block ?? []);
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "white", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "textColor" => "white", "className" => "has-text-align-center"]]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}">
  <div class="container-xxl bg-dark p-5">
    <div class="mw-700 mx-auto">
      <InnerBlocks
        allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
        template="{!! esc_attr(wp_json_encode($template)) !!}"
      />
    </div>
    @if ($certifications ?? false)
    <div class="row g-4 mt-4">
      @foreach ($certifications as $c)
        <div class="col-md-4">
          <div class="ratio ratio-4x3">
            {!! wp_get_attachment_image($c, 'full', '') !!}
          </div>
        </div>
      @endforeach
    </div>
    @endif
  </div>
</section>
