<?php

/**
 * Test: Kdyby\Events\Extension.
 *
 * @testCase
 */

namespace KdybyTests\Events;

use Kdyby\Events\DI\EventsExtension;
use Kdyby\Events\Event;
use Kdyby\Events\EventManager;
use Kdyby\Events\IExceptionHandler;
use Nette\Application\Application;
use Nette\Configurator;
use Nette\Security\User;
use ReflectionProperty;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class ExtensionTest extends \Tester\TestCase
{

	/**
	 * @param string $configFile
	 * @return \Nette\DI\Container
	 */
	public function createContainer($configFile)
	{
		$config = new Configurator();
		$config->setTempDirectory(TEMP_DIR);
		$config->addParameters(['container' => ['class' => 'SystemContainer_' . md5($configFile)]]);
		EventsExtension::register($config);
		$config->addConfig(__DIR__ . '/../nette-reset.neon');
		$config->addConfig(__DIR__ . '/config/' . $configFile . '.neon');
		return $config->createContainer();
	}

	public function testRegisterListeners()
	{
		$container = $this->createContainer('subscribers');
		$manager = $container->getService('events.manager');
		/** @var \Kdyby\Events\EventManager $manager */
		Assert::true($manager instanceof EventManager);
		Assert::equal(2, count($manager->getListeners()));
	}

	public function testRegisterListenersWithSameArguments()
	{
		$container = $this->createContainer('subscribersWithSameArgument');
		$manager = $container->getService('events.manager');

		/** @var \Kdyby\Events\EventManager $manager */
		Assert::true($manager instanceof EventManager);
		Assert::same(['onFoo'], array_keys($manager->getListeners()));
		Assert::count(2, $manager->getListeners('onFoo'));
	}

	public function testValidateDirect()
	{
		Assert::exception(function () {
			$this->createContainer('validate.direct');
		}, \Nette\Utils\AssertionException::class, 'Please, do not register listeners directly to service @events.manager. %a%');
	}

	public function testValidateMissing()
	{
		try {
			$this->createContainer('validate.missing');
			Assert::fail('Expected exception');

		} catch (\Nette\Utils\AssertionException $e) {
			Assert::match(
				'Please, specify existing class for service \'events.subscriber.%a%\' explicitly, and make sure, that the class exists and can be autoloaded.',
				$e->getMessage()
			);

		} catch (\Nette\DI\ServiceCreationException $e) {
			Assert::match("Service 'events.subscriber.0': Class 'NonExistingClass_%a%' not found%a?%.", $e->getMessage());

		} catch (\Exception $e) {
			Assert::fail($e->getMessage());
		}
	}

	public function testValidateFake()
	{
		Assert::exception(function () {
			$this->createContainer('validate.fake');
		}, \Nette\Utils\AssertionException::class, 'Subscriber @events.subscriber.%a% doesn\'t implement Kdyby\Events\Subscriber.');
	}

	public function testValidateInvalid()
	{
		Assert::exception(function () {
			$this->createContainer('validate.invalid');
		}, \Nette\Utils\AssertionException::class, 'Event listener KdybyTests\Events\FirstInvalidListenerMock::onFoo() is not implemented.');
	}

	public function testValidateInvalid2()
	{
		Assert::exception(function () {
			$this->createContainer('validate.invalid2');
		}, \Nette\Utils\AssertionException::class, 'Event listener KdybyTests\Events\SecondInvalidListenerMock::onBar() is not implemented.');
	}

	public function testAutowire()
	{
		$container = $this->createContainer('autowire');

		/** @var \Nette\Application\Application $app */
		$app = $container->getService('application');

		$onStartupEvent = $this->getEventFromListenersProperty($app->onStartup);
		Assert::true($onStartupEvent instanceof Event);
		Assert::same(Application::class . '::onStartup', $onStartupEvent->getName());

		$onRequestEvent = $this->getEventFromListenersProperty($app->onRequest);
		Assert::true($onRequestEvent instanceof Event);
		Assert::same(Application::class . '::onRequest', $onRequestEvent->getName());

		$onResponseEvent = $this->getEventFromListenersProperty($app->onResponse);
		Assert::true($onResponseEvent instanceof Event);
		Assert::same(Application::class . '::onResponse', $onResponseEvent->getName());

		$onErrorEvent = $this->getEventFromListenersProperty($app->onError);
		Assert::true($onErrorEvent instanceof Event);
		Assert::same(Application::class . '::onError', $onErrorEvent->getName());

		$onShutdownEvent = $this->getEventFromListenersProperty($app->onShutdown);
		Assert::true($onShutdownEvent instanceof Event);
		Assert::same(Application::class . '::onShutdown', $onShutdownEvent->getName());

		// not all properties are affected
		Assert::true(is_bool($app->catchExceptions));
		Assert::true(!is_object($app->errorPresenter));

		/** @var \Nette\Security\User $user */
		$user = $container->getService('user');

		$onLoggedInEvent = $this->getEventFromListenersProperty($user->onLoggedIn);
		Assert::true($onLoggedInEvent instanceof Event);
		Assert::same(User::class . '::onLoggedIn', $onLoggedInEvent->getName());

		$onLoggedOutEvent = $this->getEventFromListenersProperty($user->onLoggedOut);
		Assert::true($onLoggedOutEvent instanceof Event);
		Assert::same(User::class . '::onLoggedOut', $onLoggedOutEvent->getName());
	}

	public function testInherited()
	{
		$container = $this->createContainer('inherited');

		/** @var \KdybyTests\Events\LeafClass $leafObject */
		$leafObject = $container->getService('leaf');

		$onCreateEvent = $this->getEventFromListenersProperty($leafObject->onCreate);
		Assert::true($onCreateEvent instanceof Event);
		Assert::same(LeafClass::class . '::onCreate', $onCreateEvent->getName());

		$leafObject->create();

		/** @var \KdybyTests\Events\InheritSubscriber $subscriber */
		$subscriber = $container->getService('subscriber');

		/** @var \KdybyTests\Events\SecondInheritSubscriber $subscriber */
		$subscriber2 = $container->getService('subscriber2');

		Assert::same([
			LeafClass::class . '::onCreate' => 2,
			// not subscribed for middle class
		], $subscriber->eventCalls);

		Assert::same([
			LeafClass::class . '::onCreate' => 1,
			// not subscribed for middle class
		], $subscriber2->eventCalls);
	}

	public function testOptimize()
	{
		$container = $this->createContainer('optimize');
		$manager = $container->getService('events.manager');
		/** @var \Kdyby\Events\EventManager $manager */
		Assert::true($manager instanceof EventManager);

		Assert::false($container->isCreated('foo'));
		Assert::false($container->isCreated('bar'));
		Assert::false($container->isCreated('baz'));
		$bazArgs = new EventArgsMock();
		$manager->dispatchEvent('onFoo', $bazArgs);
		Assert::false($container->isCreated('foo'));
		Assert::true($container->isCreated('bar'));
		Assert::false($container->isCreated('baz'));
		Assert::same(1, count($manager->getListeners('onFoo')));

		$bazArgsSecond = new EventArgsMock();
		$manager->dispatchEvent('App::onFoo', $bazArgsSecond);
		Assert::same(1, count($manager->getListeners('App::onFoo')));

		$baz = $container->getService('baz');
		/** @var \KdybyTests\Events\NamespacedEventListenerMock $baz */
		$bar = $container->getService('bar');
		/** @var \KdybyTests\Events\EventListenerMock $bar */

		Assert::same([
			[EventListenerMock::class . '::onFoo', [$bazArgs]],
		], $bar->calls);

		Assert::same([
			[NamespacedEventListenerMock::class . '::onFoo', [$bazArgsSecond]],
		], $baz->calls);
	}

	public function testOptimizeDispatchNamespaceFirst()
	{
		$container = $this->createContainer('optimize');
		$manager = $container->getService('events.manager');
		/** @var \Kdyby\Events\EventManager $manager */
		Assert::true($manager instanceof EventManager);

		Assert::false($container->isCreated('foo'));
		Assert::false($container->isCreated('bar'));
		Assert::false($container->isCreated('baz'));
		$bazArgs = new EventArgsMock();
		$manager->dispatchEvent('App::onFoo', $bazArgs);
		Assert::false($container->isCreated('foo'));
		Assert::false($container->isCreated('bar'));
		Assert::true($container->isCreated('baz'));
		Assert::same(1, count($manager->getListeners('App::onFoo')));

		$baz = $container->getService('baz');
		/** @var \KdybyTests\Events\NamespacedEventListenerMock $baz */

		Assert::same([
			[NamespacedEventListenerMock::class . '::onFoo', [$bazArgs]],
		], $baz->calls);
	}

	public function testOptimizeStandalone()
	{
		$container = $this->createContainer('optimize');
		$manager = $container->getService('events.manager');
		/** @var \Kdyby\Events\EventManager $manager */
		Assert::true($manager instanceof EventManager);

		Assert::false($container->isCreated('foo'));
		Assert::false($container->isCreated('bar'));
		Assert::false($container->isCreated('baz'));
		$foo = new FooMock();
		$bazArgs = new StartupEventArgs($foo, 123);
		$manager->dispatchEvent('onStartup', $bazArgs);
		Assert::true($container->isCreated('foo'));
		Assert::false($container->isCreated('bar'));
		Assert::false($container->isCreated('baz'));
		Assert::same(1, count($manager->getListeners('onStartup')));

		/** @var \KdybyTests\Events\NamespacedEventListenerMock $baz */
		$baz = $container->getService('foo');

		Assert::same([
			[LoremListener::class . '::onStartup', [$bazArgs]],
		], $baz->calls);
	}

	public function testExceptionHandler()
	{
		$container = $this->createContainer('exceptionHandler');
		$manager = $container->getService('events.manager');

		// getter not needed, so hack it via reflection
		$rp = new ReflectionProperty(EventManager::class, 'exceptionHandler');
		$rp->setAccessible(TRUE);
		$handler = $rp->getValue($manager);

		Assert::true($handler instanceof IExceptionHandler);
	}

	public function testAutowireAlias()
	{
		$container = $this->createContainer('alias');
		Assert::same($container->getService('alias'), $container->getService('application'));
	}

	public function testFactoryAndAccessor()
	{
		$container = $this->createContainer('factory.accessor');

		$foo = $container->getService('foo');
		$fooOnBarEvent = $this->getEventFromListenersProperty($foo->onBar);
		Assert::type(Event::class, $fooOnBarEvent);

		$fooAccessor = $container->getService('fooAccessor');
		$foo2 = $fooAccessor->get();
		Assert::same($foo, $foo2);

		$fooFactory = $container->getService('fooFactory');
		$foo3 = $fooFactory->create();
		$foo3OnBarEvent = $this->getEventFromListenersProperty($foo3->onBar);
		Assert::type(Event::class, $foo3OnBarEvent);
		Assert::notSame($foo, $foo3);
	}

	public function testGlobalDispatchFirst()
	{
		$container = $this->createContainer('globalDispatchFirst');
		$container->getService('events.manager');

		$mock = $container->getService('dispatchOrderMock');
		Assert::true($this->getEventFromListenersProperty($mock->onGlobalDispatchFirst)->globalDispatchFirst);
		Assert::false($this->getEventFromListenersProperty($mock->onGlobalDispatchLast)->globalDispatchFirst);
		Assert::true($this->getEventFromListenersProperty($mock->onGlobalDispatchDefault)->globalDispatchFirst);
	}

	public function testGlobalDispatchLast()
	{
		$container = $this->createContainer('globalDispatchLast');
		$container->getService('events.manager');

		$mock = $container->getService('dispatchOrderMock');
		Assert::true($this->getEventFromListenersProperty($mock->onGlobalDispatchFirst)->globalDispatchFirst);
		Assert::false($this->getEventFromListenersProperty($mock->onGlobalDispatchLast)->globalDispatchFirst);
		Assert::false($this->getEventFromListenersProperty($mock->onGlobalDispatchDefault)->globalDispatchFirst);
	}

	protected function getEventFromListenersProperty($listeners): ?Event {
		if (is_array($listeners)) {
			foreach ($listeners as $listener) {
				if ($listener instanceof Event) {
					return $listener;
				}
			}
		}

		return null;
	}
}

(new ExtensionTest())->run();
