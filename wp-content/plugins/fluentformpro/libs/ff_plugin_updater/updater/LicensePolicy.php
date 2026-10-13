<?php

namespace FluentFormPro\FluentUpdater;

use FluentForm\App\Modules\Acl\Acl;
use FluentForm\Framework\Foundation\Policy;
use FluentForm\Framework\Http\Request\Request;

defined('ABSPATH') or die;

class LicensePolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return Acl::hasPermission('fluentform_full_access');
    }
}
