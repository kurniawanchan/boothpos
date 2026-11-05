<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuActions;
use App\Support\MenuKeys;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — registri aksi per menu: lengkap, konsisten, dan salah ketik tak lolos diam-diam. */
class MenuActionsRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_menu_resolves_without_error_and_view_is_always_valid(): void
    {
        foreach (MenuKeys::keys() as $menu) {
            $this->assertIsArray(MenuActions::for($menu));
            $this->assertTrue(MenuActions::isValid($menu, 'view'), $menu);
            $this->assertSame('view', MenuActions::withView($menu)[0]);
        }
    }

    public function test_sales_has_exactly_the_named_actions_of_the_data_model(): void
    {
        $this->assertSame(['void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts'], MenuActions::for('sales'));
    }

    public function test_standard_actions_are_only_create_edit_delete_and_never_duplicated(): void
    {
        foreach (MenuKeys::keys() as $menu) {
            $all = MenuActions::for($menu);
            $this->assertSame($all, array_values(array_unique($all)), "duplikat pada {$menu}");
            foreach ($all as $a) {
                $this->assertSame(in_array($a, ['create', 'edit', 'delete'], true), MenuActions::isStandard($a));
            }
        }
    }

    public function test_an_unknown_pair_is_invalid_and_can_do_throws(): void
    {
        $this->assertFalse(MenuActions::isValid('sales', 'create'));
        $this->assertFalse(MenuActions::isValid('nope', 'view'));

        $user = User::factory()->create();
        $user->update(['role_id' => Role::factory()->full()->create()->id]);
        $this->expectException(InvalidArgumentException::class);
        $user->fresh()->canDo('sales', 'typo');
    }

    public function test_every_action_has_a_label_in_both_languages(): void
    {
        foreach (['en', 'id'] as $lang) {
            app()->setLocale($lang);
            foreach (MenuKeys::keys() as $menu) {
                foreach (MenuActions::withView($menu) as $a) {
                    $this->assertNotSame('permissions.actions.'.$a, __('permissions.actions.'.$a), "label {$a} ({$lang})");
                }
            }
        }
    }

    public function test_http_methods_map_to_standard_actions(): void
    {
        $this->assertSame('create', MenuActions::fromHttpMethod('POST'));
        $this->assertSame('edit', MenuActions::fromHttpMethod('PATCH'));
        $this->assertSame('edit', MenuActions::fromHttpMethod('put'));
        $this->assertSame('delete', MenuActions::fromHttpMethod('DELETE'));
    }
}
