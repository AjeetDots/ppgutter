<?php 
$post_type = get_post_type(get_the_ID());
?>
@includeFirst(['partials.content-' . $post_type, 'partials.content'])