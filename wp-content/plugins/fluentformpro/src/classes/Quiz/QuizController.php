<?php


namespace FluentFormPro\classes\Quiz;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class QuizController
{
    public $metaKey = '_quiz_settings';
    public $key = 'quiz_addon';
    protected $app;
    
    public function init($app)
    {
        $this->app = $app;
        $enabled = $this->isEnabled();
        
        $this->addToGlobalMenu($enabled);
        
        if (!$enabled) {
            return;
        }
        $this->addToFormSettingsMenu();

        $this->maybeRandomize();
        
        $this->registerAjaxHandlers();
        
        add_filter('fluentform/all_editor_shortcodes', function ($shortCodes) {
            $shortCodes[] = [
                'title' => __('Quiz', 'fluentformpro'),
                'shortcodes' => [
                    '{quiz_result}' => 'Quiz Result Table'
                ]
            ];
            return $shortCodes;
        });
        
        new QuizScoreComponent();
        add_filter('safe_style_css', function ($styles) {
            $style_tags = ['display'];
            $style_tags = apply_filters('fluentform/allowed_css_properties', $style_tags);
            foreach ($style_tags as $tag) {
                $styles[] = $tag;
            }
            return $styles;
        });
        add_filter('fluentform/shortcode_parser_callback_quiz_result', [$this, 'getQuizResultTable'], 10, 2);

        add_filter('fluentform/form_submission_confirmation', [$this, 'maybeAppendResult'], 10, 3);
        
        add_filter('fluentform/submission_cards', [$this, 'pushQuizResult'], 10, 3);
    }
    
    public function registerAjaxHandlers()
    {
        $this->app->addAdminAjaxAction('ff_get_quiz_module_settings', [$this, 'getSettingsAjax']);
        $this->app->addAdminAjaxAction('ff_store_quiz_module_settings', [$this, 'saveSettingsAjax']);
        $this->app->addAdminAjaxAction('ff_delete_quiz_module_settings', [$this, 'deleteSettingsAjax']);
    }
    
    public function getSettingsAjax()
    {
        $formId = intval($this->app->request->get('form_id'));
        Acl::verify('fluentform_forms_manager', $formId);
        $settings = $this->getSettings($formId);
        wp_send_json_success([
            'settings' => $settings,
            'quiz_fields' => $this->getQuizFields($formId),
            'settings_fields' => QuizController::getIntegrationFields(),
        ]);
    }
    
    public function saveSettingsAjax()
    {
        $formId = intval($this->app->request->get('form_id'));
        Acl::verify('fluentform_forms_manager', $formId);
        $settings = isset($_REQUEST['settings']) ? wp_unslash($_REQUEST['settings']) : [];
        if (Helper::isJson($settings)) {
            $settings = json_decode($settings, true);
        }
        $sanitizeMap = [
            'enabled' => 'rest_sanitize_boolean',
            'randomize_answer' => 'rest_sanitize_boolean',
            'append_result' => 'rest_sanitize_boolean',
            'randomize_question' => 'rest_sanitize_boolean',
        ];
        $settings = fluentform_backend_sanitizer($settings, $sanitizeMap);
        Helper::setFormMeta($formId, $this->metaKey, $settings);
        wp_send_json_success([
            'message' => __('Settings successfully updated', 'fluentformpro'),
        ]);
    }

    public function deleteSettingsAjax()
    {
        $formId = intval($this->app->request->get('form_id'));
        Acl::verify('fluentform_forms_manager', $formId);
        Helper::deleteFormMeta($formId, $this->metaKey);

        wp_send_json_success([
            'message' => __('Settings deleted successfully', 'fluentformpro'),
        ]);
    }
    
    public function getSettings($formId)
    {
        $settings = Helper::getFormMeta($formId, $this->metaKey, []);
        $form = $this->getForm($formId);
        $fields = $this->getQuizFields($form);
        $resultType = self::getScoreType($form);
    
        $defaults = [
            'enabled'            => false,
            'randomize_answer'   => false,
            'append_result'      => true,
            'randomize_question' => false,
            'saved_quiz_fields'  => $fields,
            'grades'             => [
                [
                    'label' => 'A',
                    'min'   => 90,
                    'max'   => 100,
                ],
                [
                    'label' => 'B',
                    'min'   => 80,
                    'max'   => 89,
                ],
                [
                    'label' => 'C',
                    'min'   => 70,
                    'max'   => 79,
                ],
            ]
        ];
        
        $settings = $this->removeDeletedFields($settings, $fields);
        
        $settings = wp_parse_args($settings, $defaults);
        $settings['saved_quiz_fields'] = empty($settings['saved_quiz_fields']) ? [] : $settings['saved_quiz_fields'];
        $settings['result_type'] = $resultType;
        return $settings;
    }
    
    protected function getForm($formId)
    {
        return wpFluent()->table('fluentform_forms')->find($formId);
    }
    
    public static function getIntegrationFields()
    {
        return [
            [
                'key' => 'append_result',
                'label' => 'Append Result',
                'component' => 'checkbox-single',
                'checkbox_label' => __('Show Result on confirmation page', 'fluentformpro')
            
            ],
            [
                'key' => 'randomize_question',
                'label' => 'Randomize Questions',
                'checkbox_label' => __('Questions will be randomized each time its loaded', 'fluentformpro'),
                'component' => 'checkbox-single'
            ],
            [
                'key' => 'randomize_answer',
                'label' => 'Randomize Options',
                'checkbox_label' => __('Options will be randomized each time its loaded', 'fluentformpro'),
                'component' => 'checkbox-single'
            ],
        
        ];
    }
    
    public function isEnabled()
    {
        $globalModules = get_option('fluentform_global_modules_status');
        $quizAddon = Arr::get($globalModules, $this->key);
        
        if ($quizAddon == 'yes') {
            return true;
        }
        
        return false;
    }
    
    public function addToGlobalMenu($enabled)
    {
        add_filter('fluentform/global_addons', function ($addOns) use ($enabled) {
            $addOns[$this->key] = [
                'title' => 'Quiz Module',
                'description' => __('With this module, you can create quizzes and show scores with grades, points, fractions, or percentages', 'fluentformpro'),
                'logo' => fluentFormMix('img/integrations/quiz-icon.svg'),
                'enabled' => ($enabled) ? 'yes' : 'no',
                'config_url' => '',
                'category' => ''
            ];
            
            return $addOns;
        }, 9);
    }
    
    public function addToFormSettingsMenu()
    {
        add_filter('fluentform/form_settings_menu', function ($menu) {
            $menu['quiz_settings'] = [
                'title' => __('Quiz Settings', 'fluentformpro'),
                'slug' => 'form_settings',
                'hash' => 'quiz_settings',
                'route' => '/quiz_settings'
            ];
            
            return $menu;
        });
    }
    
    /**
     * Maybe Randomize Questions and Answers
     *
     * @return void
     */
    public function maybeRandomize()
    {
        
        add_filter('fluentform/rendering_form', function ($form) {
            $settings = $this->getSettings($form->id);
            $enabled = $settings['enabled'] == 'yes';
            if (!$enabled) {
                return $form;
            }
            if ($settings['randomize_answer']) {
                $this->randomizeCheckableInputs();
            }
            if (!$settings['randomize_question']) {
                return $form;
            }
            $fields = $form->fields;
    
            $quizFields = $this->getQuizFields($form);
            $quizFieldsKeys = array_keys($quizFields);
            $formQuizFields = array_filter($fields['fields'], function ($field) use ($quizFieldsKeys) {
                return in_array(Arr::get($field, 'attributes.name'), $quizFieldsKeys);
            });
            if (empty($formQuizFields)) {
                return $form;
            }
            $quizGroup = [];
            $i = 0;
            foreach ($formQuizFields as $key => $field) {
                $inSequence = isset($formQuizFields[$key + 1]) ? $formQuizFields[$key + 1] : false;
                if ($inSequence) {
                    $quizGroup[$i][$key] = $field;
                } else {
                    $quizGroup[$i][$key] = $field;
                    $i++;
                }
            }
            //shuffle groups and replace their positions in the original array
            foreach ($quizGroup as $group) {
                $startIndex = Arr::get(array_keys($group), '0');
                shuffle($group);
                $length = count($group);
                array_splice($fields['fields'], $startIndex, $length, $group);
            }
    
            $form->fields = $fields;
            
            return $form;
        }, 10, 1);
    }
    
    
    /**
     * Generate Quiz Result Table
     *
     * @param $shortCode
     * @param ShortCodeParser $parser
     *
     * @return string|void
     */
    public function getQuizResultTable($shortCode, ShortCodeParser $parser)
    {
        $form = $parser::getForm();
        $entry = $parser::getEntry();
        $quizSettings = $this->getSettings($form->id);
        $quizFields = Arr::get($quizSettings,'saved_quiz_fields');
    
        if (!$entry || !$form || $quizSettings['enabled'] != 'yes') {
            return;
        }
       
        $scoreType = self::getScoreType($form);
        $response = json_decode($entry->response, true);
        $results = $this->getFormattedResults($quizSettings, $response, $form);
        
        /* For full width in single entry page */
        $width = defined('FLUENTFORM_RENDERING_ENTRIES') ? '' : ' width="600"';
        $html = '<table class="table ff_quiz_result_table"  ' . $width . ' cellpadding="0" cellspacing="0" style="min-width: 100%"><tbody>';
        $hasRightWrongAns = !in_array($scoreType, ['total_point', 'personality']) ? true : false;
        $rightWrongHtml = '';
        $forPdf = method_exists($parser, 'getProvider') && 'pdfFeed' === $parser::getProvider();
        
        foreach ($results as $name => $result) {
    
            if( !in_array($name,array_keys($quizFields))){
                continue;
            }
            //question
    
            $icon = $result['correct'] == true ? self::getRightIcon($forPdf) : self::getWrongIcon($forPdf);
            $rightWrongHtml = $hasRightWrongAns ? "<div style='width: 20px; margin-right: 10px;'>$icon</div>" : '';
            $html .= "<tr class=\"field-label\">
                        <td style=' align-items: center; padding: 6px 12px; background-color: #f8f8f8; text-align: left; clear: both; display: flex;'>
                           {$rightWrongHtml} <div><b>{$result['label']}</b></div>
                        </td>
                    </tr>";
            //answer
            if ('personality' == $scoreType) {
                $result['user_value'] = static::labelFromValue($result['user_value'], $result['options'], $result, 'user_value');
            }
            $userValueFormatted = is_array($result['user_value']) ? join(', ', $result['user_value']) : $result['user_value'];
            $userValueFormatted = empty($userValueFormatted) ? '-' :$userValueFormatted;
            if (is_string($userValueFormatted)) {
                $userValueFormatted = isset($result['options'][$userValueFormatted]) ? $result['options'][$userValueFormatted] : $userValueFormatted;
            }
            
            $html .= sprintf(
                "<tr class=\"user-value\"><td style=\"padding: 6px 12px 12px 12px;\"> %s</td>",
                $userValueFormatted
            );
            
            $correctAnsFormatted = is_array($result['correct_value']) ? join(', ', $result['correct_value']) : $result['correct_value'];
            //skip right wrong for when total point is selected
             if ($scoreType == 'total_point') {
                $score = 0;
                if ($result['has_advance_scoring'] == 'yes') {
                    $score = $result['advance_points_score'];
                } else {
                    if ($result['correct']) {
                        $score = $result['points'];
                    }
                }
                
                $html .= sprintf(
                    "<tr class=\"field-value\"><td style=\"padding: 6px 12px 12px 12px;\">%s : %s</td>",
                    __('Point', 'fluentformpro'),
                    $score
                );
             }
             else if (!$result['correct'] && $scoreType != 'total_point' && $scoreType != 'personality') {
                 $conditionText = '';
                 if ($result['correct_ans_condition'] == 'not_includes') {
                     $conditionText = 'does Not includes ';
                 } elseif ($result['correct_ans_condition'] == 'includes_any') {
                     $conditionText = 'any of the following ';
                 } elseif ($result['correct_ans_condition'] == 'includes_all') {
                     $conditionText = 'all of the following ';
                 }
                 if($rightWrongHtml){
                     $correctAnswerLabel = Arr::get($result, 'element') === 'input_ranking'
                         ? __('Correct ranking', 'fluentformpro')
                         : __('Correct answer', 'fluentformpro');
                     $html .= sprintf(
                         "<tr class=\"field-value\"><td style=\"padding: 6px 12px 12px 12px;\">%s %s: %s</td>",
                         $correctAnswerLabel,
                         $conditionText,
                         $correctAnsFormatted
                     );
                 }
    
             }
            $html .= '</tr>';
        }
        if ($scoreType == 'personality') {
    
            $scoreInput = self::getScoreInput($form);
    
            $personalityResult = $this->getPersonalityScoreMap($results);
            $result = QuizScoreComponent::determinePersonality($personalityResult, $scoreInput, $form);
            $result = Arr::get($scoreInput, "raw.options.$result", $result);
            $personalityLabel = apply_filters('fluentform/quiz_personality_label',__('Personality', 'fluentformpro'),$form);
            $html .= sprintf(
                "<tr class=\"field-label\"> <td style=\"display:flex;align-items: center; padding: 6px 12px; background-color: #f8f8f8; text-align: left;\"> <div><b>%s </b></div></td></tr>",
                $personalityLabel
            );
    
            $html .= sprintf(
                "<tr class=\"field-value\"><td style=\"padding: 6px 12px 12px 12px;\">%s</td>",
                $result
            );
            $html .= '</tr>';
    
        }
    
        $html .= '</tbody></table>';
        return apply_filters('fluentform/quiz_result_table_html', $html, $form, $results, $quizSettings, $entry);
    }
    
    
    /**
     * Get Available Quiz Fields
     *
     * @param $form
     *
     * @return array|mixed
     */
    protected function getQuizFields($form)
    {
        $fields = FormFieldsParser::getEntryInputs($form, ['admin_label', 'label', 'element', 'options']);
        $supportedQuizFields = [
            'input_text',
            'input_radio',
            'input_checkbox',
            'select',
            'input_number',
            'input_date',
            'rangeslider',
            'input_ranking'
        ];
        $fields = array_filter($fields, function ($field) use ($supportedQuizFields) {
            return in_array($field['element'], $supportedQuizFields);
        });
    
        foreach ($fields as $name => $value) {
            $fields[$name]['enabled'] = false;
            $fields[$name]['points'] = 1;
            $fields[$name]['correct_answer'] = $value['element'] === 'input_ranking' ? array_keys(Arr::get($value, 'options', [])) : [];
            $fields[$name]['condition'] = $value['element'] === 'input_ranking' ? 'list_match' : 'equal';
            $fields[$name]['has_advance_scoring'] = 'no';
            $fields[$name]['advance_points'] = $this->advancePoints($fields[$name]);
            $fields[$name]['personality_points'] = $value['element'] === 'input_ranking' ? $this->personalityPoints($fields[$name]) : [];
        }
        return $fields;
    }
    
    /**
     * Remove Deleted inputs
     *
     * @param $settings
     * @param $fields
     *
     * @return mixed
     */
    protected function removeDeletedFields($settings, $fields)
    {
        if (!isset($settings['saved_quiz_fields'])) {
            return $settings;
        }
        $savedFields = $settings['saved_quiz_fields'];
        foreach ($savedFields as $fieldKey => $value) {
            if (!isset($fields[$fieldKey])) {
                unset($savedFields[$fieldKey]);
            }
            if (Arr::exists($fields,$fieldKey) && Arr::exists($fields[$fieldKey], 'options')) {
                $savedFields[$fieldKey]['options'] = Arr::get($fields[$fieldKey], 'options');
                if (Arr::get($fields, $fieldKey . '.element') === 'input_ranking') {
                    $savedFields[$fieldKey]['personality_points'] = $this->mergePersonalityPoints(
                        Arr::get($savedFields[$fieldKey], 'personality_points', []),
                        Arr::get($fields[$fieldKey], 'options', [])
                    );
                }
            }
        }
        $settings['saved_quiz_fields'] = $savedFields;
        
        return $settings;
    }
    
    
    /**
     * Validate Answer
     *
     * @param $settings
     * @param $userValue
     * @param $correctValue
     *
     * @return bool
     */

    protected function isCorrect($settings, $userValue, $correctValue = '', $options = [])
    {
        $isCorrect = false;
        $element = $settings['element'];
        switch ($element) {
            case 'input_radio':
                if (!$userValue) {
                    break;
                }
                
                if (in_array($userValue, $correctValue)) {
                    $isCorrect = true;
                }
                
                break;
            case 'select':
            case 'input_text':
            case 'rangeslider':
            case 'input_date':
            case 'input_checkbox':
            case 'input_number':
                if (!$userValue) {
                    break;
                }
                $hasAdvanceScoring = $settings['has_advance_scoring'] === 'yes';
                if ($hasAdvanceScoring) {
                    //check if select is not a multiselect
                    if ($element == 'select' && is_string($correctValue)) {
                        $isCorrect = $userValue == $correctValue;
                        break;
                    }

                    //if it has advance scoring then for right answer match all value greater than 0 with user values
                    //else assume as wrong answer but count the scores
                    $correctValues = static::labelFromValue($correctValue, $options, $settings, 'correct_value');
                  
                    return count(array_intersect($userValue, $correctValues)) == count($userValue);
                }
                $condition = Arr::get($settings, 'condition');
            
                if ($condition == 'equal') {
                    if (is_array($correctValue)) {
                        $correctValue = array_shift($correctValue);
                    }
                    if (is_array($userValue)) {
                        $userValue = array_shift($userValue);
                    }
                    if (apply_filters('fluentform/quiz_case_sensitive_off', __return_false())) {
                        $userValue = strtolower($userValue);
                        $correctValue = strtolower($correctValue);
                    }
                    if ($userValue == $correctValue) {
                        $isCorrect = true;
                    }
                } elseif ($condition == 'includes_any') {
                    //check if any user values exists in correct answers
                    if (!is_array($userValue)) {
                        $userValue = [$userValue];
                    }
                    $isCorrect = (bool)array_intersect($correctValue, $userValue);
                } elseif ($condition == 'includes_all') {
                    //check if all user values exists in correct answers
                    if (!is_array($userValue)) {
                        $userValue = [$userValue];
                    }
                    $commonValue = array_intersect($correctValue, $userValue);
                    $isCorrect = $commonValue && count($commonValue) == count($userValue) && count($correctValue) == count($commonValue);
                } elseif ($condition == 'not_includes') {
                    //check if all user values not exists in correct answers
                    if (!is_array($userValue)) {
                        $userValue = [$userValue];
                    }
                    $isCorrect = !array_intersect($correctValue, $userValue);
                }
                break;
            case 'input_ranking':
                $correctValue = $this->normalizeRankingValues($correctValue, $options, true);
                $userValueState = $this->parseRankingSubmission($userValue, $options);

                if (!$userValueState['valid'] || !$userValueState['answered'] || !$correctValue) {
                    break;
                }

                $isCorrect = $userValueState['values'] === $correctValue;
                break;
        }
        
        return $isCorrect;
    }
    
    /**
     * Get Formatted Quiz Result
     *
     * @param $quizFields
     * @param $response
     *
     * @return array
     */
    public function getFormattedResults($quizSettings, $response, $form)
    {
        $quizFields = $quizSettings['saved_quiz_fields'];
        $quizType = Arr::get($quizSettings,'result_type');
        $inputs = FormFieldsParser::getInputs($form, ['element', 'options', 'label']);
        $quizResults = [];
        $quizFields = $this->arrayReposition($quizFields, array_keys($inputs));
        foreach ($quizFields as $key => $settings) {
            if ($settings['enabled'] != true) {
                continue;
            }
            $correctValue = Arr::get($settings, 'correct_answer');
            $userValue = Arr::get($response, $key, '');
            $options = Arr::get($inputs, $key . '.options');
            $quizResults[$key] = [
                'correct'               => $this->isCorrect($settings, $userValue, $correctValue, $options),
                'correct_value'         => static::labelFromValue($correctValue, $options, $settings, 'correct_value'),
                'correct_ans_condition' => $settings['condition'],
                'options'               => $options,
                'user_value'            => ($quizType != 'personality') ? static::labelFromValue($userValue, $options, $settings, 'user_value') : $userValue,
                'points'                => $settings['points'],
                'label'                 => Arr::get($inputs, $key . '.label'),
                'element'               => Arr::get($settings, 'element'),
                'personality_points'    => Arr::get($settings, 'personality_points', []),
                'has_advance_scoring'   => $settings['has_advance_scoring'],
                'advance_points'        => array_reduce($settings['advance_points'], function ($sum, $itemScore) {
                    $sum += $itemScore;
                    return $sum;
                }),
                'advance_points_score'  => $this->calcAdvancePoints($settings, $userValue),
            ];
        }
        return $quizResults;
    }
    
    /**
     * Calculate user selected values from quiz results.
     *
     * @param array $quizResults
     * @return array
     */
    public function getUserSelectedValues($quizResults)
    {
        $userSelectedValues = [];
        foreach ($quizResults as $result) {
            if(empty($result['user_value'])){
                continue;
            }
            if (is_array($result['user_value'])) {
                // If 'user_value' is an array
                $userSelectedValues = array_merge($userSelectedValues, $result['user_value']);
            } else {
                // If 'user_value' is a string, convert it to an array
                $userSelectedValues[] = $result['user_value'];
            }
        }
        
        return $userSelectedValues;
    }

    public function getPersonalityScoreMap($quizResults)
    {
        $scoreMap = [];

        foreach ($quizResults as $result) {
            $userValue = Arr::get($result, 'user_value');
            if (empty($userValue)) {
                continue;
            }

            if (Arr::get($result, 'element') === 'input_ranking' && is_array($userValue)) {
                $positionScores = Arr::get($result, 'personality_points', []);

                foreach ($userValue as $index => $value) {
                    $score = Arr::get($positionScores, $index, 0);
                    if (!is_numeric($score)) {
                        continue;
                    }

                    // Ranking personality scoring is position-based:
                    // the personality value placed in each rank receives that rank's configured score.
                    $scoreMap[$value] = Arr::get($scoreMap, $value, 0) + $score;
                }

                continue;
            }

            if (is_array($userValue)) {
                foreach ($userValue as $value) {
                    $scoreMap[$value] = Arr::get($scoreMap, $value, 0) + 1;
                }
            } else {
                $scoreMap[$userValue] = Arr::get($scoreMap, $userValue, 0) + 1;
            }
        }

        return $scoreMap;
    }
    /**
     * Array Restructured to get Form Fields structure
     * @param $array
     * @param $keys
     * @return array
     */
    protected function arrayReposition($array, $keys)
    {
        $returnArray = [];
        foreach ($keys as $key) {
            if (Arr::get($array, $key)) {
                $returnArray[$key] = Arr::get($array, $key);
            }
        }

        return $returnArray;
    }
    
    /**
     * Get input label from value
     *
     * @param $targetValues
     * @param $options
     * @param $settings
     * @param $type
     * @return array|mixed
     */
    private static function labelFromValue($targetValues, $options, $settings, $type)
    {
        $hasAdvanceScoring = $settings['has_advance_scoring'] === 'yes';
        if ($hasAdvanceScoring && $type == 'correct_value' && Arr::get($settings, 'element') !== 'input_ranking') {
            $advanceScores = Arr::get($settings, 'advance_points');
            $correctOptions = [];
            foreach ($advanceScores as $label => $score) {
                if ($score >= 1) {
                    $correctOptions[] = $label;
                }
            }
            return $correctOptions;
        }
        if (is_array($targetValues)) {
            $formattedValue = [];
            foreach ($targetValues as $index => $value) {
                $formattedValue[] = Arr::get($settings, 'element') === 'input_ranking'
                    ? ($index + 1) . '. ' . (isset($options[$value]) ? $options[$value] : $value)
                    : (isset($options[$value]) ? $options[$value] : $value);
            }
            $targetValues = $formattedValue;
        } else {
            $targetValues = isset($options[$targetValues]) ? $options[$targetValues] : $targetValues;
        }
        return $targetValues;
    }
    
    /**
     * Maybe Append quiz result
     *
     * @param $data
     * @param $formData
     * @param $form
     *
     * @return mixed
     */
    public function maybeAppendResult($data, $formData, $form)
    {
        $settings = $this->getSettings($form->id);
        if ($settings['append_result'] == true) {
            $data['messageToShow'] .= '{quiz_result}';
        }
        
        return $data;
    }
    
    
    /**
     * Adds quiz result in the single entry page
     * @param $cards
     * @param $entryData
     * @param $submission
     * @return array|mixed
     */
    public function pushQuizResult($cards, $entryData, $submission)
    {
        $formId = $submission->form_id;
        
        $settings = $this->getSettings($formId);
        
        if (($settings['enabled'] != 'yes')) {
            return $cards;
        }
        $form = $this->getForm($formId);
        
        $contents = '<p>{quiz_result}</p>';
        $resultHtml = ShortCodeParser::parse(
            $contents,
            $submission->id,
            $entryData,
            $form
        );
        $title = __('Quiz Result', 'fluentformpro');
        $title = apply_filters('fluentform/quiz_result_title', $title, $submission, $form);

        $widgetData = [
            'title' => $title,
            'content' => apply_filters('fluentform/quiz_result_message', $resultHtml, $submission, $form)
        ];
        
        $cards['quiz_result'] = $widgetData;
        
        return $cards;
    }
    
    /**
     * Wrong Answer Icon
     *
     * @return string
     */
    protected static function getWrongIcon($forPdf)
    {
        $icon = '<svg fill="#e13636" version="1.1" viewBox="0 0 32 32"  xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><path d="M17.459,16.014l8.239-8.194c0.395-0.391,0.395-1.024,0-1.414c-0.394-0.391-1.034-0.391-1.428,0  l-8.232,8.187L7.73,6.284c-0.394-0.395-1.034-0.395-1.428,0c-0.394,0.396-0.394,1.037,0,1.432l8.302,8.303l-8.332,8.286  c-0.394,0.391-0.394,1.024,0,1.414c0.394,0.391,1.034,0.391,1.428,0l8.325-8.279l8.275,8.276c0.394,0.395,1.034,0.395,1.428,0  c0.394-0.396,0.394-1.037,0-1.432L17.459,16.014z" /><g/><g/><g/><g/><g/><g/></svg>';
        if ($forPdf) {
            $icon = '<svg height="26" width="26" version="1.1" viewBox="0 0 32 32"  xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g transform="translate(-5,-5)"><path fill="#e13636" d="M17.459,16.014l8.239-8.194c0.395-0.391,0.395-1.024,0-1.414c-0.394-0.391-1.034-0.391-1.428,0  l-8.232,8.187L7.73,6.284c-0.394-0.395-1.034-0.395-1.428,0c-0.394,0.396-0.394,1.037,0,1.432l8.302,8.303l-8.332,8.286  c-0.394,0.391-0.394,1.024,0,1.414c0.394,0.391,1.034,0.391,1.428,0l8.325-8.279l8.275,8.276c0.394,0.395,1.034,0.395,1.428,0  c0.394-0.396,0.394-1.037,0-1.432L17.459,16.014z" /></g></svg>';
        }
        return apply_filters('fluentform/quiz_wrong_ans_icon', $icon);
    }
    
    /**
     * Right Answer Icon
     *
     * @return string
     */
    protected static function getRightIcon($forPdf)
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg"   viewBox="0 0 24 24" fill="none" stroke="#1a7efb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-check"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        if ($forPdf) {
            $icon = '<svg height="26" width="26" xmlns="http://www.w3.org/2000/svg"   viewBox="0 0 24 24" class="feather feather-check"><polyline fill="none" stroke="#1a7efb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="20 6 9 17 4 12"></polyline></svg>';
        }
        return apply_filters('fluentform/quiz_right_ans_icon', $icon);
    }
    
    public function advancePoints($options)
    {
        if (!isset($options['options'])) {
            return (object)[];
        }
        $formattedOptions = [];
        foreach ($options['options'] as $key => $value) {
            $formattedOptions[$key] = 0;
        }
        return $formattedOptions;
    }

    public function personalityPoints($options)
    {
        $optionCount = count(Arr::get($options, 'options', []));
        $formattedPoints = [];

        for ($index = 0; $index < $optionCount; $index++) {
            $formattedPoints[$index] = max($optionCount - $index, 0);
        }

        return $formattedPoints;
    }

    private function mergePersonalityPoints($savedPoints, $options)
    {
        $savedPoints = is_array($savedPoints) ? $savedPoints : [];
        $optionCount = count($options);
        $formattedPoints = [];

        for ($index = 0; $index < $optionCount; $index++) {
            $formattedPoints[$index] = Arr::get($savedPoints, $index, max($optionCount - $index, 0));
        }

        return $formattedPoints;
    }

    private function calcAdvancePoints($settings, $userValue)
    {
        $hasAdvancePoint = Arr::get($settings, 'has_advance_scoring');
            if ($hasAdvancePoint) {
            if (Arr::get($settings, 'element') === 'input_ranking') {
                $advancePoints = Arr::get($settings, 'advance_points', []);
                $correctValue = $this->normalizeRankingValues(Arr::get($settings, 'correct_answer', []), Arr::get($settings, 'options', []), true);
                $userValueState = $this->parseRankingSubmission($userValue, Arr::get($settings, 'options', []));
                $total = 0;

                if (!$userValueState['valid'] || !$userValueState['answered']) {
                    return 0;
                }

                $userValue = $userValueState['values'];

                foreach ($correctValue as $index => $value) {
                    if (Arr::get($userValue, $index) !== $value) {
                        continue;
                    }

                    $score = Arr::get($advancePoints, $value, 0);
                    if (!is_numeric($score)) {
                        continue;
                    }

                    $total += $score;
                }

                return $total;
            }

            $advancePoints = Arr::get($settings, 'advance_points');
            $userValue = is_array($userValue) ? $userValue : [$userValue];
            $total = 0;
            foreach ($userValue as $value) {
                $value = Arr::get($advancePoints, $value, 0);
                if (!is_numeric($value)) {
                    continue;
                }
                $total += $value;
            }
            return $total;
        } else {
            return Arr::get($settings, 'points');
        }
    }

    private function normalizeRankingValues($values, $options = [], $fillMissing = false)
    {
        $optionKeys = array_map('strval', array_keys($options));
        $values = is_array($values) ? $values : (strlen((string) $values) ? [$values] : []);
        $values = array_values(array_unique(array_map('sanitize_text_field', $values)));

        if ($optionKeys) {
            $values = array_values(array_filter($values, function ($value) use ($optionKeys) {
                return in_array($value, $optionKeys, true);
            }));
        }

        if ($fillMissing) {
            foreach ($optionKeys as $value) {
                if (!in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    private function parseRankingSubmission($values, $options = [])
    {
        $optionKeys = array_map('strval', array_keys($options));
        $optionCount = count($optionKeys);

        if (is_string($values) && Helper::isJson($values)) {
            $decodedValues = json_decode($values, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $values = $decodedValues;
            }
        }

        if (!is_array($values)) {
            $values = strlen((string) $values) ? [$values] : [];
        }

        $values = array_values(array_filter(array_map('sanitize_text_field', $values), function ($value) {
            return $value !== '';
        }));

        if (!$values) {
            return [
                'values' => [],
                'answered' => false,
                'valid' => true
            ];
        }

        if (count($values) !== $optionCount) {
            return [
                'values' => $values,
                'answered' => true,
                'valid' => false
            ];
        }

        if (count(array_unique($values)) !== count($values)) {
            return [
                'values' => $values,
                'answered' => true,
                'valid' => false
            ];
        }

        foreach ($values as $value) {
            if (!in_array($value, $optionKeys, true)) {
                return [
                    'values' => $values,
                    'answered' => true,
                    'valid' => false
                ];
            }
        }

        return [
            'values' => $values,
            'answered' => true,
            'valid' => true
        ];
    }
    
    private static function getScoreType($form)
    {
        $scoreType = '';
        $scoreInput = self::getScoreInput($form);
        if ($scoreInput) {
            $scoreType = Arr::get($scoreInput, 'raw.settings.result_type');
        }
        return $scoreType;
    }
    public static function getScoreInput($form){
        $scoreInput = \FluentForm\App\Modules\Form\FormFieldsParser::getInputsByElementTypes($form,['quiz_score'],['raw']);
        if ($scoreInput) {
           return array_shift($scoreInput);
        }
        return false;
    }
    
    public function randomizeCheckableInputs()
    {
        add_filter('fluentform/rendering_field_data_input_checkbox', function ($data) {
            $options = $data['settings']['advanced_options'];
            shuffle($options);
            $data['settings']['advanced_options'] = $options;
        
            return $data;
        }, 10, 1);
    
        add_filter('fluentform/rendering_field_data_input_radio', function ($data) {
            $options = $data['settings']['advanced_options'];
            shuffle($options);
            $data['settings']['advanced_options'] = $options;
        
            return $data;
        }, 10, 1);

        add_filter('fluentform/rendering_field_data_input_ranking', function ($data) {
            $options = Arr::get($data, 'settings.advanced_options', []);
            if (count($options) > 1) {
                shuffle($options);
                $data['settings']['advanced_options'] = $options;
            }

            $data['settings']['require_interaction_for_submission'] = 'yes';

            return $data;
        }, 10, 1);
    }
    
}
