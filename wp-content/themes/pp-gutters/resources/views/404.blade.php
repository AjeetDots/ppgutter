@extends('layouts.app-wide')

@section('content')
  <section class="p-clamp bg-secondary">
    <div class="container-xxl mw-700 mx-auto">
      @includeIf('components.search-form')
      <div class="d-flex-column text-center ai-center">
        @if ($img = get_field('404_image', 'option') ?? false)
          {!! wp_get_attachment_image($img, 'full', '', ['class' => 'mx-auto my-5']) !!}
        @endif
        <h2>{!! __('Ooops!', 'sage') !!}</h2>
        <p>{!! __('The page you are looking for doesn’t exist or an other error occurred. <br>Go back to the home page or search for another page.', 'sage') !!}</p>
        <a href="/" class="btn btn-primary btn-full-mobile">{!! __('Visit Homepage', 'sage') !!}</a>
      </div>
    </div>
  </section>
@endsection
