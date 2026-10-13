<?php
$allowed_blocks = ['acf/module-partial-additional-cards', 'core/heading', 'core/paragraph', 'core/buttons'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", "textColor" => "black", "textAlign" => "center"]],
  ['core/paragraph', ["placeholder" => "This is some paragraph text that goes here.", "textColor" => "black", "className" => "has-text-align-center"]],
  ['acf/module-partial-additional-cards'],
  ['core/buttons', ['contentJustification' => 'center', 'className' => 'mt-5'], [['core/button', ['text' => 'Button Text',  'url' => '#', 'className' => 'is-style-fill btn-full-mobile', 'backgroundColor' => 'primary']]]]
];
?>

<section @if (!empty($block['anchor'])) id="{!! $block['anchor'] !!}" @endif
  class="block-{!! App\Helpers()->kebabCase($block['title']) !!} p-clamp {!! $block['className'] ?? '' !!}">
  <InnerBlocks
    class="container-xxl"
    allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
    template="{!! esc_attr(wp_json_encode($template)) !!}"
  />
</section>
