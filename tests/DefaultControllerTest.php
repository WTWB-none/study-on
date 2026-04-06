<?php

namespace App\Tests;

class DefaultControllerTest extends ApplicationWebTestCase
{
    public function testHomeRedirectsToCourseIndex(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/courses');
    }
}
