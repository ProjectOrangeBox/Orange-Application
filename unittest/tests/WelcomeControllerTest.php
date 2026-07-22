<?php

declare(strict_types=1);

use api\controllers\WelcomeController;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;

final class WelcomeControllerTest extends UnitTestHelper
{
    protected $instance;
    protected Data $data;
    protected Output $output;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        // fresh services every test (see MainControllerTest for the reasoning)
        $this->data = Data::newInstance();
        $this->output = Output::newInstance([], Input::newInstance([]));

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('input', Input::newInstance([]));
        $container->set('output', $this->output);
        $container->set('data', $this->data);

        $this->instance = new WelcomeController();
    }

    public function testIndexReturnsWelcomeMessage(): void
    {
        $json = $this->instance->index();

        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertEquals('application/json', $this->output->getContentType());
        $this->assertEquals(['msg' => 'Welcome to My Vue App'], json_decode((string) $json, true));
    }
}
