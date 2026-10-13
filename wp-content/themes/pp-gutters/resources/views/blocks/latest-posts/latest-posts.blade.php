<?php
$args  = ['numberposts' => 3, 'post_type' => 'post', 'fields' => 'ids', 'post__not_in' => [get_the_ID()]];
$posts = get_posts($args);
?>

@if ($posts ?? false)
  <section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!} pt-0"
  >
    <div class="container-xxl">
      <h2 class="mb-5 text-center">Latest Posts</h2>
      <div class="row g-4 jc-center">
        @foreach ($posts as $post)
          @php $cols = ($loop->first or $loop->iteration == 2) ? 'col-md-6' : 'col-md-6 col-lg-4'; @endphp
          @includeIf('partials.content-post', ['post_loop' => $post])
        @endforeach
      </div>
    </div>
  </section>
@endif

