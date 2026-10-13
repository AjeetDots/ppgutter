<?php
$pages          = App\Helpers()->getFieldDataOrBlockPlaceholder("pages", $fields ?? '', $block ?? []);
$bg             = ($fields->background_colour ?? 'bg-white');
$allowed_blocks = [ 'core/heading', 'core/paragraph' ];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]],
];
$card_size = '';
$card_size_1_and_2 = '';
if ($pages) {
  switch (count($pages)) {
    case 3:
    case 6:
      $card_size = 'col-md-6 col-lg-4';
      $card_size_1_and_2 = 'col-md-6 col-lg-4';
      break;
    case 5:
      $card_size = 'col-md-6 col-lg-4';
      $card_size_1_and_2 = 'col-md-6';
      break;
    default:
      $card_size = 'col-md-6';
      $card_size_1_and_2 = 'col-md-6';
  }
}
?>

@if ($pages ?? false)
  <section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}"
  >
    <div class="container-xxl">
      <div class="mw-900 mx-auto mb-5">
        <InnerBlocks
          allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
          template="{!! esc_attr(wp_json_encode($template)) !!}"
        />
      </div>
      <div class="row g-4 jc-center">
        @foreach ($pages as $page)
          @php $cols = ($loop->first or $loop->iteration == 2) ? $card_size_1_and_2 : $card_size; @endphp
          @includeIf('partials.content-page', ['post_loop' => $page, 'cols' => $cols, 'card_bg' =>  $bg])
        @endforeach
      </div>
    </div>
  </section>
@endif
