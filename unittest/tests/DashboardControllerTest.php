<?php

declare(strict_types=1);

use application\welcome\controllers\DashboardController;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\interfaces\RouterInterface;

/**
 * The protected page's two gates: authentication, then authorization.
 *
 * TestSession and TestOutput come from SessionControllerTest, which the runner
 * loads in the same suite. Required explicitly so this file also passes when
 * run on its own.
 */
require_once __DIR__ . '/SessionControllerTest.php';

final class DashboardControllerTest extends unitTestHelper
{
    protected TestSession $session;
    protected TestOutput $output;
    protected Data $data;
    protected MockViewService $view;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        $this->session = new TestSession();
    }

    /**
     * @param list<string> $permissions
     */
    protected function makeController(bool $isGuest, array $permissions = []): DashboardController
    {
        $input = Input::newInstance([
            'server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard', 'REMOTE_ADDR' => '203.0.113.5'],
            'request' => [],
        ]);

        $this->output = TestOutput::newInstance([], $input);
        $this->data = Data::newInstance();
        $this->view = new MockViewService();

        $entity = $this->createStub(UserEntityInterface::class);
        $entity->method('isGuest')->willReturn($isGuest);
        // the real thing walks roles to permissions in the database; what
        // matters here is only that the controller asks it and believes it
        $entity->method('can')->willReturnCallback(
            static fn(string $permission): bool => in_array($permission, $permissions, true)
        );
        $entity->method('hasRole')->willReturn($permissions !== []);

        $user = $this->createStub(User::class);
        $user->method('load')->willReturn($entity);

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('input', $input);
        $container->set('output', $this->output);
        $container->set('data', $this->data);
        $container->set('view', $this->view);
        $container->set('router', $this->createStub(RouterInterface::class));
        $container->set('viewFinder', new MockViewFinderService());
        $container->set('session', $this->session);
        $container->set('user', $user);

        return new DashboardController();
    }

    /* the authentication gate */

    public function testAGuestNeverSeesThePage(): void
    {
        $this->makeController(isGuest: true)->index();

        $this->assertSame([], $this->view->renderCalls);
        $this->assertSame(303, $this->output->getResponseCode());
    }

    public function testAGuestIsSentToTheLoginFormWithThisPageRemembered(): void
    {
        $this->makeController(isGuest: true)->index();

        // stored server-side rather than passed as ?return=, so there is no
        // client-supplied redirect target to validate in the first place
        $this->assertSame('/dashboard', $this->session->get('return_to'));
    }

    public function testALoggedInVisitorGetsThePage(): void
    {
        $this->makeController(isGuest: false)->index();

        $this->assertCount(1, $this->view->renderCalls);
        $this->assertEquals('dashboard/index', $this->view->renderCalls[0]['view']);
    }

    /* the authorization gate */

    public function testPermissionsAreReportedFromAclNotAssumed(): void
    {
        $this->makeController(isGuest: false, permissions: ['orders.create'])->index();

        $permissions = $this->data['permissions'];

        $this->assertTrue($permissions['orders.create']['granted']);
        $this->assertFalse($permissions['orders.update']['granted']);
        $this->assertFalse($permissions['orders.delete']['granted']);
    }

    public function testEveryGuardedOrdersPermissionIsReported(): void
    {
        $this->makeController(isGuest: false)->index();

        // the same three the orders API guards its writes with - a page that
        // reported on fewer would quietly stop matching what it describes
        $this->assertSame(
            ['orders.create', 'orders.update', 'orders.delete'],
            array_keys((array) $this->data['permissions'])
        );
    }

    public function testTheDangerPanelIsWithheldWithoutTheDeletePermission(): void
    {
        $this->makeController(isGuest: false, permissions: ['orders.create', 'orders.update'])->index();

        $this->assertFalse($this->data['mayDelete']);
    }

    public function testTheDangerPanelIsShownWithIt(): void
    {
        $this->makeController(isGuest: false, permissions: ['orders.delete'])->index();

        $this->assertTrue($this->data['mayDelete']);
    }

    public function testAnAccountWithNoPermissionsStillGetsThePage(): void
    {
        // being signed in is enough to see the dashboard; what it *shows* is
        // where the permissions bite. The two gates are separate on purpose.
        $this->makeController(isGuest: false)->index();

        $this->assertEquals('dashboard/index', $this->view->renderCalls[0]['view']);

        foreach ((array) $this->data['permissions'] as $permission) {
            $this->assertFalse($permission['granted']);
        }
    }
}
