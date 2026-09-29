<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nenhum teste fala com a rede. A Meta e a Anthropic sao simuladas (Http::fake,
     * MockProvider): uma requisicao real aqui seria um post no Instagram de alguem ou
     * uma chamada paga — e o teste que a fizesse passaria por acaso.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
