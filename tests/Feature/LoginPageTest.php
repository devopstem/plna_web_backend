<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class LoginPageTest extends TestCase
{
    public function test_login_page_is_reachable(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
