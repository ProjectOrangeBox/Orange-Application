<?php

declare(strict_types=1);

use application\api\controllers\CalendarController;
use application\api\models\CalendarEventModel;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;
use orange\framework\interfaces\RouterInterface;

final class CalendarControllerTest extends unitTestHelper
{
    protected Data $data;
    protected Output $output;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        $this->data = Data::newInstance();
        $this->output = Output::newInstance([], Input::newInstance([]));

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('input', Input::newInstance([]));
        // BaseController attaches the router now that it, not the view engine,
        // resolves $c/$m view names - so every controller needs one present
        $container->set('router', $this->createStub(RouterInterface::class));
        // renderView() resolves the name through the view finder before the
        // view engine ever sees it, so controllers need one of these too
        $container->set('viewFinder', new MockViewFinderService());
        $container->set('output', $this->output);
        $container->set('data', $this->data);
        $container->set('CalendarEventModel', CalendarEventModel::getInstance($this->makePdo()));
    }

    protected function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE calendar_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                event_date TEXT NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO calendar_events (title, description, event_date) VALUES
                ('Planning', 'Monthly planning', '2026-07-10'),
                ('August kickoff', 'Next month', '2026-08-01')
            SQL);

        return $pdo;
    }

    public function testMonthReturnsEventsForRequestedMonth(): void
    {
        $json = new CalendarController()->month('2026-07');
        $events = json_decode($json, true);

        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertCount(1, $events);
        $this->assertSame(['id', 'title', 'description', 'date'], array_keys($events[0]));
        $this->assertSame('Planning', $events[0]['title']);
    }

    public function testCreateRejectsInvalidPayload(): void
    {
        $controller = $this->controllerWithJsonInput([
            'title' => '',
            'description' => 'Missing title',
            'date' => '2026-07-14',
        ]);

        $json = $controller->create();

        $this->assertEquals(422, $this->output->getResponseCode());
        $this->assertArrayHasKey('title', json_decode($json, true)['errors']);
    }

    public function testCreateUpdateAndDelete(): void
    {
        $controller = $this->controllerWithJsonInput([
            'title' => 'Lunch',
            'description' => 'Team lunch',
            'date' => '2026-07-14',
        ]);

        $json = $controller->create();
        $id = json_decode($json, true)['id'];

        $this->assertEquals(201, $this->output->getResponseCode());

        $controller = $this->controllerWithJsonInput([
            'title' => 'Lunch Updated',
            'description' => 'Team lunch moved',
            'date' => '2026-07-15',
        ]);

        $json = $controller->update((string) $id);

        $this->assertEquals(200, $this->output->getResponseCode());
        $this->assertEquals(['success' => true], json_decode($json, true));

        $json = new CalendarController()->delete((string) $id);

        $this->assertEquals(204, $this->output->getResponseCode());
        $this->assertSame('', $json);
    }

    protected function controllerWithJsonInput(array $request): CalendarController
    {
        Container::getInstance()->set('input', Input::newInstance(['request' => $request]));

        return new CalendarController();
    }
}
