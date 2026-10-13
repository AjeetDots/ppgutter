{{-- Offcanvas Nav Menu --}}
<nav class="nav-primary d-block collapse navbar-collapse" id="collapsePrimaryNav">
  <button type="button" class="btn-close btn-close-white text-reset d-lg-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  @if (has_nav_menu('primary_navigation')){!! wp_nav_menu(['theme_location' => 'primary_navigation', 'menu_class' => 'navbar-nav']) !!} @endif
</nav>
