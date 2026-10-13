<?php defined('ABSPATH') or die; ?>
<div class="ffp_submission_header">
    <div class="ffp_submission_message">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo fluentform_sanitize_html($header_content); ?>
    </div>
</div>
