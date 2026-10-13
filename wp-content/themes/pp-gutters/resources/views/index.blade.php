<?php $archive_content = App\helpers()->mpxGetArchivePageContent('Blog'); ?>
@extends('layouts.app-wide')

@section('content')
  @if ($archive_content['hero_banner_block'] ?? false)
    {!! $archive_content['hero_banner_block'] !!}
  @endif
  @includeIf('components.breadcrumbs')
  <section class="p-clamp pt-0">
    <div class="container-xxl">
      <div class="row g-4">
        @while(have_posts()) @php(the_post())
          @includeFirst(['partials.content-post', 'partials.content'])
        @endwhile
        @if (paginate_links() ?? false)
        <div class="pagination p-clamp-t text-center d-flex-center flex-wrap">
          {!! paginate_links(['prev_text' => __('&#8249;'), 'next_text' => __('&#8250;')]); !!}
        </div>
        @endif
      </div>
    </div>
  </section>
  @if ($archive_content['remaining_content'] ?? false)
    {!! $archive_content['remaining_content'] !!}
  @endif
@endsection
