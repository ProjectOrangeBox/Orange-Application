<?php

declare(strict_types=1);

use application\welcome\controllers\MainController;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;

final class MainControllerTest extends UnitTestHelper
{
    protected $instance;
    protected Data $data;
    protected MockViewService $view;
    protected MockConfigService $config;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        // fresh services every test so nothing leaks through the container/Data
        // singletons between test methods
        $this->data = Data::newInstance();
        $this->view = new MockViewService();
        // seeded with recognisable values so the assertions prove the controller
        // reads them from config rather than hard-coding anything
        $this->config = new MockConfigService([
            'application' => [
                'position' => 'Test Position',
                'h1' => 'Test Heading',
                'this file' => '/test/config/application.php',
            ],
        ]);

        $container = Container::getInstance();
        $container->set('config', $this->config);
        $container->set('input', Input::newInstance([]));
        $container->set('output', Output::newInstance([], Input::newInstance([])));
        $container->set('data', $this->data);
        $container->set('view', $this->view);

        $this->instance = new MainController();
    }

    public function testIndexRendersTheMainIndexView(): void
    {
        $this->instance->index();

        $this->assertCount(1, $this->view->renderCalls);
        $this->assertEquals('main/index', $this->view->renderCalls[0]['view']);
    }

    public function testIndexReturnsTheRenderedView(): void
    {
        // MockViewService::render() returns "rendered:<view>"
        $this->assertEquals('rendered:main/index', $this->instance->index());
    }

    public function testIndexMergesConfigDrivenValues(): void
    {
        $this->instance->index();

        // these come straight from the (mocked) application config
        $this->assertEquals('Test Heading', $this->data['h1']);
        $this->assertEquals('Test Position', $this->data['position']);
        $this->assertEquals('/test/config/application.php', $this->data['file']);
    }

    public function testIndexSetsIndividualValues(): void
    {
        $this->instance->index();

        // assigned one at a time via ArrayAccess in the controller
        $this->assertEquals('AROUND THE WEB', $this->data['around']);
        $this->assertEquals('Johnny Appleseed', $this->data['name']);
    }

    public function testIndexMergesStaticValues(): void
    {
        $this->instance->index();

        $this->assertEquals('19.95', $this->data['cash']);
        $this->assertEquals('123 South Main Street<br />Somewhere, AZ 12345', $this->data['address']);

        // keys the view expects to always exist, even when empty
        foreach (['css', 'script', 'js', 'about', 'aboutText'] as $key) {
            $this->assertArrayHasKey($key, (array)$this->data);
        }
    }
}
