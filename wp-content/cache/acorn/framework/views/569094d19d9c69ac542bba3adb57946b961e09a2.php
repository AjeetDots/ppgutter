<aside class="prefooter p-clamp-sm bg-dark">
  <div class="container-xxl">
    <div class="row ai-center g-5">
      <?php if($global_cta_text = get_field('global_cta_text', 'option') ?? false): ?>
        <div class="col-lg-7 t-white">
          <?php echo apply_filters('the_content_lite', $global_cta_text) ?? ''; ?>

        </div>
      <?php endif; ?>
      <div class="col-lg-5 d-flex flex-column flex-md-row justify-content-lg-end">
        <?php if($global_link_appointment = get_field('global_link_appointment', 'option') ?? false): ?>
          <?php echo App\Helpers()->getButtonHtml($global_link_appointment, 'btn btn-dark me-md-3 mb-3 mb-md-0' ); ?>

        <?php endif; ?>
        <?php if($global_link_contact = get_field('global_link_contact', 'option') ?? false): ?>
          <?php echo App\Helpers()->getButtonHtml($global_link_contact, 'btn btn-primary btn-primary-inv' ); ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</aside>
<aside class="block-rated p-clamp">
  <div class="container-xxl">
    <div class="row g-5">
      <div class="col-lg-6">
        <?php if($cw_title = get_field('cw_title', 'option') ?? false): ?>
          <h2><?php echo $cw_title; ?></h2>
        <?php endif; ?>
        <div class="d-flex">
          <?php if($cw_image = get_field('cw_image', 'option') ?? false): ?>
            <?php echo wp_get_attachment_image($cw_image, 'full', '', ['class'=>'img-rated me-5 w-100 h-100']); ?>

          <?php endif; ?>
          <?php if($cw_content = get_field('cw_content', 'option') ?? false): ?>
            <div class="box-content">
              <?php echo apply_filters('the_content_lite', $cw_content)   ?? ''; ?>

            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6">
        <?php if($tbr_title = get_field('tbr_title', 'option') ?? false): ?>
          <h2><?php echo $tbr_title; ?></h2>
        <?php endif; ?>
          <div class="d-flex">
            <?php if($tbr_image = get_field('tbr_image', 'option') ?? false): ?>
              <?php echo wp_get_attachment_image($tbr_image, 'full', '', ['class'=>'img-rated me-5 w-100 h-100']); ?>

            <?php endif; ?>
            <?php if($tbr_content = get_field('tbr_content', 'option') ?? false): ?>
              <div class="box-content">
                <?php echo apply_filters('the_content_lite', $tbr_content)   ?? ''; ?>

              </div>
            <?php endif; ?>
          </div>
      </div>
    </div>
  </div>
</aside>
<?php /**PATH /var/www/html/wp-content/themes/pp-gutters/resources/views/components/global-cta.blade.php ENDPATH**/ ?>