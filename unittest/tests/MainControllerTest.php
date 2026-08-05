<?php

declare(strict_types=1);

use application\welcome\controllers\MainController;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;
use orange\framework\interfaces\RouterInterface;
use orange\session\SessionInterface;

final class MainControllerTest extends unitTestHelper
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
        // BaseController attaches the router now that it, not the view engine,
        // resolves $c/$m view names - so every controller needs one present
        $container->set('router', $this->createStub(RouterInterface::class));
        // renderView() resolves the name through the view finder before the
        // view engine ever sees it, so controllers need one of these too
        $container->set('viewFinder', new MockViewFinderService());
        // The home page is still public, but it extends WebController so the
        // shared navbar can say who is signed in - which means it now reads the
        // current user, and therefore the session.
        $container->set('user', $this->makeUser(isGuest: true));
        $container->set('session', $this->createStub(SessionInterface::class));

        $this->instance = new MainController();
    }

    /**
     * An acl User whose load() hands back an entity answering isGuest() as told.
     *
     * The entity is a stub of the interface rather than a real UserEntity: a
     * real one needs a UserModel, a database and a role/permission cascade to
     * answer a question this test does not ask.
     */
    protected function makeUser(bool $isGuest): User
    {
        $entity = $this->createStub(UserEntityInterface::class);
        $entity->method('isGuest')->willReturn($isGuest);

        $user = $this->createStub(User::class);
        $user->method('load')->willReturn($entity);

        return $user;
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

    /**
     * The home page mentions no user, and must not need one to render.
     *
     * WebController used to attach the user service, which made it a
     * construction dependency: the container built user -> acl -> pdo before
     * this controller existed, so an unreachable database answered the
     * marketing page with a stack trace. A fresh clone that has not run the
     * migrations yet is exactly that state.
     */
    public function testIndexRendersWithNoAccountsDatabase(): void
    {
        $this->givenTheAccountsDatabaseIsUnreachable();

        $this->assertEquals('rendered:main/index', $this->instance->index());
    }

    public function testNoAccountsDatabaseIsReportedToTheNav(): void
    {
        $this->givenTheAccountsDatabaseIsUnreachable();

        $this->instance->index();

        // what the navbar reads to leave out Log In and Sign Up - both lead to
        // pages that cannot work without the accounts it could not reach
        $this->assertFalse($this->data['accountsAvailable']);
        // and no-accounts is never mistaken for someone being signed in
        $this->assertFalse($this->data['isLoggedIn']);
        $this->assertNull($this->data['currentUser']);
    }

    public function testAccountsAreReportedAvailableWhenTheyAre(): void
    {
        // the container set up in setUp() has a working user service
        $this->instance->index();

        $this->assertTrue($this->data['accountsAvailable']);
        // a guest is present but not signed in - a different answer to
        // "there are no accounts to ask", and the nav shows Log In for it
        $this->assertFalse($this->data['isLoggedIn']);
        $this->assertInstanceOf(UserEntityInterface::class, $this->data['currentUser']);
    }

    /**
     * Replace the user service with one that throws the way an absent database
     * does - PDO fails at connect, so it fails when the service is built rather
     * than when it is asked anything.
     */
    protected function givenTheAccountsDatabaseIsUnreachable(): void
    {
        Container::getInstance()->set('user', function (): User {
            throw new PDOException('SQLSTATE[HY000] [2002] Network is unreachable');
        });

        // rebuilt so it resolves the replacement rather than a service it has
        // already cached for this request
        $this->instance = new MainController();
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
