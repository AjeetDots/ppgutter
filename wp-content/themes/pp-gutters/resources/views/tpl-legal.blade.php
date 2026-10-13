{{--
  Template Name: Legal
--}}

@extends('layouts.app-wide')

@section('content')
<section class="p-clamp bg-secondary">
  <div class="container-xxl">
    <h1>{!! the_title() !!}</h1>
  </div>
</section>
@includeIf('components.breadcrumbs')
<section class="p-clamp pt-0">
    <div class="container-xxl mw-900 mx-auto">
      {!! the_content() !!}
    </div>
  </section>
@endsection
