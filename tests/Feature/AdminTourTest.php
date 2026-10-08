<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminTourTest extends TestCase
{
    use RefreshDatabase;

    private function tourSource(): string
    {
        return file_get_contents(resource_path('js/admin-tour.js'));
    }

    /** @return array<int, string> */
    private function allViewSource(): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        $source = '';

        foreach ($files as $file) {
            $source .= file_get_contents($file->getPathname());
        }

        return [$source];
    }

    public function test_every_tour_step_points_at_a_data_tour_marker_that_exists_in_the_views(): void
    {
        preg_match_all("/step\('([a-z-]+)'/", $this->tourSource(), $matches);
        $targets = array_unique($matches[1]);
        [$views] = $this->allViewSource();

        $this->assertNotEmpty($targets);

        foreach ($targets as $target) {
            $this->assertStringContainsString('data-tour="'.$target.'"', $views, "No view has data-tour=\"{$target}\", so that tour step would never show.");
        }
    }

    public function test_every_tour_is_keyed_by_a_real_admin_route(): void
    {
        preg_match_all("/^    '(admin\.[a-z.-]+)': \[/m", $this->tourSource(), $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $routeName) {
            $this->assertTrue(Route::has($routeName), "Tour is keyed by unknown route {$routeName}.");
        }
    }

    public function test_admin_pages_expose_their_route_name_and_the_tour_button(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach (['admin.dashboard', 'admin.submissions', 'admin.orders', 'admin.leads', 'admin.specialist-calls', 'admin.users'] as $routeName) {
            $this->withoutVite()->actingAs($admin)->get(route($routeName))
                ->assertOk()
                ->assertSee('data-admin-page="'.$routeName.'"', false)
                ->assertSee('Take a tour');
        }
    }

    public function test_client_pages_do_not_get_the_admin_tour(): void
    {
        $client = User::factory()->create();

        $this->withoutVite()->actingAs($client)->get(route('portal'))
            ->assertOk()
            ->assertDontSee('data-admin-page', false)
            ->assertDontSee('Take a tour');
    }
}
