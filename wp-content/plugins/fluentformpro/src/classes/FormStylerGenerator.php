<?php

namespace FluentFormPro\classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class FormStylerGenerator
{

    public function generateFormCss($parentSelector, $styles)
    {
        $normalTypes = [
            'container_styles' => $parentSelector,
            'label_styles' => $parentSelector . ' .ff-el-input--label label',
            'placeholder_styles' => $parentSelector . ' .ff-el-input--content input::placeholder, ' . $parentSelector . ' .ff-el-input--content textarea::placeholder',
            'asterisk_styles' => $parentSelector . ' .asterisk-right label:after, ' . $parentSelector . ' .asterisk-left label:before',
            'inline_error_msg_style' => $parentSelector . ' .ff-el-input--content .error , ' . $parentSelector . ' .error-text',
            'success_msg_style' => $parentSelector . ' .ff-message-success',
            'error_msg_style' => $parentSelector . ' .ff-errors-in-stack '
        ];
        $cssCodes = '';
        if (empty($styles)) {
            return $cssCodes;
        }

        //pass input label border styke to net promoter
        foreach ($styles as $styleKey => $style) {
            if (isset($normalTypes[$styleKey])) {
                $cssCodes .= $this->generateNormal($style, $normalTypes[$styleKey]);
            } else if ($styleKey == 'input_styles') {
                $cssCodes .= $this->generateInputStyles($style, $parentSelector);
            } else if ($styleKey == 'sectionbreak_styles') {
                $cssCodes .= $this->generateSectionBreak($style, $parentSelector);
            } else if ($styleKey == 'gridtable_style') {
                $cssCodes .= $this->generateGridTable($style, $parentSelector);
            } else if ($styleKey == 'payment_summary_style') {
                $cssCodes .= $this->generatePaymentSummary($style, $parentSelector);
            } else if ($styleKey == 'payment_coupon_style') {
                $cssCodes .= $this->generatePaymentCoupon($style, $parentSelector);
            } else if ($styleKey == 'image_or_file_button_style') {
                $cssCodes .= $this->generateImageOrFileButtonStyle($style, $parentSelector);
            } else if ($styleKey == 'submit_button_style') {
                $cssCodes .= $this->generateSubmitButton($style, $parentSelector);
            } else if ($styleKey == 'radio_checkbox_style') {
                $cssCodes .= $this->generateSmartCheckable($style, $parentSelector);
            } else if ($styleKey == 'next_button_style') {
                $cssCodes .= $this->generateNextButton($style, $parentSelector);
            } else if ($styleKey == 'prev_button_style') {
                $cssCodes .= $this->generatePrevButton($style, $parentSelector);
            } else if ($styleKey == 'step_header_style') {
                $cssCodes .= $this->generateStepHeader($style, $parentSelector);
            } else if ($styleKey == 'net_promoter_style') {
                $cssCodes .= $this->generateNetPromoter($style, $parentSelector);
            } else if ($styleKey == 'range_slider_style') {
                $cssCodes .= $this->generateRangeSliderStyle($style, $parentSelector);
            }
        }

        return $cssCodes;
    }

    /*
     * For the following
     * - container_styles
     * - label_styles
     * - placeholder_styles
     * - asterisk_styles
     * - help_msg_style
     * - success_msg_style
     * - error_msg_style
     */
    public function generateNormal($styles, $selector)
    {
        $css = '';
        foreach ($styles as $styleKey => $style) {
            $css .= $this->extrachStyle($style, $styleKey, '');
        }
        if ($css && $selector) {
            return $selector . '{ ' . $css . ' } ';
        }
        return $css;
    }

    public function generateSectionBreak($item, $selector)
    {
        $titleStyles = Arr::get($item, 'all_tabs.tabs.LabelStyling.value');
        $titleCss = $this->generateNormal($titleStyles, '');
        if ($titleCss) {
            $titleCss = "{$selector} .ff-el-section-break .ff-el-section-title { {$titleCss} }";
        }
        $focusStyles = Arr::get($item, 'all_tabs.tabs.DescriptionStyling.value');
        $descCss = $this->generateNormal($focusStyles, '');
        $customHTMLCss = $descCss;
        if ($descCss) {
            $descCss = "{$selector} .ff-el-section-break div.ff-section_break_desk { {$descCss} }";
            $customHTMLCss = "{$selector} .ff-custom_html { {$customHTMLCss} }";
        }
        return $titleCss . ' ' . $descCss . ' ' . $customHTMLCss;
    }

    public function generateGridTable($item, $selector)
    {
        $styleSelector = $selector . ' .ff-el-input--content table.ff-table.ff-checkable-grids thead tr th';
        $theadStyles = Arr::get($item, 'all_tabs.tabs.TableHead.value');
        $normalCss = $this->generateNormal($theadStyles, '');
        if ($normalCss) {
            $normalCss = "{$styleSelector} { {$normalCss} }";
        }
        $tbodyStyles = Arr::get($item, 'all_tabs.tabs.TableBody.value');
        $tbodyCss = $this->generateNormal($tbodyStyles, '');
        if ($tbodyCss) {
            $styleDescSelector = $selector . ' .ff-el-input--content table.ff-table.ff-checkable-grids tbody tr td';
            $tbodyCss = "$styleDescSelector { {$tbodyCss} }";
        }
        if ($oddColor = Arr::get($tbodyStyles, 'oddColor')) {
            if ($value = Arr::get($oddColor, 'value')) {
                $tbodyCss .= "{$selector} .ff-checkable-grids tbody > tr:nth-child(2n) > td{background-color:{$value}!important}";
            }
        }
        return $normalCss . ' ' . $tbodyCss;
    }

    public function generatePaymentSummary($item, $selector)
    {
        $styleSelector = $selector . ' .ff-el-group .ff_payment_summary table thead tr th';
        $theadStyles = Arr::get($item, 'all_tabs.tabs.TableHead.value');
        $normalCss = $this->generateNormal($theadStyles, '');
        if ($normalCss) {
            $normalCss = "{$styleSelector} { {$normalCss} }";
        }
        $tbodyStyles = Arr::get($item, 'all_tabs.tabs.TableBody.value');
        $tbodyCss = $this->generateNormal($tbodyStyles, '');
        if ($tbodyCss) {
            $styleDescSelector = $selector . ' .ff-el-group .ff_payment_summary table tbody tr td';
            $tbodyCss = "$styleDescSelector { {$tbodyCss} }";
        }
        $tfootStyles = Arr::get($item, 'all_tabs.tabs.TableFooter.value');
        $tfootCss = $this->generateNormal($tfootStyles, '');
        if ($tfootCss) {
            $styleDescSelector = $selector . ' .ff-el-group .ff_payment_summary table tfoot tr th';
            $tfootCss = "$styleDescSelector { {$tfootCss} }";
        }
        return $normalCss . ' ' . $tbodyCss . ' ' . $tfootCss;
    }

    public function generatePaymentCoupon($item, $selector)
    {
        $styleSelector = $selector . ' .ff-el-group .ff-el-input--content .ff_input-group';

        $buttonStyles = $this->generateNormal(Arr::get($item, 'all_tabs.tabs.button.value'),'');
        $hoverStyles = $this->generateNormal(Arr::get($item, 'all_tabs.tabs.buttonHover.value'), '');

        if ($buttonStyles) {
            $buttonStyles = "{$styleSelector} .ff_input-group-append span { {$buttonStyles} }";
        }
        if ($hoverStyles) {
            $hoverStyles = "{$styleSelector} .ff_input-group-append span:hover { {$hoverStyles} }";
        }

        return $buttonStyles . ' ' . $hoverStyles;
    }

    public function generateImageOrFileButtonStyle($item, $selector)
    {
        $styleSelector = $selector . ' .ff-el-group .ff-el-input--content .ff_file_upload_holder';

        $buttonStyles = $this->generateNormal(Arr::get($item, 'all_tabs.tabs.button.value'),'');
        $hoverStyles = $this->generateNormal(Arr::get($item, 'all_tabs.tabs.buttonHover.value'), '');
        if ($buttonStyles) {
            $buttonStyles = "{$styleSelector} span.ff_upload_btn { {$buttonStyles} }";
        }
        if ($hoverStyles) {
            $hoverStyles = "{$styleSelector} span.ff_upload_btn:hover { {$hoverStyles} }";
        }

        return $buttonStyles . ' ' . $hoverStyles;
    }

    public function generateSubmitButton($style, $selector)
    {
        $stylesAllignment = $this->generateAllignment(Arr::get($style, 'allignment.value'));
        if ($stylesAllignment) {
            $stylesAllignment = "{$selector} .ff-el-group.ff_submit_btn_wrapper { {$stylesAllignment} }";
        }

        $normalStyles = Arr::get($style, 'all_tabs.tabs.normal.value', []);
        $normalCss = $this->generateNormal($normalStyles, '');
        if ($normalCss) {
            $normalCss = "{$selector} .ff_submit_btn_wrapper .ff-btn-submit:not(.ff_btn_no_style) { {$normalCss} }";
        }

        $hoverStyles = Arr::get($style, 'all_tabs.tabs.hover.value', []);
        $hoverCss = $this->generateNormal($hoverStyles, '');
        if ($hoverCss) {
            $hoverCss = "{$selector} .ff_submit_btn_wrapper .ff-btn-submit:not(.ff_btn_no_style):hover { {$hoverCss} }";
        }

        return $stylesAllignment . $normalCss . $hoverCss;
    }

    public function generateNextButton($style, $selector)
    {
        $normalStyles = Arr::get($style, 'all_tabs.tabs.normal.value', []);
        $normalCss = $this->generateNormal($normalStyles, '');
        if ($normalCss) {
            $normalCss = "{$selector} .step-nav .ff-btn-next { {$normalCss} }";
        }

        $hoverStyles = Arr::get($style, 'all_tabs.tabs.hover.value', []);
        $hoverCss = $this->generateNormal($hoverStyles, '');
        if ($hoverCss) {
            $hoverCss = "{$selector} .step-nav .ff-btn-next:hover { {$hoverCss} }";
        }

        return $normalCss . $hoverCss;
    }

    public function generatePrevButton($style, $selector)
    {
        $normalStyles = Arr::get($style, 'all_tabs.tabs.normal.value', []);
        $normalCss = $this->generateNormal($normalStyles, '');
        if ($normalCss) {
            $normalCss = "{$selector} .step-nav .ff-btn-prev { {$normalCss} }";
        }

        $hoverStyles = Arr::get($style, 'all_tabs.tabs.hover.value', []);
        $hoverCss = $this->generateNormal($hoverStyles, '');
        if ($hoverCss) {
            $hoverCss = "{$selector} .step-nav .ff-btn-prev:hover { {$hoverCss} }";
        }

        return $normalCss . $hoverCss;
    }

    public function generateInputStyles($item, $selector)
    {
        $normalStyles = Arr::get($item, 'all_tabs.tabs.normal.value');

        $normalCss = $this->generateNormal($normalStyles, '');
        if ($normalCss) {
            $normalCss = "{$selector} .ff-el-input--content input, {$selector} .ff-el-input--content .ff-el-form-control.ff_stripe_card_element, {$selector} .ff-el-input--content textarea, {$selector} .ff-el-input--content select, {$selector} .choices__list--single, {$selector} .choices[data-type*='select-multiple'] { {$normalCss} }";
            $borderCss = $this->extrachStyle($normalStyles['border'], 'border', '');
            if($borderCss) {
                $normalCss .= " {$selector} .frm-fluent-form .choices__list--dropdown { {$borderCss} }";
            }
        }
        $focusStyles = Arr::get($item, 'all_tabs.tabs.focus.value');
        $focusCss = $this->generateNormal($focusStyles, '');
        if ($focusCss) {
            $focusCss = "{$selector} .ff-el-input--content input:focus, {$selector} .ff-el-input--content .ff-el-form-control.ff_stripe_card_element:focus, {$selector} .ff-el-input--content textarea:focus, {$selector} .ff-el-input--content select:focus { {$focusCss} outline: none !important; }";
        }
        return $normalCss . ' ' . $focusCss;

    }

    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS generation with sanitized admin-only values
    public function generateSmartCheckable($item, $selector)
    {
        $itemColor = Arr::get($item, 'color.value');
        $itemSize = Arr::get($item, 'size.value.value');
        if ($itemSize) {
            $itemSize = $this->getResolveValue($itemSize, Arr::get($item, 'size.value.type'));
        }

        ob_start();
        if ($itemColor) {
            ?>
            <?php echo $selector; ?> .ff-el-form-check, <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check label>span {
            color:  <?php echo $itemColor . ';' ?>
            }
            <?php
        }

        if ($backgroundColor = Arr::get($item, 'backgroundColor.value')) {
            ?>
            <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check label > span {
            background-color:  <?php echo $backgroundColor . ';' ?>
            }
            <?php
        }
        $hasSmartUi = Arr::get($item, 'radio_checkbox.status') === 'yes';
        if ($itemSize && !$hasSmartUi) {
            ?>
            <?php echo $selector; ?> .ff-el-group input[type=checkbox],
            <?php echo $selector; ?> .ff-el-group input[type=radio]
            {
                height:  <?php echo $itemSize . ';' ?>
                width:  <?php echo $itemSize . ';' ?>
            }
            <?php
        }
        if (!$hasSmartUi) {
            return ob_get_clean();
        }
        if (!$itemSize) {
            $itemSize = '15px';
        }
        $smartUiMarginStyle = 'margin-left: 3px;';
        if ($smartUIMargin =  Arr::get($item, 'radio_checkbox.value.margin.value')) {
            if ($marginStyle = $this->generateAroundDimention('margin', $smartUIMargin)) {
                $smartUiMarginStyle = $marginStyle;
            }
        }

        $normalColor = Arr::get($item, 'radio_checkbox.value.color.value');
        $checkedColor = Arr::get($item, 'radio_checkbox.value.active_color.value');
        $customBorderOn = Arr::get($item, 'radio_checkbox.value.border.value.status') == 'yes';
        $borderColor = $normalColor;
        $borderWidthStyle = 'border-width:1px;';
        $borderRadiusStyle = 'border-radius:2px;';
        $borderType = 'solid';
        $radioBorderSameAsCheckbox = false;
    
        if ($customBorderOn == 'yes') {
            $borderColor = Arr::get($item, 'radio_checkbox.value.border.value.border_color');
            $borderWidthStyle = $this->generateAroundDimentionBorder(Arr::get($item, 'radio_checkbox.value.border.value.border_width'));
            $borderRadiusStyle = $this->generateAroundDimentionBorderRadius('border', Arr::get($item, 'radio_checkbox.value.border.value.border_radius'));
            $borderType = Arr::get($item, 'radio_checkbox.value.border.value.border_type');
            if ("yes" === Arr::get($item, 'radio_checkbox.value.border.value.radio_border_status')) {
                $radioBorderSameAsCheckbox = true;
            }
        }
        if (!$checkedColor) {
            $checkedColor = 'black';
        }
        if (!$normalColor) {
            $normalColor = 'black';
        }

        ?>
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:not(.ff_list_buttons input[type=checkbox]),
        <?php echo $selector; ?> .ff-el-group input[type=radio]:not(.ff_list_buttons input[type=radio]) {
        -webkit-transform: scale(1);
        transform: scale(1);
        width: 21px;
        height: 15px;
        margin-right: 0px;
        cursor: pointer;
        font-size: 12px;
        position: relative;
        text-align: left;
        border: none;
        box-shadow: none;
        vertical-align: middle;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox] {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        }
        <?php echo $selector; ?> .ff-el-group input[type=radio] {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:before,
        <?php echo $selector; ?> .ff-el-group input[type=radio]:before {
        content: none;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:after,
        <?php echo $selector; ?> .ff-el-group input[type=radio]:after {
        content: " ";
        background-color: #fff;
        display: inline-block;
        <?php echo $smartUiMarginStyle;?>
        padding-bottom: 3px;
        color: #212529;
        width: <?php echo $itemSize . ';' ?>;
        height: <?php echo $itemSize . ';' ?>;
        border-color: <?php echo $borderColor . ';' ?>;
        border-style: <?php echo $borderType . ';' ?>;
        <?php echo $borderWidthStyle?>
        padding-left: 1px;
        <?php echo $borderRadiusStyle ?>
        padding-top: 1px;
        -webkit-transition: all .1s ease;
        -moz-transition: all .1s ease;
        -o-transition: all .1s ease;
        transition: all .1s ease;
        background-size: 9px;
        background-repeat: no-repeat;
        background-position: center center;
        position: absolute;
        top: 50%;
        left: 0;
        -webkit-transform: translateY(-50%);
        -moz-transform: translateY(-50%);
        -ms-transform: translateY(-50%);
        -o-transform: translateY(-50%);
        transform: translateY(-50%);
        box-sizing: border-box;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:checked:after, <?php echo $selector; ?> .ff-el-group input[type=radio]:checked:after {
        <?php echo $borderWidthStyle?>
        background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3E%3Cpath fill='%23fff' d='M6.564.75l-3.59 3.612-1.538-1.55L0 4.26 2.974 7.25 8 2.193z'/%3E%3C/svg%3E");
        background-color: <?php echo $checkedColor . ';' ?>;
        -webkit-transition: all 0.3s ease-out;
        -moz-transition: all 0.3s ease-out;
        -o-transition: all 0.3s ease-out;
        transition: all 0.3s ease-out;
        color: #fff;
        border-color: <?php echo $checkedColor . ';' ?>;
        } <?php echo $selector; ?> .ff-el-group input[type=radio]:after {
        <?php echo $radioBorderSameAsCheckbox ? $borderRadiusStyle : 'border-radius: 50%;'?>
        font-size: 10px;
        padding-top: 1px;
        padding-left: 2px;
        } <?php echo $selector; ?> .ff-el-group input[type=radio]:checked:after {
        background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3E%3Ccircle r='3' fill='%23fff'/%3E%3C/svg%3E");
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check label > span {
        border-color: <?php echo $borderColor . ';' ?>;
        border-style: <?php echo $borderType . ';' ?>;
        <?php echo $borderWidthStyle?>
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check:not(:first-child) label > span {
        border-left: 0;
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check:first-child label > span {
        border-color: <?php echo $borderColor . ';' ?>;
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check.ff_item_selected label > span {
        background-color: <?php echo $checkedColor . ';' ?>;
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check.ff_item_selected label > span {
        border-color: <?php echo $checkedColor . ';' ?>;
        } <?php echo $selector; ?> .ff_list_buttons .ff-el-form-check.ff_item_selected:first-child label > span {
        border-left-color: <?php echo $checkedColor . ';' ?>;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:focus,
        <?php echo $selector; ?> .ff-el-group input[type=radio]:focus {
        outline: none !important;
        outline-width: 0 !important;
        outline-style: none !important;
        }
        <?php echo $selector; ?> .ff-el-group input[type=checkbox]:focus:after,
        <?php echo $selector; ?> .ff-el-group input[type=radio]:focus:after {
        box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }
        <?php

        return ob_get_clean();
    }

    public function generateStepHeader($item, $selector)
    {
        $styles = '';
        if ($activeColor = Arr::get($item, 'activeColor.value')) {
            $inactiveColor = Arr::get($item, 'inActiveColor.value');
            $textColor = Arr::get($item, 'textColor.value');
            $inactiveColor = $inactiveColor ?: '#333';
            $textColor = $textColor ?: '#fff';
            $styles = "{$selector} .ff-step-titles li.ff_active:before, {$selector} .ff-step-titles li.ff_completed:before { background: {$activeColor}; color: {$textColor}; } {$selector} .ff-step-titles li.ff_active:after, {$selector} .ff-step-titles li.ff_completed:after { background: {$activeColor};} {$selector} .ff-step-titles li:after { background: {$inactiveColor};} {$selector} .ff-step-titles li:before, {$selector} .ff-step-titles li { color:  {$inactiveColor}; } {$selector} .ff-step-titles li.ff_active, {$selector} .ff-step-titles li.ff_completed { color: {$activeColor} }";
            $styles .= "{$selector} .ff-el-progress-bar { background: {$activeColor}; color: {$textColor}; } {$selector} .ff-el-progress { background-color: {$inactiveColor}; }";
        }
        $otherStyles = '';
        foreach ($item as $styleKey => $styleSetting) {
            if (in_array($styleKey, ['height', 'width', 'margin', 'boxshadow', 'border'])) {
                $otherStyles .= $this->extrachStyle($styleSetting, $styleKey, '');
            } elseif (
                'textPosition' == $styleKey &&
                $textPositionStyle = $this->generateAroundProperty('{replace}', $styleSetting)
            ) {
                $otherStyles .= 'position:relative;';
                $styles .= "{$selector} .ff-el-progress .ff-el-progress-bar span {position:absolute;{$textPositionStyle}}";
            }
        }
        if ($otherStyles) {
            if (strpos($otherStyles, 'height') !== false) {
                $styles .= "{$selector} .ff-el-progress .ff-el-progress-bar {display:-webkit-flex;display:-ms-flexbox;display:flex;-webkit-align-items:center;-ms-flex-align:center;align-items:center;-webkit-justify-content:flex-end;-ms-flex-pack:end;justify-content:flex-end;}";
            }
            $styles .= "{$selector} .ff-el-progress {{$otherStyles}}";
        }
        return $styles;
    }

    public function generateNetPromoter($item, $selector)
    {
        $styles = '';
        $activeStyle = '';
        $normalStyle = '';
        if ($activeColor = Arr::get($item, 'activeColor.value')) {
            $activeStyle .= "background-color: {$activeColor};";
            $styles .= "{$selector} .ff_net_table tbody tr td label:hover:after { border-color: transparent; }";
        }
        if ($color = Arr::get($item, 'color.value')) {
            $activeStyle .= "color: {$color};";
        }

        if ($activeStyle) {
            $styles .= "{$selector} .ff_net_table tbody tr td input[type=radio]:checked + label { {$activeStyle} }";
            $styles .= "{$selector} .ff_net_table tbody tr td input[type=radio] + label:hover { {$activeStyle} }";
        }
        $styles .= "{$selector} .ff_net_table tbody tr td input[type=radio]:checked + label { background-color: {$activeColor}; } {$selector} .ff_net_table tbody tr td label:hover:after { border-color: {$activeColor}; }";

        if ($inActiveColor = Arr::get($item, 'inActiveColor.value')) {
            $normalStyle .= "color: {$inActiveColor};";
        }
        if ($inActiveBgColor = Arr::get($item, 'inActiveBgColor.value')) {
            $normalStyle .= "background-color: {$inActiveBgColor};";
        }

        if ($height = Arr::get($item, 'height.value.value')) {
            $height = $this->getResolveValue($height, Arr::get($item, 'height.value.type'));
            $normalStyle .= "height: {$height};";
        }
        if ($lineHeight = Arr::get($item, 'lineHeight.value.value')) {
            $lineHeight = $this->getResolveValue($lineHeight, Arr::get($item, 'lineHeight.value.type'));
            $normalStyle .= "line-height: {$lineHeight};";
        }
        if ($normalStyle) {
            $styles .= "{$selector} .ff_net_table tbody tr td input[type=radio] + label { {$normalStyle} }";
        }

        if (Arr::get($item,'border.value.status') == 'yes') {
            $border = Arr::get($item,'border');
            if ($borderStyle = $this->extrachStyle($border, 'border', '')) {
                $borderStyle .= "border-left: 0;border-radius: 0;";
                $styles .= "{$selector} .ff_net_table  tbody tr td {{$borderStyle}}";
                $firstTdStyle = '';
                $lastTdStyle = '';
                if ($borderColor = Arr::get($border, 'value.border_color')) {
                    $firstTdStyle .= "border-color: {$borderColor};";
                }
                if ($borderType = Arr::get($border, 'value.border_type')) {
                    $firstTdStyle .= "border-style: {$borderType};";
                }

                $borderWidth = Arr::get($border, 'value.border_width');
                if ($borderRight = Arr::get($borderWidth, 'right')) {
                    $borderRight = $this->getResolveValue($borderRight, Arr::get($borderWidth, 'type'));
                    $styles .= "{$selector} .ff_net_table tbody tr td:last-of-type {
                        border-right-width : $borderRight;
                    }";
                }
                if ($borderLeft = Arr::get($borderWidth, 'left')) {
                    $borderLeft = $this->getResolveValue($borderLeft, Arr::get($borderWidth, 'type'));
                    $firstTdStyle .= "border-left-width: {$borderLeft};";
                }

                $borderRadius = Arr::get($border, 'value.border_radius');
                if ($topRadius = Arr::get($borderRadius, 'top')) {
                    $topRadius = $this->getResolveValue($topRadius, Arr::get($borderRadius, 'type'));
                    $firstTdStyle .= "border-top-left-radius: {$topRadius};";
                }
                if ($bottomRadius = Arr::get($borderRadius, 'bottom')) {
                    $bottomRadius = $this->getResolveValue($bottomRadius, Arr::get($borderRadius, 'type'));
                    $firstTdStyle .= "border-bottom-left-radius: {$bottomRadius};";
                }
                if ($rightRadius = Arr::get($borderRadius, 'right')) {
                    $rightRadius = $this->getResolveValue($rightRadius, Arr::get($borderRadius, 'type'));
                    $lastTdStyle .= "border-top-right-radius: {$rightRadius};";
                }
                if ($leftRadius = Arr::get($borderRadius, 'left')) {
                    $leftRadius = $this->getResolveValue($leftRadius, Arr::get($borderRadius, 'type'));
                    $lastTdStyle .= "border-bottom-right-radius: {$leftRadius};";
                }
                if ($firstTdStyle) {
                    $styles .= "{$selector} .ff_net_table tbody tr td:first-of-type { overflow:hidden; {$firstTdStyle}}";
                }
                if ($lastTdStyle) {
                    $styles .= "{$selector} .ff_net_table  tbody tr td:last-child{overflow:hidden; {$lastTdStyle}}";
                }
            }
        }
        return $styles;
    }

    public function generateRangeSliderStyle($item, $selector)
    {
        $styles = '';
        if ($activeColor = Arr::get($item, 'activeColor.value')) {
            $inactiveColor = Arr::get($item, 'inActiveColor.value');
            $textColor = Arr::get($item, 'textColor.value');
            if (!$inactiveColor) {
                $inactiveColor = '#e6e6e6';
            }
            if (!$textColor) {
                $textColor = '#3a3a3a';
            }
            $styles = "{$selector} .rangeslider__fill { background: {$activeColor}; } {$selector} .rangeslider { background: {$inactiveColor}; } {$selector} .rangeslider__handle { color: {$textColor}; }";
        }

        if ($height = Arr::get($item, 'height.value.value')) {
            $height = $this->getResolveValue($height, Arr::get($item, 'height.value.type'));
            $styles .= "{$selector} .rangeslider--horizontal { height: {$height};}";
        }
        if ($size = Arr::get($item, 'handleSize.value.value')) {
            $size = $this->getResolveValue($size, Arr::get($item, 'handleSize.value.type'));
            $styles .= "{$selector} .rangeslider__handle { height:{$size};width:{$size};}";
        }

        return $styles;
    }


    public function extrachStyle($style, $styleKey, $selector)
    {
        $cssStyle = '';
        if ($styleKey == 'backgroundColor' || $styleKey == 'des_backgroundColor' || $styleKey == 'hover_backgroundColor') {
            if ($value = Arr::get($style, 'value')) {
                $cssStyle .= "background-color: {$value};";
            } else {
                return '';
            }
        } else if ($styleKey == 'backgroundImage') {
            $cssStyle .= $this->generateBackgroundImage(Arr::get($style, 'value'));
        } else if ($styleKey == 'color' || $styleKey == 'des_color') {
            if ($value = Arr::get($style, 'value')) {
                $cssStyle .= "color: {$value};";
            } else {
                return '';
            }
        } else if ($styleKey == 'width') {
            $value = Arr::get($style, 'value');
            $unit = Arr::get($style, 'type', 'custom');
            if (is_array($value)) {
                $unit = Arr::get($value, 'type');
                $value = Arr::get($value, 'value');
            }
            if ($value && $unit) {
                $value = $this->getResolveValue($value, $unit);
                $cssStyle .= "width: {$value};";
            } else {
                return '';
            }
        } else if ($styleKey == 'color_asterisk' || $styleKey == 'color_imp' || $styleKey == 'hover_color_imp') {
            if ($value = Arr::get($style, 'value')) {
                $cssStyle .= "color: {$value} !important;;";
            } else {
                return '';
            }
        } else if ($styleKey == 'margin' || $styleKey == 'padding') {
            $value = Arr::get($style, 'value');
            $cssStyle .= $this->generateAroundDimention($styleKey, $value);
        } else if ($styleKey == 'border' || $styleKey == 'hover_border') {
            if (Arr::get($style, 'value.status') != 'yes') {
                return '';
            }

            if ($borderType = Arr::get($style, 'value.border_type')) {
                $cssStyle .= 'border-style: ' . $borderType . ';';
            }
            if ($borderColor = Arr::get($style, 'value.border_color')) {
                $cssStyle .= 'border-color: ' . $borderColor . ';';
            }
            $cssStyle .= $this->generateAroundDimentionBorder(Arr::get($style, 'value.border_width'));
            $cssStyle .= $this->generateAroundDimentionBorderRadius('border', Arr::get($style, 'value.border_radius'));
        } else if ($styleKey == 'typography' || $styleKey == 'des_typography' || $styleKey == 'hover_typography') {
            $cssStyle .= $this->generateTypegraphy(Arr::get($style, 'value'));
        } else if ($styleKey == 'des_margin') {
            $cssStyle .= $this->generateAroundDimention('margin', Arr::get($style, 'value'));
        } else if ($styleKey == 'des_padding') {
            $cssStyle .= $this->generateAroundDimention('padding', Arr::get($style, 'value'));
        } else if ($styleKey == 'boxshadow' || $styleKey == 'hover_boxshadow') {
            $cssStyle .= $this->generateBoxshadow(Arr::get($style, 'value'));
        } else if ($styleKey == 'allignment') {
            $cssStyle .= $this->generateAllignment(Arr::get($style, 'value'));
        } else if ($styleKey == 'placeholder') {
            $cssStyle .= $this->generatePlaceholder(Arr::get($style, 'value'));
        } else if ($styleKey == 'height') {
            if ($height = Arr::get($style, 'value.value')) {
                $height = $this->getResolveValue($height, Arr::get($style, 'value.type'));
                $cssStyle .= "height: {$height};";
            } else {
                return '';
            }
        }

        if ($cssStyle && $selector) {
            return $selector . '{ ' . $cssStyle . ' } ';
        }

        return $cssStyle;
    }

    public function generateAroundProperty($property, $values, $isRadius = false)
    {
        $cssStyle = '';
        $unit = Arr::get($values, 'type', 'px');
        if ($this->hasValue($top = Arr::get($values, 'top'))) {
            $value = $this->getResolveValue($top, $unit);
            $cssProperty = str_replace('{replace}', $isRadius ? 'top-left' : 'top', $property);
            $cssStyle .= "{$cssProperty}: {$value};";
        }
        if ($this->hasValue($left = Arr::get($values, 'left'))) {
            $value = $this->getResolveValue($left, $unit);
            $cssProperty = str_replace('{replace}', $isRadius ? 'bottom-right' : 'left', $property);
            $cssStyle .= "{$cssProperty}: {$value};";
        }
        if ($this->hasValue($right = Arr::get($values, 'right'))) {
            $value = $this->getResolveValue($right, $unit);
            $cssProperty = str_replace('{replace}', $isRadius ? 'top-right' : 'right', $property);
            $cssStyle .= "{$cssProperty}: {$value};";
        }
        if ($this->hasValue($bottom = Arr::get($values, 'bottom'))) {
            $value = $this->getResolveValue($bottom, $unit);
            $cssProperty = str_replace('{replace}', $isRadius ? 'bottom-left' : 'bottom', $property);
            $cssStyle .= "{$cssProperty}: {$value};";
        }
        return $cssStyle;
    }

    private function hasValue($value)
    {
        return $value === '0' || !empty($value);
    }

    public function generateAroundDimention($styleKey, $values)
    {
        $unit = Arr::get($values, 'type', 'px');
        if (Arr::get($values, 'linked') == 'yes') {
            if ($this->hasValue($top = Arr::get($values, 'top'))) {
                $top = $this->getResolveValue($top, $unit);
                return "{$styleKey}: {$top};";
            }
           return '';
        }
        return $this->generateAroundProperty("$styleKey-{replace}", $values);
    }

    public function generateAroundDimentionBorder($values)
    {
        $unit = Arr::get($values, 'type', 'px');
        if (Arr::get($values, 'linked') == 'yes') {
            if ($this->hasValue($top = Arr::get($values, 'top'))) {
                $top = $this->getResolveValue($top, $unit);
                return "border-width: {$top};";
            }
            return '';
        }
        return $this->generateAroundProperty("border-{replace}-width", $values);
    }

    public function generateAroundDimentionBorderRadius($styleKey, $values)
    {
        if (!$values) return '';
        $unit = Arr::get($values, 'type', 'px');
        if (Arr::get($values, 'linked') == 'yes') {
            if ($this->hasValue($top = Arr::get($values, 'top'))) {
                $top = $this->getResolveValue($top, $unit);
                return "{$styleKey}-radius: {$top};";
            }
            return '';
        }
        return $this->generateAroundProperty("$styleKey-{replace}-radius", $values, true);
    }

    public function generateTypegraphy($values)
    {

        $styles = '';
        if ($fontSize = Arr::get($values, 'fontSize.value')) {
            $fontSize = $this->getResolveValue($fontSize, Arr::get($values, 'fontSize.type', 'px'));
            $styles .= "font-size: {$fontSize};";
        }
        if ($value = Arr::get($values, 'fontWeight')) {
            $styles .= "font-weight: {$value};";
        }
        if ($value = Arr::get($values, 'transform')) {
            $styles .= "text-transform: {$value};";
        }
        if ($value = Arr::get($values, 'fontStyle')) {
            $styles .= "font-style: {$value};";
        }
        if ($value = Arr::get($values, 'textDecoration')) {
            $styles .= "text-decoration: {$value};";
        }
        if ($lineHeight = Arr::get($values, 'lineHeight.value')) {
            $lineHeight = $this->getResolveValue($lineHeight, Arr::get($values, 'lineHeight.type', 'px'));
            $styles .= "line-height: {$lineHeight};";
        }
        if ($letterSpacing = Arr::get($values, 'letterSpacing.value')) {
            $letterSpacing = $this->getResolveValue($letterSpacing, Arr::get($values, 'letterSpacing.type', 'px'));
            $styles .= "letter-spacing: {$letterSpacing};";
        }
        if ($wordSpacing = Arr::get($values, 'wordSpacing.value')) {
            $wordSpacing = $this->getResolveValue($wordSpacing, Arr::get($values, 'wordSpacing.type', 'px'));
            $styles .= "word-spacing: {$wordSpacing};";
        }
        return $styles;
    }

    public function generateBackgroundImage($values)
    {
        $styles = '';
        if ('classic' == Arr::get($values, 'type')) {
            if ($imgUrl = Arr::get($values, 'image.url')) {
                $styles .= "background-image: url('". $imgUrl ."');";
                if ($position = Arr::get($values, 'image.position.value')) {
                    if ("custom" == $position) {
                        if ($xPosition = Arr::get($values, 'image.position.valueX.value')) {
                            $xPosition = $this->getResolveValue($xPosition, Arr::get($values, 'image.position.valueX.type'));
                            $styles .= "background-position-x: {$xPosition};";
                        }
                        if ($yPosition = Arr::get($values, 'image.position.valueY.value')) {
                            $yPosition = $this->getResolveValue($yPosition, Arr::get($values, 'image.position.valueY.type'));
                            $styles .= "background-position-y: {$yPosition};";
                        }
                    } else {
                        $styles .= "background-position: {$position};";
                    }
                }
                if ($repeat = Arr::get($values, 'image.repeat')) {
                    $styles .= "background-repeat: {$repeat};";
                }
                if ($attachment = Arr::get($values, 'image.attachment')) {
                    $styles .= "background-attachment: {$attachment};";
                }
                if ($size = Arr::get($values, 'image.position.value')) {
                    if ("custom" == $size) {
                        $x = 'auto';
                        $y = 'auto';
                        if ($xSize = Arr::get($values, 'image.position.valueX.value')) {
                            $x = $this->getResolveValue($xSize, Arr::get($values, 'image.position.valueX.type'));
                        }
                        if ($ySize = Arr::get($values, 'image.position.valueY.value')) {
                            $y = $this->getResolveValue($ySize, Arr::get($values, 'image.position.valueY.type'));
                        }
                        $size = "{$x} {$y}";
                    }
                    $styles .= "background-size: {$size};";
                }
            }
        } else {
            $primary = Arr::get($values, 'gradient.primary');
            $secondary = Arr::get($values, 'gradient.secondary');
            if (Arr::get($primary, 'color') && Arr::get($secondary, 'color')) {
                $primaryColor = Arr::get($primary, 'color');
                $secondaryColor = Arr::get($secondary, 'color');

                $primaryLocation = Arr::get($primary, 'location.value');
                $primaryLocation = $primaryLocation ?: 0;
                $primaryLocation = $this->getResolveValue($primaryLocation, Arr::get($primary, 'location.type', '%'));

                $secondaryLocation = Arr::get($secondary, 'location.value');
                $secondaryLocation = $secondaryLocation ?: 0;
                $secondaryLocation = $this->getResolveValue($secondaryLocation, Arr::get($secondary, 'location.type', '%'));

                if ("radial" == Arr::get($values, 'gradient.type')) {
                    $position = Arr::get($values, 'gradient.position', 'center-center');
                    $styles .= "background-image: radial-gradient(at {$position}, {$primaryColor} {$primaryLocation}, {$secondaryColor} {$secondaryLocation});";
                } else {
                    $angle = Arr::get($values, 'gradient.angle.value', 0);
                    $angle = $angle ?: 0;
                    $angle = $this->getResolveValue($angle, Arr::get($values, 'gradient.angle.type', '%'));
                    $styles .= "background-image: linear-gradient({$angle}, {$primaryColor} {$primaryLocation}, {$secondaryColor} {$secondaryLocation});";
                }
            }
        }
        return $styles;
    }

    public function generateBoxshadow($values)
    {
        $styles = '';
        $color = Arr::get($values, 'color', '');
        $horValue = Arr::get($values, 'horizontal.value');
        $verValue = Arr::get($values, 'vertical.value');
        $blurValue = Arr::get($values, 'blur.value');
        $spreadValue = Arr::get($values, 'spread.value');
        if ($horValue) {
            $horValue = $this->getResolveValue($horValue, Arr::get($values, 'horizontal.type', 'px'));
        }
        if ($verValue) {
            $verValue = $this->getResolveValue($verValue, Arr::get($values, 'vertical.type', 'px'));
        }
        if ($blurValue) {
            $blurValue = $this->getResolveValue($blurValue, Arr::get($values, 'blur.type', 'px'));
        }
        if ($spreadValue) {
            $spreadValue = $this->getResolveValue($spreadValue, Arr::get($values, 'spread.type', 'px'));
        }
        if ($horValue || $verValue || $blurValue || $spreadValue) {
            $horValue = $horValue ?: 0;
            $verValue = $verValue ?: 0;
            $blurValue = $blurValue ?: 0;
            $spreadValue = $spreadValue ?: 0;
            $boxShadowValue = "{$horValue} {$verValue} {$blurValue} {$spreadValue} {$color}";
            if (Arr::get($values, 'position') == 'inset') {
                $boxShadowValue .= ' inset';
            }
            $styles = "-webkit-box-shadow: {$boxShadowValue}; -moz-box-shadow: {$boxShadowValue}; box-shadow: {$boxShadowValue};";
        }
        return $styles;
    }

    public function generateAllignment($value)
    {
        if (!$value) {
            return '';
        }
        return 'text-align: ' . $value . ';';
    }

    public function generatePlaceholder($value)
    {
        if (!$value) {
            return '';
        }
        return 'color: ' . $value . ';';
    }

    public function getResolveValue($value, $type)
    {
        return 'custom' == $type ? $value : $value . $type;
    }
}
