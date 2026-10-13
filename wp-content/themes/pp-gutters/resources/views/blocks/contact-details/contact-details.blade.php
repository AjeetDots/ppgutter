<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}">
  <div class="container-xxl">
    <div class="row g-3 t-white">
      <div class="col-lg-4">
        <div class="col-inner bg-primary p-5 h-100">
          @if ($opening_hours = get_field('opening_hours', 'option') ?? false)
            <h2>{{ __('Working Hours', 'sage') }}</h2>
            {!! apply_filters('the_content_lite', $opening_hours) ?? '' !!}
          @endif
        </div>
      </div>
      <div class="col-lg-4">
        <div class="col-inner bg-dark p-5 h-100">
          <h2>{{ __('Contact Details', 'sage') }}</h2>
          @if ($location = get_field('locations', 'option') ?? false)
            <a class="t-white d-flex ai-start mb-4"><span>{!! App\helpers()->getSvg([ 'icon' => 'location', 'width' => 18, 'height' => 18]) !!}</span> <span class="d-block ps-3 "><strong>{{ __('Headquarters office', 'sage') }}</strong> <br>{!! $location[0]['location'] !!}</span></a>
          @endif
            <p class="t-white d-flex ai-start mb-4">
              <span>{!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]) !!}</span>
              <span class="d-block ps-3 "><strong>{{ __('Phone numbers', 'sage') }}</strong>
                @if ($phone = get_field('phone', 'option') ?? false)
                  <a href="tel:{!! App\helpers()->getSanitisedTel($phone) !!}" class="t-white d-flex ai-center">{!! $phone !!}</a>
                @endif
                @if ($phone_2 = get_field('phone_2', 'option') ?? false)
                  <a href="tel:{!! App\helpers()->getSanitisedTel($phone_2) !!}" class="t-white d-flex ai-center">{!! $phone_2 !!}</a>
                @endif
              </span>
            </p>
          @if ($email = get_field('email', 'option') ?? false)
            <a href="mailto:{!! $email !!}" class="t-white d-flex ai-start"><span>{!! App\helpers()->getSvg([ 'icon' => 'email', 'width' => 18, 'height' => 18]) !!}</span> <span class="d-block ps-3 "><strong>{{ __('Email', 'sage') }}</strong> <br>{!! $email !!}</span></a>
          @endif
        </div>
      </div>
      <div class="col-lg-4">
        <div class="col-inner bg-primary p-5 h-100">
          @if ($pay_your_bill = get_field('pay_your_bill', 'option') ?? false)
            <h2>{{ __('Pay Your Bill', 'sage') }}</h2>
            {!! apply_filters('the_content_lite', $pay_your_bill) ?? '' !!}
          @endif
        </div>
      </div>
    </div>
  </div>
</section>
