<?php
$bg             = $block['backgroundColor'] ?? '';
$allowed_blocks = ['core/heading', 'core/paragraph', 'core/buttons', 'core/image', 'core/list', 'core/list-item', 'core/video', 'core/embed', 'trustindex/block-selector'];
$template       = [
  ['core/heading', ["placeholder" => "Block Title Goes Here", 'textColor' => 'black']],
  ['core/paragraph', ["placeholder" => "Some content goes here as the paragraph.", 'textColor' => 'black']],
  ['core/buttons', ['contentJustification' => 'center'], [['core/button', ['text' => 'Button Text', 'url' => '#', 'className' => 'is-style-fill', 'backgroundColor' => 'primary']]]]
];
?>

<div class="{!! ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], '--') : 'col-lg-6' !!}">
  <InnerBlocks
    class="col-inner {!! ($block['backgroundColor'] ?? false) ? 'bg-' . $bg : '' !!} {!! (($block['backgroundColor'] ?? false) && ($block['backgroundColor'] !== 'white')) ? 'p-4 p-lg-5' : '' !!} p-rel {!! ($block['className'] ?? false) ? App\helpers()->getClassesFromString($block['className'], 'card-inner--', false) : '' !!}"
    allowedBlocks="{!! esc_attr(wp_json_encode($allowed_blocks)) !!}"
    template="{!! esc_attr(wp_json_encode($template)) !!}"
  />
</div>
