<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Caching\Storages;

use Nette;
use Nette\Caching\Cache;


/**
 * Memcached storage using memcached extension.
 */
class MemcachedStorage implements Nette\Caching\Storage, Nette\Caching\BulkReader
{
	use Nette\SmartObject;

	/** @internal cache structure */
	private const
		META_CALLBACKS = 'callbacks',
		META_DATA = 'data',
		META_DELTA = 'delta';

	private \Memcached $memcached;

	private string $prefix;

	private ?Journal $journal;


	/**
	 * Checks if Memcached extension is available.
	 */
	public static function isAvailable(): bool
	{
		return extension_loaded('memcached');
	}


	public function __construct(
		string $host = 'localhost',
		int $port = 11211,
		string $prefix = '',
		?Journal $journal = null,
	) {
		if (!static::isAvailable()) {
			throw new Nette\NotSupportedException("PHP extension 'memcached' is not loaded.");
		}

		$this->prefix = $prefix;
		$this->journal = $journal;
		$this->memcached = new \Memcached;
		if ($host) {
			$this->addServer($host, $port);
		}
	}


	public function addServer(string $host = 'localhost', int $port = 11211): void
	{
		if (@$this->memcached->addServer($host, $port, 1) === false) { // @ is escalated to exception
			$error = error_get_last();
			throw new Nette\InvalidStateException("Memcached::addServer(): $error[message].");
		}
	}


	public function getConnection(): \Memcached
	{
		return $this->memcached;
	}


	public function read(string $key): mixed
	{
		$key = urlencode($this->prefix . $key);
		$meta = $this->memcached->get($key);
		if (!$meta) {
			return null;
		}

		// meta structure:
		// array(
		//     data => stored data
		//     delta => relative (sliding) expiration
		//     callbacks => array of callbacks (function, args)
		// )

		// verify dependencies
		if (!empty($meta[self::META_CALLBACKS]) && !Cache::checkCallbacks($meta[self::META_CALLBACKS])) {
			$this->memcached->delete($key, 0);
			return null;
		}

		if (!empty($meta[self::META_DELTA])) {
			$this->memcached->replace($key, $meta, $meta[self::META_DELTA] + time());
		}

		return $meta[self::META_DATA];
	}


	public function bulkRead(array $keys): array
	{
		$prefixedKeys = array_map(fn($key) => urlencode($this->prefix . $key), $keys);
		$keys = array_combine($prefixedKeys, $keys);
		$metas = $this->memcached->getMulti($prefixedKeys);
		$result = [];
		$deleteKeys = [];
		foreach ($metas as $prefixedKey => $meta) {
			if (!empty($meta[self::META_CALLBACKS]) && !Cache::checkCallbacks($meta[self::META_CALLBACKS])) {
				$deleteKeys[] = $prefixedKey;
			} else {
				$result[$keys[$prefixedKey]] = $meta[self::META_DATA];
			}

			if (!empty($meta[self::META_DELTA])) {
				$this->memcached->replace($prefixedKey, $meta, $meta[self::META_DELTA] + time());
			}
		}

		if (!empty($deleteKeys)) {
			$this->memcached->deleteMulti($deleteKeys, 0);
		}

		return $result;
	}


	public function lock(string $key): void
	{
	}


	public function write(string $key, $data, array $dp): void
	{
		if (isset($dp[Cache::ITEMS])) {
			throw new Nette\NotSupportedException('Dependent items are not supported by MemcachedStorage.');
		}

		$key = urlencode($this->prefix . $key);
		$meta = [
			self::META_DATA => $data,
		];

		$expire = 0;
		if (isset($dp[Cache::EXPIRATION])) {
			$expire = (int) $dp[Cache::EXPIRATION];
			if (!empty($dp[Cache::SLIDING])) {
				$meta[self::META_DELTA] = $expire; // sliding time
			}
		}

		if (isset($dp[Cache::CALLBACKS])) {
			$meta[self::META_CALLBACKS] = $dp[Cache::CALLBACKS];
		}

		if (isset($dp[Cache::TAGS]) || isset($dp[Cache::PRIORITY])) {
			if (!$this->journal) {
				throw new Nette\InvalidStateException('CacheJournal has not been provided.');
			}

			$this->journal->write($key, $dp);
		}

		$this->memcached->set($key, $meta, $expire);
	}


	public function remove(string $key): void
	{
		$this->memcached->delete(urlencode($this->prefix . $key), 0);
	}


	public function clean(array $conditions): void
	{
		if (!empty($conditions[Cache::ALL])) {
			$this->memcached->flush();

		} elseif ($this->journal) {
			foreach ($this->journal->clean($conditions) as $entry) {
				$this->memcached->delete($entry, 0);
			}
		}
	}
}
