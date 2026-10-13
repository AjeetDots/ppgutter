@includeIf('components.global-cta')
<footer>
  <section class="p-clamp-sm bg-primary">
    <div class="container-xxl">
      <div class="row g-5 t-white">
        <div class="col-md-6 col-lg-3 d-flex flex-column flex-md-column-reverse">
          <div class="box-logo-hours mb-5 mb-md-0">
            @if ($logo_white = get_field('logo_white', 'option') ?? false)
              <a href="/" aria-label="Logo PP Gutters Ltd.">{!! wp_get_attachment_image($logo_white, 'full', '', ['class' => 'mb-4']) !!}</a>
            @endif
            @if ($opening_hours = get_field('opening_hours', 'option') ?? false)
              {!! apply_filters('the_content_lite', $opening_hours) ?? '' !!}
            @endif
          </div>
          @if (has_nav_menu('footer_navigation'))
          <div class="box-footer-nav mb-4">
            <h3 class="h5 mb-4">{{ __('Useful Pages', 'sage') }}</h3>
            <nav class="nav-footer">
              {!! wp_nav_menu(['theme_location' => 'footer_navigation', 'menu_class' => 'nav d-flex-column']) !!}
            </nav>
          </div>
          @endif
        </div>
        @if (is_nav_menu('Menu Services'))
        <div class="col-md-6 col-lg-3">
          <h3 class="h5 mb-4">{{ __('Our Services', 'sage') }}</h3>
          <nav class="nav-about">
            {!! wp_nav_menu(['menu' => 'Menu Services', 'menu_class' => 'nav d-flex-column']) !!}
          </nav>
        </div>
        @endif
        @if (is_nav_menu('Menu Areas'))
          <div class="col-md-6 col-lg-3">
            <h3 class="h5 mb-4">{{ __('Areas We Cover', 'sage') }}</h3>
            <nav class="nav-about">
              {!! wp_nav_menu(['menu' => 'Menu Areas', 'menu_class' => 'nav d-flex-column']) !!}
            </nav>
          </div>
        @endif
        <div class="col-md-6 col-lg-3 box-connect d-flex-column">
          <h3 class="h5 mb-4">{{ __('Contact us', 'sage') }}</h3>
          @if ($email = get_field('email', 'option') ?? false)
            <a href="mailto:{!! $email !!}" class="t-white mb-2 d-flex ai-center">{!! App\helpers()->getSvg([ 'icon' => 'email', 'width' => 18, 'height' => 18]) !!} <span class="ms-2">{!! $email !!}</span></a>
          @endif
          @if ($phone = get_field('phone', 'option') ?? false)
            <a href="tel:{!! App\helpers()->getSanitisedTel($phone) !!}" class="t-white mb-3 d-flex ai-center">{!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]) !!} <span class="ms-2">{!! $phone !!}<span class="ms-2"></a>
          @endif
          @if ($phone_2 = get_field('phone_2', 'option') ?? false)
            <a href="tel:{!! App\helpers()->getSanitisedTel($phone_2) !!}" class="t-white mb-3 d-flex ai-center">{!! App\helpers()->getSvg([ 'icon' => 'phone', 'width' => 18, 'height' => 18]) !!} <span class="ms-2">{!! $phone_2 !!}<span class="ms-2"></a>
          @endif
          @if (has_nav_menu('social_navigation'))
            <div id="box-socials" class="social">
              <nav class="nav-social">
                {!! wp_nav_menu(['theme_location' => 'social_navigation', 'menu_class' => 'nav menu-social-icons d-flex']) !!}
              </nav>
            </div>
          @endif
        </div>
      </div>
    </div>
  </section>
  <section class="copyright p-clamp-x bg-white">
    <div class="container-xxl d-flex flex-column flex-md-row ai-center jc-between py-2">
      PP Gutters Ltd {{ __('© Copyrights 2026', 'sage') }}
    </div>
  </section>
</footer>

@includeIf('components.footer-icons')
