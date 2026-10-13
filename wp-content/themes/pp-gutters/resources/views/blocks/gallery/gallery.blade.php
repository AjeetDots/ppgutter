<?php
$gallery        = App\Helpers()->getFieldDataOrBlockPlaceholder("gallery", $fields ?? '', $block ?? []);
$bg             = ($block['backgroundColor'] ?? 'white');
$allowed_blocks = ['core/heading', 'core/paragraph'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "dark", "textAlign" => "center"]]
];
$image_1        = isset($gallery[1]) ? wp_get_attachment_image($gallery[0], 'large', '', ['class' => 'image-before slider-image']) : false;
$ratio          = $image_1 ? \App\helpers()->calculateEmbedRatio($image_1, true) : false;
$slides_count   = $image_1 ? count($gallery) : 0;
$is_admin_screen = function_exists('get_current_screen');
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} d-flex ai-center p-clamp bg-{!! $bg !!} {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <div class="mw-950 mx-auto mb-5">
      <InnerBlocks
        allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
        template="{!! esc_attr(wp_json_encode($template)) !!}"
      />
    </div>
    @if ($image_1)
    <div class="row p-rel">
      <div id="slider-arrow-left">{!! App\helpers()->getSvg([ 'icon' => 'arrow-left', 'width' => 24, 'height' => 24]) !!}</div>
        <div id="slider-for-gallery" class="gallery-block slider-gallery slider-for" data-slick="{{ json_encode(['slidesToShow' => 1, 'slidesToScroll' => 1, 'dots' => false, 'arrows' => false, 'fade' => true, 'asNavFor' => '.slider-nav', 'autoplay' => false]) }}">
          @foreach ($gallery as $img)
            <div class="ratio" style="--bs-aspect-ratio:{!! $ratio ?: 56.25 !!}%">
              {!! wp_get_attachment_image($img, [1100,0], false, ['class'=>'bar img-obj-cover']) !!}
            </div>
          @endforeach
        </div>
      <div id="slider-arrow-right">{!! App\helpers()->getSvg([ 'icon' => 'arrow-right', 'width' => 24, 'height' => 24]) !!}</div>
      <div id="slider-nav-gallery" class="gallery-block slider-gallery slider-nav mt-2" data-slick="{{ json_encode(['slidesToShow' => 8, 'slidesToScroll' => 1, 'centerMode' => true, 'variableWidth' => true, 'dots' => true, 'arrows' => false, 'focusOnSelect' => true, 'asNavFor' => '.slider-for', 'autoplay' => false, 'responsive' => [['breakpoint' => 1024, 'settings' => ['slidesToShow' => 8]], ['breakpoint' => 767, 'settings' => ['slidesToShow' => 3]]]]) }}">
          @foreach ($gallery as $img)
              {!! wp_get_attachment_image($img, [0,100], false, ['class'=>'img-obj-cover mx-1', 'style'=>'height:100px']) !!}
          @endforeach
      </div>
    </div>
    @endif
  </div>
</section>
