<aside class="prefooter p-clamp-sm bg-dark">
  <div class="container-xxl">
    <div class="row ai-center g-5">
      @if ($global_cta_text = get_field('global_cta_text', 'option') ?? false)
        <div class="col-lg-7 t-white">
          {!! apply_filters('the_content_lite', $global_cta_text) ?? '' !!}
        </div>
      @endif
      <div class="col-lg-5 d-flex flex-column flex-md-row justify-content-lg-end">
        @if ($global_link_appointment = get_field('global_link_appointment', 'option') ?? false)
          {!! App\Helpers()->getButtonHtml($global_link_appointment, 'btn btn-dark me-md-3 mb-3 mb-md-0' ) !!}
        @endif
        @if ($global_link_contact = get_field('global_link_contact', 'option') ?? false)
          {!! App\Helpers()->getButtonHtml($global_link_contact, 'btn btn-primary btn-primary-inv' ) !!}
        @endif
      </div>
    </div>
  </div>
</aside>
<aside class="block-rated p-clamp">
  <div class="container-xxl">
    <div class="row g-5">
      <div class="col-lg-6">
        @if ($cw_title = get_field('cw_title', 'option') ?? false)
          <h2>{!! $cw_title !!}</h2>
        @endif
        <div class="d-flex">
          @if ($cw_image = get_field('cw_image', 'option') ?? false)
            {!! wp_get_attachment_image($cw_image, 'full', '', ['class'=>'img-rated me-5 w-100 h-100']) !!}
          @endif
          @if ($cw_content = get_field('cw_content', 'option') ?? false)
            <div class="box-content">
              {!! apply_filters('the_content_lite', $cw_content)   ?? '' !!}
            </div>
          @endif
        </div>
      </div>
      <div class="col-lg-6">
        @if ($tbr_title = get_field('tbr_title', 'option') ?? false)
          <h2>{!! $tbr_title !!}</h2>
        @endif
          <div class="d-flex">
            @if ($tbr_image = get_field('tbr_image', 'option') ?? false)
              {!! wp_get_attachment_image($tbr_image, 'full', '', ['class'=>'img-rated me-5 w-100 h-100']) !!}
            @endif
            @if ($tbr_content = get_field('tbr_content', 'option') ?? false)
              <div class="box-content">
                {!! apply_filters('the_content_lite', $tbr_content)   ?? '' !!}
              </div>
            @endif
          </div>
      </div>
    </div>
  </div>
</aside>
