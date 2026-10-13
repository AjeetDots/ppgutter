@extends('layouts.app-wide')

@section('content')
<section>
  <div class="container-xxl p-clamp">
    @while(have_posts()) @php(the_post())
      @includeFirst(['partials.content-single-' . get_post_type(), 'partials.content-single'])
    @endwhile
  </div>
</section>
@endsection
