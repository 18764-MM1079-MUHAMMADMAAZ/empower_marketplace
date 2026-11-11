<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_footer_shows_the_cobranded_logo_and_the_legal_links_before_contact_us(): void
    {
        $this->withoutVite()->get(route('home'))
            ->assertOk()
            ->assertSee('images/empower-carecloud-logo.png', false)
            ->assertSeeInOrder([
                'href="https://carecloud.com/privacy-policy/"',
                'Privacy Policy',
                'href="https://carecloud.app.box.com/v/ProactiveComplianceTerms"',
                'Terms of Use',
                'Contact us',
            ], false);
    }

    public function test_legal_links_open_in_a_new_tab_safely(): void
    {
        $html = $this->withoutVite()->get(route('home'))->getContent();

        foreach (['https://carecloud.com/privacy-policy/', 'https://carecloud.app.box.com/v/ProactiveComplianceTerms'] as $url) {
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'" target="_blank" rel="noopener noreferrer"#', $html);
        }
    }

    public function test_the_legal_links_also_appear_on_the_client_portal_footer(): void
    {
        $this->withoutVite()->actingAs(User::factory()->create())->get(route('portal'))
            ->assertOk()
            ->assertSee('https://carecloud.com/privacy-policy/', false)
            ->assertSee('https://carecloud.app.box.com/v/ProactiveComplianceTerms', false)
            ->assertSee('images/empower-carecloud-logo.png', false);
    }

    public function test_the_logo_file_exists(): void
    {
        $this->assertFileExists(public_path('images/empower-carecloud-logo.png'));
    }
}
