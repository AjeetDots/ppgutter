<?php
$allowed_blocks = [ 'core/heading', 'core/paragraph' ];
$template       = [
  ['core/heading', ["placeholder" => "Subheading Goes Here", 'textColor' => 'white']],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph text.", "textColor" => "white"]],
];
?>

@if ($fields->custom_content ?? false)
<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="p-clamp-sm bg-dark {!! $block['className'] ?? '' !!}"
  >
  <div class="container-xxl">
    <div class="row ai-center g-5">
      <InnerBlocks
        class="col-lg-7"
        allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
        template="{!! esc_attr(wp_json_encode($template)) !!}"
      />
      @if (($fields->link_1 ?? false) || ($fields->link_2 ?? false))
        <div class="col-lg-5 d-flex flex-column flex-md-row justify-content-lg-end">
          @if ($fields->link_1 ?? false)
            {!! App\Helpers()->getButtonHtml($fields->link_1, 'btn btn-dark me-md-3 mb-3 mb-md-0' ) !!}
          @endif
          @if ($fields->link_2 ?? false)
            {!! App\Helpers()->getButtonHtml($fields->link_2, 'btn btn-primary btn-primary-inv' ) !!}
          @endif
        </div>
      @endif
    </div>
  </div>
</section>
@else
@includeIf('components.global-cta')
@endif
