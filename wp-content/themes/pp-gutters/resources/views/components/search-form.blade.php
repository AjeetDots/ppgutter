<form id="search-form" role="search" method="get" class="search-form" action="{!! esc_url(home_url( '/' )) !!}">
  <label class="search-input-group p-rel d-flex">
    <input type="search" class="search-field form-control" value="{!! get_search_query() !!}" name="s" placeholder="Search..." />
    <button type="submit" class="btn btn-primary btn-search p-2">{!! App\helpers()->getSvg([ 'icon' => 'search', 'width' => 24, 'height' => 24]) !!}</button>
  </label>
</form>