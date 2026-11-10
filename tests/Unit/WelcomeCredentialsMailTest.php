<?php

namespace Tests\Unit;

use App\Mail\WelcomeCredentialsMail;
use App\Models\User;
use Tests\TestCase;

class WelcomeCredentialsMailTest extends TestCase
{
    public function test_password_markdown_symbols_are_rendered_literally(): void
    {
        $mail = new WelcomeCredentialsMail(
            new User(['name' => 'Jane Provider', 'email' => 'jane@practice.com']),
            'Ab1*cd*Ef2',
        );

        $this->assertStringContainsString('<code>Ab1*cd*Ef2</code>', $mail->render());
    }
}
