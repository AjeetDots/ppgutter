<?php
namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\FormBuilder\Components\BaseComponent;
use FluentForm\Framework\Helpers\ArrayHelper;

class FormStep extends BaseComponent
{
    /**
     * Restrict rendered step titles to plain text plus line breaks.
     *
     * @param string $stepTitle
     * @return string
     */
    protected function sanitizeRenderedStepTitle($stepTitle)
    {
        return wp_kses((string) $stepTitle, ['br' => []]);
    }

    /**
     * Normalize saved step titles so legacy empty titles still produce usable labels.
     *
     * @param array $stepTitles
     * @return array
     */
    protected function normalizeStepTitles($stepTitles)
    {
        return array_map(function ($title, $index) {
            $title = (string) $title;

            if (trim(wp_strip_all_tags($title)) !== '') {
                return $title;
            }

            return sprintf(__('Step %d', 'fluentformpro'), $index + 1);
        }, array_values((array) $stepTitles), array_keys(array_values((array) $stepTitles)));
    }

    /**
     * Build the step navigation list HTML.
     *
     * @param array $stepTitles
     * @return string
     */
    protected function compileStepTitles($stepTitles)
    {
        $items = [];

        foreach ($stepTitles as $index => $stepTitle) {
            $classes = $index === 0 ? 'ff_active' : '';
            $items[] = '<li class="' . esc_attr($classes) . '"><span class="ff-step-title-text">' . $this->sanitizeRenderedStepTitle($stepTitle) . '</span></li>';
        }

        return implode('', $items);
    }

    /**
     * Build reusable progress bar markup for step navigation.
     *
     * @param array  $stepTitles
     * @param string $wrapperClass
     * @return string
     */
    protected function compileProgressBar($stepTitles, $wrapperClass = '')
    {
        $classes = trim('ff-step-progress-wrap ' . $wrapperClass);
        $progressBar = "<div class='ff-el-progress'><div role='progressbar' class='ff-el-progress-bar'><span></span></div></div>";
        $progressStatus = "<div class='ff-el-progress-status' aria-live='polite'></div>";

        if ($wrapperClass === 'ff-step-progress-wrap--tabs') {
            $markup = $progressBar . $progressStatus;
        } else {
            $markup = $progressStatus . $progressBar;
        }

        return "<div class='" . esc_attr($classes) . "'>"
            . $markup
            . "<ul style='display: none' class='ff-el-progress-title'><li>"
            . implode('</li><li>', array_map([$this, 'sanitizeRenderedStepTitle'], $stepTitles))
            . "</li></ul></div>";
    }

    /**
     * Compile and echo step header
     * @param array $data [element data]
     * @param object $form [Form Object]
     * @return void
     */
    public function stepStart($data, $form)
    {
        if (!$data) {
            return;
        }

        $stepTitles = $this->normalizeStepTitles(ArrayHelper::get($data, 'settings.step_titles', []));
        $stepTitles = (array) apply_filters('fluentform/step_form_navigation_title', $stepTitles, $form);

        $rawProgressIndicator = ArrayHelper::get($data, 'settings.progress_indicator', '');
        $progressIndicator = $rawProgressIndicator === 'steps_with_nav' ? 'tabs' : $rawProgressIndicator;

        $progressLayout = ArrayHelper::get($data, 'settings.progress_layout', 'top');
        $tabsShowProgressBar = ArrayHelper::get($data, 'settings.tabs_show_progress_bar', 'no');

        $headerClasses = ['ff-step-header'];

        $stepNavLabel = esc_attr__('Form steps', 'fluentformpro');

        if ($progressIndicator === 'steps') {
            $nav = "<ul class='ff-step-titles ff-step-titles-navs ff-step-titles--clickable' role='group' aria-label='{$stepNavLabel}' aria-live='polite'>"
                . $this->compileStepTitles($stepTitles)
                . "</ul>";
        } elseif ($progressIndicator === 'tabs') {
            $headerClasses[] = 'ff-step-header--tabs';
            $headerClasses[] = 'ff-step-header--tabs-' . sanitize_html_class($progressLayout);
            $nav = "<ul class='ff-step-titles ff-step-titles-navs ff-step-titles--clickable' role='group' aria-label='{$stepNavLabel}' aria-live='polite'>"
                . $this->compileStepTitles($stepTitles)
                . "</ul>";

            if ($tabsShowProgressBar === 'yes') {
                $nav .= $this->compileProgressBar($stepTitles, 'ff-step-progress-wrap--tabs');
            }

        } elseif ($progressIndicator === 'progress-bar') {
            $headerClasses[] = 'ff-step-header--progress';
            $nav = $this->compileProgressBar($stepTitles);
        } else {
            $nav = '';
        }

        $data['attributes']['data-disable_auto_focus'] = ArrayHelper::get($data, 'settings.disable_auto_focus', 'no');
        $data['attributes']['data-enable_auto_slider'] = ArrayHelper::get($data, 'settings.enable_auto_slider', 'no');
        $data['attributes']['data-enable_step_data_persistency'] = ArrayHelper::get($data, 'settings.enable_step_data_persistency', 'no');
        $data['attributes']['data-enable_step_page_resume'] = ArrayHelper::get($data, 'settings.enable_step_page_resume', 'no');
        $data['attributes']['data-animation_type'] = ArrayHelper::get($data, 'settings.step_animation', 'slide');
        $data['attributes']['data-progress_layout'] = $progressLayout;
        $data['attributes']['data-progress_indicator'] = $rawProgressIndicator;
        $atts = $this->buildAttributes(
            \FluentForm\Framework\Helpers\ArrayHelper::except($data['attributes'], 'name')
        );

        echo "<div class='ff-step-container' {$atts}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via buildAttributes
        if ($nav) {
            echo "<div class='" . esc_attr(implode(' ', $headerClasses)) . "'>{$nav}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Step navigation HTML
        }

        echo "<span class='ff_step_start'></span><div class='ff-step-body'>";
        $data['attributes']['class'] = ($data['attributes']['class'] ?? '') . ' fluentform-step';
        $data['attributes']['class'] = trim($data['attributes']['class']) . ' active';
        $atts = $this->buildAttributes(
            \FluentForm\Framework\Helpers\ArrayHelper::except($data['attributes'], 'name')
        );
        echo "<div {$atts}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via buildAttributes
    }

    /**
     * Compile and echo the html element
     * @param array $data [element data]
     * @param stdClass $form [Form Object]
     * @return void
     */
    public function compile($data, $form)
    {
        echo $this->compileButtons($data['settings']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Step button HTML
        $data['attributes']['class'] = ($data['attributes']['class'] ?? '') . ' fluentform-step';
        $atts = $this->buildAttributes(
            \FluentForm\Framework\Helpers\ArrayHelper::except($data['attributes'], 'name')
        );
        echo "</div><div style='display: none;' {$atts}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via buildAttributes
    }

    /**
     * Compile and echo step footer
     * @param array $data [element data]
     * @param stdClass $form [Form Object]
     * @return void
     */
    public function stepEnd($data, $form)
    {
        $btnPrev = $this->compileButtons($data['settings']);
        ?>
        <div class="ff-step-t-container ff-inner_submit_container ff-column-container ff_columns_total_2">
            <div class="ff-t-cell ff-t-column-1"><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $btnPrev; ?></div>
            <div class="ff-t-cell ff-t-column-2">
                <?php
                do_action('fluentform/render_item_submit_button', $form->fields['submitButton'], $form);
                ?>
            </div>
        </div>
        </div></div></div>
        <?php
    }

    /**
     * Compile next and prev buttons
     * @param array $data [element data]
     * @return void
     */
    protected function compileButtons($data)
    {
        $btnPrev = $btnNext = '';
        $prev = isset($data['prev_btn']) ? $data['prev_btn'] : null;
        $next = isset($data['next_btn']) ? $data['next_btn'] : null;

        if ($prev) {
            if ($prev['type'] == 'default') {
                $tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex();
                $tabIndexHtml = '';
                if($tabIndex) {
                    $tabIndexHtml = "tabindex='".$tabIndex."' ";
                }

                $btnClass = apply_filters('fluentform/step_prev_button_class', 'ff-btn ff-btn-prev ff-btn-secondary', $data);

                $prevAriaLabel = wp_strip_all_tags(fluentform_sanitize_html($prev['text']));
                $btnPrev = "<button style='float: left;' " . $tabIndexHtml . " type='button' data-action='prev' class='" . esc_attr($btnClass) . "' aria-label='" . esc_attr($prevAriaLabel) . "'>" . fluentform_sanitize_html($prev['text']) . "</button>";
            } else {
                $alt = esc_attr(ArrayHelper::get($prev,'img_alt'));
                $btnPrev = "<img style='float: left;' data-action='prev' alt='{$alt}' class='prev ff-btn-prev ff_pointer' src='" . esc_url($prev['img_url']) . "'>";
            }
        }

        if ($next) {
            if ($next['type'] == 'default') {
                $tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex();
                $tabIndexHtml = '';
                if($tabIndex) {
                    $tabIndexHtml = "tabindex='".$tabIndex."' ";
                }

                $btnClass = apply_filters('fluentform/step_next_button_class', 'ff-float-right ff-btn ff-btn-next ff-btn-secondary', $data);

                $nextAriaLabel = wp_strip_all_tags(fluentform_sanitize_html($next['text']));
                $btnNext = "<button style='float: right;' " . $tabIndexHtml . " type='button' data-action='next' class='" . esc_attr($btnClass) . "' aria-label='" . esc_attr($nextAriaLabel) . "'>" . fluentform_sanitize_html($next['text']) . "</button>";
            } else {
                $alt = esc_attr(ArrayHelper::get($next,'img_alt'));
                $btnNext = "<img style='float: right;' data-action='next' alt='{$alt}' class='next ff-btn-next ff_pointer' src='" . esc_url($next['img_url']) . "'>";
            }
        }

        return "<div class='step-nav ff_step_nav_last'>{$btnPrev}{$btnNext}</div>";
    }
}
