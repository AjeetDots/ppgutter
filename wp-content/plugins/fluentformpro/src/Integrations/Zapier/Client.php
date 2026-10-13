<?php

namespace FluentFormPro\Integrations\Zapier;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Exception;
use FluentForm\Framework\Foundation\Application;

class Client
{
	use NotifyTrait;

	protected $app = null;
	protected $table = 'fluentform_form_meta';
	protected $metaKey = 'fluentform_zapier_feed';
	protected $baseUrl = 'https://hooks.zapier.com/hooks/catch/';

	public function __construct(Application $app)
	{
		$this->app = $app;
	}

	/**
	 * Get Zapier Feeds
	 * @return JSON Response
	 */
	public function getNotifications()
	{
		$hooks = wpFluent()
		->table($this->table)
		->where('form_id', intval($this->app->request->get('form_id')))
		->where('meta_key', $this->metaKey)
		->get();

		if (!is_array($hooks)) {
			$hooks = is_object($hooks) && method_exists($hooks, 'toArray') ? $hooks->toArray() : (array) $hooks;
		}

		foreach ($hooks as &$hook) {
			$hook->value = json_decode($hook->value);
		}
		unset($hook);

		wp_send_json_success($hooks);
	}

	/**
	 * Save Zapier Feed
	 * @return JSON Response
	 */
	public function saveNotification()
	{
		$attributes = $this->app->request->all();
		
		$sanitizeMap = [
			'form_id' => 'intval',
			'id' => 'intval',
		];
		$attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);
		
		$request = $this->validate($attributes);

		if (empty($request['id'])) {
			$id = $this->insertNotification($request);
		} else {
            $notification = $this->resolveNotification($request['id'], $request['form_id']);
            $request['id'] = (int) $notification->id;
			$id = $this->updateNotification($request);
		}

		wp_send_json_success(['id' => $id]);
	}

	protected function insertNotification($request)
	{
		return wpFluent()
		->table($this->table)
		->insertGetId([
			'meta_key' => $this->metaKey,
			'form_id' => $request['form_id'],
			'value' => $request['value']
		]);
	}

	protected function updateNotification($request)
	{
		wpFluent()
		->table($this->table)
		->where('id', $id = $request['id'])
		->update(['value' => $request['value']]);

		return $id;
	}

	/**
	 * Delete Zapier Feed
	 * @return JSON Response
	 */
	public function deleteNotification($notification = null)
    {
    	try {
            if (!$notification) {
                $notification = $this->resolveNotification($this->app->request->get('id'));
            }
    		
    		wpFluent()
				->table($this->table)
				->where('id', $notification->id)
                ->where('meta_key', $this->metaKey)
				->delete();

		        wp_send_json_success();
    	} catch(Exception $e) {
    		wp_send_json_error();
	    	}
    }

    public function resolveNotification($id, $formId = null)
    {
        $query = wpFluent()
            ->table($this->table)
            ->where('id', intval($id))
            ->where('meta_key', $this->metaKey);

        if ($formId) {
            $query->where('form_id', intval($formId));
        }

        $notification = $query->first();

        if (!$notification) {
            wp_send_json_error([
                'message' => __('Invalid Zapier feed', 'fluentformpro')
            ], 422);
        }

        return $notification;
    }

    protected function validate($request)
	{
		$validator = fluentValidator($request['value'], [
			'name' => 'required',
			'url' => 'required|url'
		]);

		if ($validator->validate()->fails()) {
			wp_send_json_error($validator->errors(), 422);
		}

		$request['value']['url'] = esc_url_raw($request['value']['url']);
		$request['value']['name'] = sanitize_text_field($request['value']['name']);
		
		$request['value'] = json_encode($request['value']);

		return $request;
	}
}
