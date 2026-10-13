(function($) {
  initSliders();
  $(document).on('change', function() {
    destroySliders();
    initSliders();
  });

  // Initialize dynamic block preview (editor).
  function initSliders() {
    if ( window.acf ) {
      window.acf.addAction( 'render_block_preview', function() {
        if (typeof sliderId !== 'undefined') {
          $('#slider-locations').not('.slick-initialized').slick();
        }
      });
    }
  }

  // Destroy slider is needed because the slider needs to be rebuilt after every change.
  function destroySliders() {
    if (typeof sliderId !== 'undefined') {
      $('#slider-locations').slick('unslick');
    }
  }

})(jQuery);
