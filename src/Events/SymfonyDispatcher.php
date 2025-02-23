<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Events;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SymfonyDispatcher implements \Symfony\Component\EventDispatcher\EventDispatcherInterface
{

	/**
	 * @var \Kdyby\Events\EventManager
	 */
	private $evm;

	public function __construct(EventManager $eventManager)
	{
		$this->evm = $eventManager;
	}

	public function dispatch(object $event, ?string $eventName = null): object
	{
		if ($eventName === null) {
			$eventName = ($event instanceof Event) ? $event->getName() : get_class($event);
		}
		$this->evm->dispatchEvent($eventName, new EventArgsList([$event]));

		return $event;
	}

	public function addListener($eventName, $listener, $priority = 0)
	{
		throw new \Kdyby\Events\NotSupportedException();
	}

	public function addSubscriber(EventSubscriberInterface $subscriber)
	{
		throw new \Kdyby\Events\NotSupportedException();
	}

	public function removeListener($eventName, $listener)
	{
		throw new \Kdyby\Events\NotSupportedException();
	}

	public function removeSubscriber(EventSubscriberInterface $subscriber)
	{
		throw new \Kdyby\Events\NotSupportedException();
	}

	public function getListenerPriority($eventName, $listener)
	{
		throw new \Kdyby\Events\NotSupportedException();
	}

	public function getListeners($eventName = NULL)
	{
		return $this->evm->getListeners($eventName);
	}

	public function hasListeners($eventName = NULL)
	{
		return $this->evm->hasListeners($eventName);
	}

}
