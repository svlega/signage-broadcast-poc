<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * No public marketing page in this app (see routes/web.php) — root
     * sends a visitor straight to the admin panel.
     */
    public function test_the_root_url_redirects_to_the_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
