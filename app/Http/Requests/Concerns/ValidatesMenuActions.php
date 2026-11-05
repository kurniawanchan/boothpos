<?php

namespace App\Http\Requests\Concerns;

use App\Support\MenuActions;
use App\Support\MenuKeys;
use Illuminate\Validation\Validator;

/**
 * 057 — validasi silang `menu_actions`: setiap menu harus dimiliki peran (pada `menu_keys` yang dikirim, atau yang
 * tersimpan bila tak dikirim) dan setiap aksi harus ada di registri MenuActions untuk menu itu. Pesan diterjemahkan.
 */
trait ValidatesMenuActions
{
    /** menu_keys yang berlaku untuk validasi ini. */
    abstract protected function effectiveMenuKeys(): array;

    protected function validateMenuActions(Validator $validator): void
    {
        $actions = $this->input('menu_actions');
        if (! is_array($actions)) {
            return;
        }
        $held = $this->effectiveMenuKeys();

        foreach ($actions as $menu => $list) {
            if (! MenuKeys::isValid((string) $menu)) {
                $validator->errors()->add('menu_actions', __('permissions.unknown_menu', ['menu' => $menu]));

                continue;
            }
            if (! in_array($menu, $held, true)) {
                $validator->errors()->add('menu_actions', __('permissions.menu_not_in_role', ['menu' => $menu]));

                continue;
            }
            foreach ((array) $list as $action) {
                if (! in_array($action, MenuActions::for($menu), true)) {
                    $validator->errors()->add('menu_actions', __('permissions.unknown_action', ['menu' => $menu, 'action' => $action]));
                }
            }
        }
    }
}
