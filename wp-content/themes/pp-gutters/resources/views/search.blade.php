{{--
  Template Name: Search
--}}

@extends('layouts.app-wide')

@section('content')
<section class="p-clamp bg-secondary">
  <div class="container-xxl mw-900 mx-auto">
    <h1>{!! $page_title !!}</h1>
    @includeIf('components.search-form')
  </div>
</section>
@if ($_SERVER['REQUEST_URI'] !== '/search/')
<section class="p-clamp">
  <div class="container-xxl">
    @if ($_SERVER['REQUEST_URI'] !== '/search/')
      @if (! have_posts())
      <div class="row">
        <x-alert type="warning">
          {!! __('Sorry, no results were found. Please search for something else.', 'sage') !!}
        </x-alert>
      </div>
      @else 
      <div class="row g-4">
        @php $post_type = ''; @endphp
        @while(have_posts()) @php(the_post())
          @if ($post_type !== get_post_type(get_the_ID()))
          <?php $post_type = get_post_type(get_the_ID()); ?>
            <div class="col-12 mt-5">
              <h2>{!! get_post_type_object($post_type)->label !!}</h2>
            </div>
          @endif
          @includeFirst(['partials.content-' . $post_type, 'partials.content'])
        @endwhile
        {!! get_the_posts_navigation() !!}
      </div>
      @endif
    @endif
  </div>
</section>
@endif
@endsection