<?php
$cards = App\Helpers()->getFieldDataOrBlockPlaceholder("cards", $fields ?? '', $block ?? []);
$bg    = $fields->background_colour ?? 'bg-primary';
?>

@if ($cards ?? false)
<div id="slider-additional-cards" class="row g-3 pb-5 pb-md-0" data-slick="{{ json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1,'mobileFirst' => true, 'dots' => true, 'arrows' => false, 'responsive' => [['breakpoint' => 768, 'settings' => 'unslick']]]) }}">
  @foreach ($cards as $c)
    <div class="col-md-6 col-lg-3 mx-1 mx-md-0">
      <div class="{!! $bg !!} p-4 p-md-5 t-white h-100">
        @if ($c['icon'] ?? false)
          {!! wp_get_attachment_image($c['icon'], 'full', '', ['class' => 'w-100 img-number mb-3']) !!}
        @endif
        {!! apply_filters('the_content_lite', $c['text']) ?? '' !!}
      </div>
    </div>
  @endforeach
</div>
@endif