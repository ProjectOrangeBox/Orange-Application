<?php

declare(strict_types=1);

use api\controllers\RestController;
use api\models\RecordDto;
use api\models\RecordModel;
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
        $container->set('RecordModel', RecordModel::getInstance($this->makePdo()));

        $this->instance = new RestController();
    }

    /**
     * In-memory SQLite stand-in for the production database, seeded with two
     * records so index() has rows to return. Safe to build per test — the
     * suite runs with process isolation, so the RecordModel singleton never
     * outlives a test.
     */
    protected function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                phone TEXT NOT NULL DEFAULT '',
                in_office INTEGER NOT NULL DEFAULT 0,
                out_until TEXT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO records (name, phone, in_office, out_until) VALUES
                ('Don Myers', '555-1234', 1, NULL),
                ('Jane Doe', '555-9876', 0, '2026-01-15 13:30:00')
            SQL);

        return $pdo;
    }

    public function testIndexReturnsJsonListOfRecords(): void
    {
        $json = $this->instance->index();

        $this->assertJson($json);

        $records = json_decode((string) $json, true);

        // a top-level JSON array of records, each with the RecordDto fields
        $this->assertIsList($records);

        foreach ($records as $record) {
            $this->assertSame(
                ['id', 'name', 'phone', 'in_office', 'out_until'],
                array_keys($record)
            );
        }
    }

    public function testIndexSetsOkResponseCodeAndJsonContentType(): void
    {
        $this->instance->index();

        // listResponse() defaults to 200
        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertEquals('application/json', $this->output->getContentType());
    }

    public function testResponseSetsGivenStatusCode(): void
    {
        foreach ([200, 201, 202, 204, 400, 401, 405, 422] as $code) {
            $this->callMethod('response', [$code], $this->instance);

            $this->assertEquals($code, $this->output->getResponseCode(), 'status code ' . $code);
        }
    }

    public function testResponseDefaultsTo200(): void
    {
        $this->callMethod('response', [], $this->instance);

        $this->assertEquals(200, $this->output->getResponseCode());
    }

    public function testResponseReturnsRawBodyWhenProvided(): void
    {
        $this->data->merge(['ignored' => true]);

        $json = $this->callMethod('response', [200, '{"raw":true}'], $this->instance);

        $this->assertEquals('{"raw":true}', $json);
    }

    public function testResponseEncodesCurrentData(): void
    {
        $this->data->merge(['a' => 1, 'b' => 'two']);

        $json = $this->callMethod('response', [], $this->instance);

        $this->assertEquals(['a' => 1, 'b' => 'two'], json_decode((string) $json, true));
    }

    public function testNotFoundResponseSendsMsgWith404(): void
    {
        $json = $this->callMethod('notFoundResponse', [], $this->instance);

        $this->assertEquals(404, $this->output->getResponseCode());
        $this->assertEquals('application/json', $this->output->getContentType());
        $this->assertEquals(['msg' => 'Record not found'], json_decode((string) $json, true));
    }

    public function testValidationErrorResponseSendsFieldKeyedErrorsWith422(): void
    {
        // id fails Integer, in_office fails IsBoolean; name satisfies
        // MaxLength(64) so it stays error-free
        $dto = new RecordDto(['id' => 'abc', 'name' => 'Myers', 'phone' => '555', 'in_office' => 'maybe']);

        $json = $this->callMethod('validationErrorResponse', [$dto], $this->instance);

        $this->assertEquals(422, $this->output->getResponseCode());
        $this->assertEquals('application/json', $this->output->getContentType());

        $payload = json_decode((string) $json, true);

        $this->assertIsArray($payload['errors']);
        $this->assertArrayHasKey('id', $payload['errors']);
        $this->assertArrayHasKey('in_office', $payload['errors']);
        $this->assertArrayNotHasKey('name', $payload['errors']);
    }

    public function testCreatePersistsAVueStylePayload(): void
    {
        // exactly what the Vue form submits: JSON booleans and nulls —
        // in_office false must survive the trip into an integer column
        $controller = $this->controllerWithJsonInput([
            'name' => 'New Person',
            'phone' => '555-2222',
            'in_office' => false,
            'out_until' => null,
        ]);

        $json = $controller->create();

        $this->assertEquals(201, $this->output->getResponseCode());
        $this->assertGreaterThan(0, json_decode($json, true)['id']);
    }

    public function testUpdateAcceptsAValidVueStylePayload(): void
    {
        // regression: update() once had its isValid() check inverted, so
        // every valid edit-form submission came back as a 422
        $controller = $this->controllerWithJsonInput([
            'name' => 'Don Myers',
            'phone' => '555-4321',
            'in_office' => false,
            'out_until' => null,
        ]);

        $json = $controller->update('1');

        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertEquals(['success' => true], json_decode($json, true));
    }

    public function testUpdateRejectsAnInvalidPayloadWith422(): void
    {
        $controller = $this->controllerWithJsonInput([
            'name' => '',
            'phone' => '555-4321',
            'in_office' => 'maybe',
            'out_until' => null,
        ]);

        $json = $controller->update('1');

        $this->assertEquals(422, $this->output->getResponseCode());

        $errors = json_decode($json, true)['errors'];

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('in_office', $errors);
    }

    /**
     * The controller attaches its services at construction, so swap in an
     * input service carrying the request body before building it.
     */
    protected function controllerWithJsonInput(array $request): RestController
    {
        Container::getInstance()->set('input', Input::newInstance(['request' => $request]));

        return new RestController();
    }

    public function testUpdateInputDefaultsMissingInOffice(): void
    {
        // an update without in_office falls back to DefaultTo(0) -> false,
        // while an explicit null out_until is kept so the date can be cleared;
        // NormalizePhone strips the phone's cosmetic dash
        $dto = new RecordDto(['id' => 7, 'name' => 'Don', 'phone' => '555-1234', 'out_until' => null]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(
            ['id' => 7, 'name' => 'Don', 'phone' => '5551234', 'in_office' => false, 'out_until' => null],
            $dto->asColumns()
        );
    }
}
