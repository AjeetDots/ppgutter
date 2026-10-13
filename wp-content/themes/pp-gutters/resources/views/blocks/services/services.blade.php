<?php
$services       = App\Helpers()->getFieldDataOrBlockPlaceholder("services", $fields ?? '', $block ?? []);
$bg             = $fields->background_colour ?? 'bg-white';
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]]
];
?>

@if ($services ?? false)
<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <div class="mw-900 mx-auto mb-5">
      <InnerBlocks
        allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
        template="{!! esc_attr(wp_json_encode($template)) !!}"
        templateLock="insert"
      />
    </div>
    <div class="row g-4 jc-center">
      @foreach ($services as $service)
        @php $cols = ($loop->first or $loop->iteration == 2) ? 'col-md-6' : 'col-md-6 col-lg-4'; @endphp
        @includeIf('partials.content-service', ['post_loop' => $service, 'cols' => $cols, 'card_bg' =>  $bg])
      @endforeach
    </div>
  </div>
</section>
@endif
