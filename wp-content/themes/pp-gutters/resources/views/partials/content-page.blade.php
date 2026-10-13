<?php
$post_used = ($post_loop ?? false ? $post_loop : get_the_ID());
?>

<article class="{!! $cols ?? 'col-md-6 col-lg-4' !!} service-card">
  <div class="card bg-primary h-100 t-white">
    <div class="card-content mb-4">
      @if (has_post_thumbnail($post_used))
        <div class="ratio ratio-16x9 service-card-img">
          {!! get_the_post_thumbnail( $post_used, 'medium_large', ['class' => 'w-100 img-obj-cover']); !!}
        </div>
      @endif
      <div class="card-content mb-4 p-5 pb-3">
        <h3 class="h4">{!! get_the_title($post_used) !!}</h3>
        {!! apply_filters('the_content_lite', App\helpers()->mpxStringTrim(get_the_excerpt($post_used), 200) ) ?? '' !!}
      </div>
      <div class="mt-auto p-5 pt-0">
        <a href="{!! get_permalink($post_used) !!}" class="btn btn-white stretched-link">{{ __('View More', 'sage') }}</a>
      </div>
    </div>
  </div>
</article>
