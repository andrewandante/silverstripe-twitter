<?php

namespace SilverStripe\Twitter\Services;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;

/**
 * Caches a wrapped twitter service
 *
 * @author Damian Mooyman
 *
 * @package twitter
 */
class CachedTwitterService implements ITwitterService {

	/**
	 * @var ITwitterService
	 */
	protected $cachedService = null;

	function __construct(ITwitterService $service) {
		$this->cachedService = $service;
	}

	function getTweets($user, $count) {
		// Init caching
		$cacheKey = "getTweets_{$user}_{$count}";
		$cache = Injector::inst()->get(CacheInterface::class . '.CachedTwitterService');

		// Return cached value, if available
		if($rawResult = $cache->get($cacheKey)) return $rawResult;

		// Save and return
		$result = $this->cachedService->getTweets($user, $count);
		$cache->set($cacheKey, $result, Config::inst()->get('CachedTwitterService', 'lifetime'));

		// Refresh the 'TimeAgo' field, as the cached value would now be outdated, or the locale could have changed.
		if($result) foreach($result as $index => $item) {
			$result[$index]['TimeAgo'] = TwitterService::determine_time_ago($item['Date']);
		}

		return $result;
	}

	/**
	 * get favourite tweets associated with the user.
	 * */
	function getFavorites($user, $count) {

		// Init caching
		$cacheKey = "getFavorites_{$user}_{$count}";
		$cache = Injector::inst()->get(CacheInterface::class . '.CachedTwitterService');

		// Return cached value, if available
		if($rawResult = $cache->get($cacheKey)) {
			return $rawResult;
		}

		// Save and return
		$result = $this->cachedService->getFavorites($user, $count);
		$cache->set($cacheKey, $result, Config::inst()->get('CachedTwitterService', 'lifetime'));

		// Refresh the 'TimeAgo' field, as the cached value would now be outdated, or the locale could have changed.
		if($result) foreach($result as $index => $item) {
			$result[$index]['TimeAgo'] = TwitterService::determine_time_ago($item['Date']);
		}

		return $result;
	}

	function searchTweets($query, $count) {
		// Init caching

		$cacheKey = "searchTweets_".str_replace("-", "_", Convert::raw2url($query))."_{$count}";
		$cache = Injector::inst()->get(CacheInterface::class . '.CachedTwitterService');

		// Return cached value, if available
 		if($rawResult = $cache->get($cacheKey)) return $rawResult;

		// Save and return
		$result = $this->cachedService->searchTweets($query, $count);
		$cache->set($cacheKey, $result, Config::inst()->get('CachedTwitterService', 'lifetime'));

		// Refresh the 'TimeAgo' field, as the cached value would now be outdated, or the locale could have changed.
		if($result) foreach($result as $index => $item) {
			$result[$index]['TimeAgo'] = TwitterService::determine_time_ago($item['Date']);
		}

		return $result;
	}
}
