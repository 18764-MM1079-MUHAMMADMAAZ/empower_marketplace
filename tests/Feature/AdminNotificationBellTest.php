<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\NewSignupNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_shows_unread_count_and_lists_notifications(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['name' => 'Jane Provider']);
        $admin->notify(new NewSignupNotification($client));

        Livewire::actingAs($admin)
            ->test('notification-bell')
            ->assertSet('unreadCount', 1)
            ->assertSee('New account created')
            ->assertSee('Jane Provider');
    }

    public function test_bell_only_shows_the_authenticated_admins_own_notifications(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $otherAdmin->notify(new NewSignupNotification($client));

        Livewire::actingAs($admin)
            ->test('notification-bell')
            ->assertSet('unreadCount', 0)
            ->assertSee('No notifications yet');
    }

    public function test_opening_a_notification_marks_it_read_and_redirects_to_its_url(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $admin->notify(new NewSignupNotification($client));
        $notification = $admin->notifications()->first();

        Livewire::actingAs($admin)
            ->test('notification-bell')
            ->call('openNotification', $notification->id)
            ->assertRedirect(route('admin.users.edit', $client));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_clears_the_unread_count(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $admin->notify(new NewSignupNotification($client));
        $admin->notify(new NewSignupNotification($client));

        Livewire::actingAs($admin)
            ->test('notification-bell')
            ->assertSet('unreadCount', 2)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }
}
