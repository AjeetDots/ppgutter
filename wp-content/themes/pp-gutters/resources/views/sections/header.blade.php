@includeIf('components.header-icons')
<header id="global-header" class="sticky-top bg-white">
  <aside class="preheader bg-dark p-clamp-x py-3">
    <div class="container-xxl t-white d-flex ai-center jc-between">
      @if ($stars = get_field('stars', 'option') ?? false)
        <div class="trust-pilot d-flex ai-start align-items-xl-center">
          <div class="d-flex flex-column flex-xl-row me-3 me-xl-0 ai-center">
            {{ __('Google rating:', 'sage') }}
            {!! wp_get_attachment_image($stars, 'full', '', ['class' => 'mx-1 mx-md-2']) !!}
          </div>
          @if ($rating = get_field('rating', 'option') ?? false)
            {!! $rating !!}
          @endif
        </div>
      @endif
      @if (has_nav_menu('social_navigation'))
        <div id="box-socials" class="social d-none d-lg-block">
          <nav class="nav-social">
            {!! wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex jc-center']) !!}
          </nav>
        </div>
      @endif
      <div id="box-buttons" class="contact-box d-flex-column flex-lg-row ai-center d-none d-xxl-block">
        @if ($email = get_field('email', 'option') ?? false)
          <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="mailto:{!! $email !!}">
            {!! App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]) !!}
            <span class="ms-2">{!! $email !!}</span>
          </a>
        @endif
        @if ($phone = get_field('phone', 'option') ?? false)
          <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="tel:{!! App\helpers()->getSanitisedTel($phone) !!}">
            {!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]) !!}
            <span class="ms-2">{!! $phone !!}</span>
          </a>
        @endif
        @if ($phone_2 = get_field('phone_2', 'option') ?? false)
          <a class="btn btn-dark btn-sm" href="tel:{!! App\helpers()->getSanitisedTel($phone_2) !!}">
            {!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]) !!}
            <span class="ms-2">{!! $phone_2 !!}</span>
          </a>
        @endif
      </div>
      <div class="box-mobile d-flex d-xxl-none">
        @if ($email = get_field('email', 'option') ?? false)
          <a class="btn-header me-2" href="mailto:{!! $email !!}">
            {!! App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]) !!}
          </a>
        @endif
        @if ($phone = get_field('phone', 'option') ?? false)
          <a class="btn-header" href="tel:{!! App\helpers()->getSanitisedTel($phone) !!}">
            {!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]) !!}
          </a>
        @endif
      </div>
    </div>
  </aside>
  <section class="main-header p-clamp-x py-2">
    <div class="container-xxl d-flex jc-between ai-center">
      {!! $site_logo !!}
      <div class="navbar navbar-expand-lg">
        <a href="/search" class="btn-search-mobile d-lg-none bg-primary d-flex flex-center me-3">{!! App\helpers()->getSvg([ 'icon' => 'search', 'width' => 24, 'height' => 24]) !!}</a>
        @includeIf('components.header-offcanvas-toggler')
        <div class="offcanvas offcanvas-start-lg" tabindex="-1" id="offcanvasExample" aria-labelledby="offcanvasExampleLabel">
          <div class="offcanvas-body ai-center">
            @includeIf('components.header-offcanvas-nav')
            @if (has_nav_menu('social_navigation'))
              <div id="box-socials" class="social d-lg-none my-5">
                <nav class="nav-social">
                  {!! wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex jc-center']) !!}
                </nav>
              </div>
            @endif
            <div id="box-buttons" class="contact-box d-flex-column flex-lg-row ai-center d-lg-none">
              @if ($email = get_field('email', 'option') ?? false)
                <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="mailto:{!! $email !!}">
                  {!! App\helpers()->getSvg([ 'icon' => 'email', 'width' => 16, 'height' => 16]) !!}
                  <span class="ms-2">{!! $email !!}</span>
                </a>
              @endif
              @if ($phone = get_field('phone', 'option') ?? false)
                <a class="btn btn-dark btn-sm me-lg-2 mb-3 mb-lg-0" href="tel:{!! App\helpers()->getSanitisedTel($phone) !!}">
                  {!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]) !!}
                  <span class="ms-2">{!! $phone !!}</span>
                </a>
              @endif
              @if ($phone_2 = get_field('phone_2', 'option') ?? false)
                <a class="btn btn-dark btn-sm" href="tel:{!! App\helpers()->getSanitisedTel($phone_2) !!}">
                  {!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 16, 'height' => 16]) !!}
                  <span class="ms-2">{!! $phone_2 !!}</span>
                </a>
              @endif
            </div>
          </div>
        </div>
      </div>
      <div class="search-box d-none d-lg-block">@includeIf('components.search-form')</div>
    </div>
  </section>
</header>
