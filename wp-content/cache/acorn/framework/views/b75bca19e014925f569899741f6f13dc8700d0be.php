<form id="search-form" role="search" method="get" class="search-form" action="<?php echo esc_url(home_url( '/' )); ?>">
  <label class="search-input-group p-rel d-flex">
    <input type="search" class="search-field form-control" value="<?php echo get_search_query(); ?>" name="s" placeholder="Search..." />
    <button type="submit" class="btn btn-primary btn-search p-2"><?php echo App\helpers()->getSvg([ 'icon' => 'search', 'width' => 24, 'height' => 24]); ?></button>
  </label>
</form><?php /**PATH /home/u741107087/domains/ppgutters.co.uk/public_html/wp-content/themes/pp-gutters/resources/views/components/search-form.blade.php ENDPATH**/ ?>