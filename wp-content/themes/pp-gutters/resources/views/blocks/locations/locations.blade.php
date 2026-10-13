<?php
$locations        = (get_field('locations', 'option') ?? '');
$locations_images = (get_field('locations_images', 'option') ?? '');
$review_badges    = (get_field('review_badges', 'option') ?? '');
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}"
  >
  <div class="container-xxl">
    <div class="row ai-center flex-lg-row-reverse">
      <div class="col-lg-4 py-5">
        @if ($locations ?? false)
          <h2>{{ __('Our Offices', 'sage') }}</h2>
          @foreach ($locations as $l)
            <p class="location d-flex">{!! App\helpers()->getSvg([ 'icon' => 'location', 'width' => 18, 'height' => 18]) !!} <span class="ms-2">{!! $l['location'] !!}</span></p>
          @endforeach
        @endif
        @if ($google_reviews = get_field('google_reviews', 'option') ?? false)
            <a href="{!! $google_reviews !!}" class="btn btn-white" target="_blank">{{ __('View our Google Reviews', 'sage') }}</a>
        @endif
      </div>
      <div class="col-lg-8">
        @if ($locations_images ?? false)
          <div id="slider-locations" data-slick="{{ json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1, 'dots' => true, 'arrows' => false, 'autoplay' => false, 'infinite' => true]) }}">
            @foreach ($locations_images as $l)
              {!! wp_get_attachment_image($l, 'full', '') !!}
            @endforeach
          </div>
        @endif
      </div>
      @if ($review_badges ?? false)
      <div class="col-12 d-flex-column flex-md-row jc-between ai-center p-clamp-t">
        @foreach ($review_badges as $r)
          {!! wp_get_attachment_image($r, 'full', '', ['class' => 'w-100 img-obj-contain my-4 mx-2 img-review-badge']) !!}
        @endforeach
      </div>
      @endif
    </div>
  </div>
</section>
