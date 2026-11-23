<?php

/*
 * This file is part of Snooze <https://github.com/pmmp/Snooze>
 * Copyright (c) 2018-2023 PMMP Team
 *
 * Snooze is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\snooze;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * Used to wake up the sleeping thread from another thread.
 * Use {@link SleeperHandlerEntry::createNotifier()} inside the thread to create this.
 */
class SleeperNotifier extends ThreadSafe{

	private ?ThreadSafeArray $sharedObject = null;
	private ?int $notifierId = null;

	/**
	 * @internal
	 * Use {@link SleeperHandlerEntry::createNotifier()} for the current API.
	 *
	 * @phpstan-param ThreadSafeArray<int, int> $sharedObject
	 */
	public function __construct(
		?ThreadSafeArray $sharedObject = null,
		?int $notifierId = null
	){
		$this->sharedObject = $sharedObject;
		$this->notifierId = $notifierId;
	}

	final public function attachSleeper(ThreadSafeArray $sharedObject, int $id) : void{
		$this->sharedObject = $sharedObject;
		$this->notifierId = $id;
	}

	final public function getSleeperId() : int{
		return $this->notifierId ?? throw new \LogicException("Notifier is not attached");
	}

	/**
	 * Call this method to wake up the sleeping thread.
	 */
	final public function wakeupSleeper() : void{
		$shared = $this->sharedObject;
		$sleeperId = $this->getSleeperId();
		$shared->synchronized(function() use ($shared, $sleeperId) : void{
			if(!isset($shared[$sleeperId])){
				$shared[$sleeperId] = $sleeperId;
				$shared->notify();
			}
		});
	}
}
