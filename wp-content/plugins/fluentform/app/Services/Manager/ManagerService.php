<?php

namespace FluentForm\App\Services\Manager;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\Framework\Support\Arr;
use FluentForm\Framework\Validator\ValidationException;
use FluentForm\Framework\Validator\Validator;

class ManagerService
{
    public function getManagers($attributes = [])
    {
        $limit = Arr::get($attributes, 'per_page', 10);
        $page = Arr::get($attributes, 'page', 1);
        $offset = 1 == $page ? 0 : ($page - 1) * $limit;

        $query = new \WP_User_Query([
            'meta_key'     => '_fluent_forms_has_role',
            'meta_value'   => 1,
            'meta_compare' => '=',
            'number'       => $limit,
            'offset'       => $offset,
        ]);

        $managers = [];

        foreach ($query->get_results() as $user) {
            $allowedForms = FormManagerService::getUserAllowedFormsScope($user->ID);
            $hasSpecificFormsPermission = false !== $allowedForms;
            $managers[] = [
                'id'          => $user->ID,
                'first_name'  => $user->first_name,
                'last_name'   => $user->last_name,
                'email'       => $user->user_email,
                'permissions' => Acl::getUserPermissions($user),
                'forms'       => false !== $allowedForms ? array_values($allowedForms) : false,
                'roles'       => $this->getUserRoles($user->roles),
                'has_specific_forms_permission'=> $hasSpecificFormsPermission ? 'yes' : 'no',
            ];
        }

        $total = $query->get_total();

        $forms = Helper::getForms();
        if ($forms) {
            Arr::forget($forms, 0);
        }

        return ([
            'managers'  => $managers,
            'total' => $total,
            'permissions' => Acl::getReadablePermissions(),
            'forms' => $forms,
        ]);
    }

    public function addManager($attributes = [])
    {
        $manager = Arr::get($attributes, 'manager');

        $this->validate($manager);

        $permissions = Arr::get($manager, 'permissions', []);

        // Defense in depth: only a full WordPress administrator may grant the
        // crown-jewel `fluentform_full_access` permission. A holder of that ACL
        // permission is not necessarily a WP admin (managers can be delegated),
        // so without this a compromised full-access session — e.g. a script
        // running via XSS in a privileged session — could mint new full-access
        // managers and escalate. Lesser permission grants are unaffected.
        if (in_array('fluentform_full_access', (array) $permissions, true) && !current_user_can('manage_options')) {
            $permissions = array_values(array_filter((array) $permissions, function ($permission) {
                return 'fluentform_full_access' !== $permission;
            }));
        }

        $user = get_user_by('email', $manager['email']);

        if (!$user) {
            throw new ValidationException('', 0, null, ['message' => 'Please Provide Valid Email']);
        }

        $this->guardDelegatedGrant($user, $manager);

        Acl::attachPermissions($user, $permissions);

        update_user_meta($user->ID, '_fluent_forms_has_role', 1);

        $hasSpecificFormsPermission = 'yes' === Arr::get($manager, 'has_specific_forms_permission');
        $allowedForms = array_values(array_filter(array_map('intval', (array) Arr::get($manager, 'forms', []))));

        // The UI always sends this flag, so an absent one is a partial payload, not a request to clear.
        $restrictionWasSubmitted = null !== Arr::get($manager, 'has_specific_forms_permission');

        // Keep an empty selection unrestricted so the manager UI's
        // "leave blank for all forms" behavior matches the saved ACL state.
        if ($hasSpecificFormsPermission && $allowedForms) {
            FormManagerService::updateHasSpecificFormsPermission($user->ID, 'yes');
            FormManagerService::addUserAllowedForms($allowedForms, $user->ID);
        } elseif ($restrictionWasSubmitted) {
            FormManagerService::updateHasSpecificFormsPermission($user->ID, 'no');
            FormManagerService::deleteUserAllowedForms($user->ID);
        }

        $updatedUser = [
            'id'          => $user->ID,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'email'       => $user->user_email,
            'permissions' => Acl::getUserPermissions($user),
        ];

        return ([
            'message' => __('Manager has been saved.', 'fluentform'),
            'manager' => $updatedUser,
        ]);
    }

    /**
     * A delegated (non-administrator) manager may only hand out or take away access inside
     * their own reach: never on their own record or an administrator's, and when they are
     * form-scoped, only for managers who stay within their own forms. Otherwise a scoped
     * full-access manager could lift any user, including themselves, to every form; removal
     * counts too, because it hands a delegated-role user back their all-forms role fallback.
     */
    private function guardDelegatedGrant($user, $manager = null)
    {
        if (current_user_can('manage_options')) {
            return;
        }

        // Lowering your own access is fine; only a permission you do not already hold is refused.
        $isSelf = get_current_user_id() === $user->ID;
        if ($isSelf && (!$manager || array_diff((array) Arr::get($manager, 'permissions', []), Acl::getUserPermissions($user)))) {
            throw new ValidationException('', 0, null, ['message' => __('You cannot change your own manager access.', 'fluentform')]);
        }

        if (user_can($user, 'manage_options')) {
            throw new ValidationException('', 0, null, ['message' => __('You cannot change an administrator\'s manager access.', 'fluentform')]);
        }

        $actorScope = FormManagerService::getUserAllowedFormsScope();
        if (false === $actorScope) {
            return;
        }

        $actorScope = array_map('intval', (array) $actorScope);
        $isWithinActorScope = function ($scope) use ($actorScope) {
            // [] is a restriction to no forms (fails closed), so it sits inside any scope; only false means all forms.
            return false !== $scope && !array_diff(array_map('intval', (array) $scope), $actorScope);
        };

        $targetScope = FormManagerService::getUserAllowedFormsScope($user->ID);

        // addManager() keeps the current scope when the flag is omitted, so judge the scope that will result.
        $resultingScope = $targetScope;
        if ($manager && null !== Arr::get($manager, 'has_specific_forms_permission')) {
            $requestedForms = array_values(array_filter(array_map('intval', (array) Arr::get($manager, 'forms', []))));
            $resultingScope = 'yes' === Arr::get($manager, 'has_specific_forms_permission') && $requestedForms ? $requestedForms : false;
        }

        // A delegated-role user already reaches every form, so limiting them is also out of reach.
        $hasAccessNow = Acl::userHasDirectGrant($user->ID, $user) || Acl::findUserCapability($user);

        if (!$isWithinActorScope($resultingScope) || ($hasAccessNow && !$isWithinActorScope($targetScope))) {
            throw new ValidationException('', 0, null, ['message' => __('You can only manage access to forms you can access yourself.', 'fluentform')]);
        }
    }

    public function removeManager($attributes = [])
    {
        $userID = intval(Arr::get($attributes, 'id'));
        $user = get_user_by('ID', $userID);

        if (!$user) {
            return ([
                'message' => __('Associate user could not be found', 'fluentform'),
            ]);
        }

        // Removal deletes _fluent_forms_has_role, which is what suppresses the role fallback.
        if (get_current_user_id() === $user->ID) {
            return ([
                'message' => __('You cannot remove yourself as a manager.', 'fluentform'),
            ]);
        }

        // Mirrors the grant-side guard at :73.
        if (in_array('fluentform_full_access', (array) Acl::getUserPermissions($user), true) && !current_user_can('manage_options')) {
            return ([
                'message' => __('You do not have permission to remove a full access manager.', 'fluentform'),
            ]);
        }

        $this->guardDelegatedGrant($user);

        Acl::attachPermissions($user, []);

        delete_user_meta($user->ID, '_fluent_forms_has_role');

        $deletedUser = [
            'id'          => $user->ID,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'email'       => $user->user_email,
            'permissions' => Acl::getUserPermissions($user),
        ];

        return ([
            'message' => __('Manager has been removed.', 'fluentform'),
            'manager' => $deletedUser,
        ]);
    }

    private function validate($manager)
    {
        $rules = [
            'permissions' => 'required',
            'email'       => 'required|email',
        ];

        $validatorInstance = new Validator();
        $validator = $validatorInstance->make($manager, $rules);

        $errors = null;

        if ($validator->validate()->fails()) {
            $errors = $validator->errors();
        }

        if (!isset($errors['email'])) {
            $user = get_user_by('email', $manager['email']);

            if (!$user) {
                $errors['email'] = [
                    'no_user' => __('We could not found any user with this email.', 'fluentform'),
                ];
            }
        }

        if (!isset($errors['permissions'])) {
            $message = $this->dependencyValidate($manager['permissions']);

            if ($message) {
                $errors['permissions'] = [
                    'dependency' => $message,
                ];
            }
        }

        if ($errors) {
            // Escape all error messages before throwing
            $escapedErrors = array_map(function ($errorMessages) {
                if (is_array($errorMessages)) {
                    return array_map('esc_html', $errorMessages);
                }
                return esc_html($errorMessages);
            }, $errors);

            throw new ValidationException('', 0, null, [
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages are escaped above
                'errors' => $escapedErrors,
            ]);
        }
    }

    private function dependencyValidate($permissions)
    {
        $allPermissions = Acl::getReadablePermissions();

        foreach ($permissions as $permission) {
            $depends = Arr::get($allPermissions, $permission . '.depends', []);

            if ($depends && $more = array_values(array_diff($depends, $permissions))) {
                $message = $allPermissions[$permission]['title'] . ' requires permission: ';

                foreach ($more as $i => $p) {
                    $joiner = $i ? ', ' : '';
                    $message = $message . $joiner . $allPermissions[$p]['title'];
                }

                return $message;
            }
        }
    }

    private function getUserRoles($roles)
    {
        $roleStr = '';
        if (count($roles) > 1) {
            foreach ($roles as $role) {
                $roleStr .= $role . ', ';
            }
        } else {
            $roleStr = Arr::get($roles, '0');
        }

        return $roleStr;
    }
}
