<?php
namespace FluentFormPro\classes\EntriesImport;

use FluentForm\App\App;
use FluentForm\App\Modules\Acl\Acl;

/**
 *  Handling Entries Module.
 *
 * @since 5.1.7
 */
class EntriesImportController
{
    protected $app = null;
    protected $service = null;

    public function __construct()
    {
      $this->app = App::getInstance();
      $this->service = new EntriesImportService();
    }

    public function boot()
    {
        $this->app->addAction('wp_ajax_fluentform-import-entries-map-fields', [$this, 'mappingFields']);
        $this->app->addAction('wp_ajax_fluentform-import-entries', [$this, 'importEntries']);
    }

    public function mappingFields()
    {
        try {
            Acl::verify(
                'fluentform_manage_entries',
                absint($this->app->request->get('form_id')),
                __('You do not have permission to perform this action.', 'fluentformpro'),
                false
            );
            
            $attributes = $this->app->request->all();
            
            $attrs = array_merge($attributes, ['file' => $this->app->request->file('file')]);
            wp_send_json([
                'success' => true,
                'data' => $this->service->mappingFields($attrs)
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json([
                'success' => false,
                'message' => $exception->getMessage()
            ], 424);
        }
    }

    public function importEntries()
    {
        try {
            Acl::verify(
                'fluentform_manage_entries',
                absint($this->app->request->get('form_id')),
                __('You do not have permission to perform this action.', 'fluentformpro'),
                false
            );
            
            $attributes = $this->app->request->all();
            
            $attrs = array_merge($attributes, ['file' => $this->app->request->file('file')]);
            wp_send_json([
                'success' => true,
                'data' => $this->service->importEntries($attrs)
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json([
                'success' => false,
                'message' => $exception->getMessage()
            ], 424);
        }
    }
}
