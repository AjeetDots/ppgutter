<?php
$post_used = ($post_loop ?? false ? $post_loop : get_the_ID());
?>
<article class="col-md-6 col-lg-4 service-card">
  <div class="card bg-primary h-100 t-white">
    @if (has_post_thumbnail($post_used))
      <div class="ratio ratio-16x9 service-card-img">
        {!! get_the_post_thumbnail( $post_used, 'medium_large', ['class' => 'w-100 img-obj-cover']); !!}
      </div>
    @endif
    <div class="card-content mb-4 p-5 pb-3">
      @if (is_home())
        <h2 class="h4">{!! get_the_title($post_used) !!}</h2>
      @else
        <h3 class="h4">{!! get_the_title($post_used) !!}</h3>
      @endif
      {!! apply_filters('the_content_lite', get_the_excerpt($post_used)) ?? '' !!}
    </div>
    <div class="mt-auto p-5 pt-0">
      <a href="{!! get_permalink($post_used) !!}" class="btn btn-white stretched-link">{{ __('Read More', 'sage') }}</a>
    </div>
  </div>
</article>
