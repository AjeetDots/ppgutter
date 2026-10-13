<?php
$image          = App\Helpers()->getFieldDataOrBlockPlaceholder("image", $fields ?? '', $block ?? []);
$numbers        = ($fields->include_numbers ?? false) ? $fields->numbers : false;
$cards          = ($fields->include_cards ?? false) ? $fields->cards : false;
$bg             = ($block['backgroundColor'] ?? 'white');
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp bg-{!! $bg !!} {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <InnerBlocks
      class="mw-900 mx-auto mb-5"
      allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
      template="{!! esc_attr(wp_json_encode($template)) !!}"
      templateLock="insert"
    />
    @if ($numbers ?? false)
      <div class="row g-4 div-numbers jc-between ai-center">
        @foreach ($numbers as $n)
          <div class="col-6 col-lg-3 box-number d-flex ai-center">
            @if ($n['image'] ?? false)
              {!! wp_get_attachment_image($n['image'], 'full', '', ['class' => 'w-100 img-number']) !!}
            @endif
            @if ($n['number'] ?? false)
              <div class="box-text ms-3 {!! ($bg === 'white') ? 't-dark' : 't-white' !!}">
                <p class="h4 {!! ($bg === 'primary') ? 't-white' : 't-primary' !!} mb-0">{!! $n['number'] !!}</p>
                {!! apply_filters('the_content_lite', $n['text']) ?? '' !!}
              </div>
            @endif
          </div>
        @endforeach
      </div>
    @endif
    @if ($image ?? false)
      {!! wp_get_attachment_image($image, 'full', '', ['class' => 'w-100 mt-5']) !!}
    @endif
    @if ($cards ?? false)
      <div id="slider-cards" class="row g-3 mt-1" data-slick="{{ json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1,'mobileFirst' => true, 'dots' => true, 'arrows' => false, 'responsive' => [['breakpoint' => 768, 'settings' => 'unslick']]]) }}">
        @foreach ($cards as $c)
          <div class="col-md-6 col-lg-4">
            <div class="{!! ($bg === 'dark') ? 'bg-primary' : 'bg-dark' !!} p-5 t-white h-100">
              @if ($c['icon'] ?? false)
                {!! wp_get_attachment_image($c['icon'], 'full', '', ['class' => 'w-100 img-number mb-3']) !!}
              @endif
              {!! apply_filters('the_content_lite', $c['text']) ?? '' !!}
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</section>
