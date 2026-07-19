<?php

declare(strict_types=1);

use api\controllers\RestController;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;

final class RestControllerTest extends UnitTestHelper
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

        $this->instance = new RestController();
    }

    public function testIndexReturnsJsonPayload(): void
    {
        $json = $this->instance->index();

        $this->assertJson($json);
        $this->assertEquals(['msg' => 'Welcome to My Vue App'], json_decode((string) $json, true));
    }

    public function testIndexSetsOkResponseCodeAndJsonContentType(): void
    {
        $this->instance->index();

        // restResponse() defaults to the 'ok' status -> 200
        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertEquals('application/json', $this->output->getContentType());
    }

    public function testResponseMapsSuccessStatusesToCodes(): void
    {
        // drive the protected restResponse() directly with each mapped status
        $expected = [
            'ok' => 200,
            'get' => 200,
            'getNew' => 200,
            'getAll' => 200,
            'getById' => 200,
            'read' => 200,
            'create' => 201,
            'post' => 201,
            'update' => 202,
            'put' => 202,
            'patch' => 202,
            'delete' => 202,
            'unknown' => 400,
            'badMethod' => 405,
            'validationFail' => 406,
            'noAuth' => 401,
            'success' => 202,
        ];

        foreach ($expected as $status => $code) {
            $this->callMethod('response', [$status], $this->instance);

            $this->assertEquals($code, $this->output->getResponseCode(), 'status "' . $status . '"');
        }
    }

    public function testResponseFallsBackTo500ForUnknownStatus(): void
    {
        $this->callMethod('response', ['not-a-real-status'], $this->instance);

        $this->assertEquals(500, $this->output->getResponseCode());
    }

    public function testResponseEncodesCurrentData(): void
    {
        $this->data->merge(['a' => 1, 'b' => 'two']);

        $json = $this->callMethod('response', ['ok'], $this->instance);

        $this->assertEquals(['a' => 1, 'b' => 'two'], json_decode((string) $json, true));
    }
}
