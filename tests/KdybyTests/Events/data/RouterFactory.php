<?php

namespace KdybyTests\Events;

class RouterFactory
{

	public function createRouter(): SampleRouter
	{
		return new SampleRouter('nemam');
	}

}
