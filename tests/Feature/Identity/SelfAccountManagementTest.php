<?php

namespace Tests\Feature\Identity;

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SelfAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_own_profile_and_password_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Identity/Account/Profile')
                ->where('profile.name', $user->name)
                ->where('profile.email', $user->email));

        $this->actingAs($user)->get('/account/password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Identity/Account/Password'));
    }

    public function test_user_updates_only_own_profile_fields_with_audit(): void
    {
        $user = User::factory()->create(['email' => 'before@example.test']);
        $other = User::factory()->create(['name' => 'Other User']);

        $this->actingAs($user)->patch('/account/profile', [
            'name' => 'My Account',
            'email' => 'after@example.test',
            'contact_name' => 'Contact Person',
            'mobile' => '13800138000',
            'phone' => '021-12345678',
            'qq' => '12345678',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('My Account', $user->name);
        $this->assertSame('after@example.test', $user->email);
        $this->assertSame('Contact Person', $user->contact_name);
        $this->assertSame('13800138000', $user->mobile);
        $this->assertSame('021-12345678', $user->phone);
        $this->assertSame('12345678', $user->qq);
        $this->assertSame('Other User', $other->fresh()->name);

        $record = AuditRecord::query()->where('action', 'user.self_profile_updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $record->actor_id);
        $this->assertSame((string) $user->id, $record->entity_id);
    }

    public function test_user_must_confirm_current_password_before_changing_own_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'WrongPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $record = AuditRecord::query()->where('action', 'user.password_changed')->latest('id')->firstOrFail();
        $this->assertSame('password_changed_by_user', $record->reason);
        $this->assertNull($record->before);
        $this->assertNull($record->after);
    }

    public function test_self_password_accepts_six_characters_without_uppercase_and_keeps_legacy_login_compatible(): void
    {
        $legacyPassword = 'LongLegacyPasswordFromPreviousPolicy2025!';
        $user = User::factory()->create(['password' => $legacyPassword]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => $legacyPassword,
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('abc123', $user->fresh()->password));

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'abc123',
            'password' => 'abcdefghijklmnop',
            'password_confirmation' => 'abcdefghijklmnop',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('abc123', $user->fresh()->password));
    }
}
