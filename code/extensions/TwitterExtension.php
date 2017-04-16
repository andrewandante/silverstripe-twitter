<?php

namespace SilverStripe\Twitter\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Twitter\Services\ITwitterService;
use SilverStripe\Twitter\Services\TwitterService;
use SilverStripe\View\ArrayData;

/**
 * Provides twitter api access for page controllers
 *
 * @author Damian Mooyman
 *
 * @package twitter
 */
class TwitterExtension extends Extension {

	/**
	 * @var ITwitterService
	 */
	protected $twitterService = null;

	/**
	 * Set the service to use for accessing twitter
	 * @param ITwitterService $twitterService
	 */
	public function setTwitterService(ITwitterService $twitterService) {
		$this->twitterService = $twitterService;
	}

	/**
	 * Retrieves the latest tweet
	 *
	 * @return ArrayData
	 */
	public function LatestTweet() {
		$latestTweets = $this->LatestTweets();
		return $latestTweets ? $latestTweets->first() : null;
	}

	/**
	 * Retrieves (up to) the last $count tweets.
	 *
	 * Note: Actual returned number may be less than 10 due to reasons
	 *
	 * @param integer $count
	 * @return ArrayList
	 */
	public function LatestTweets($count = 10) {
		$user = SiteConfig::current_site_config()->TwitterUsername;
		return $this->LatestTweetsUser($user, $count);
	}


	/**
	 * Retrieves (up to) the last $count favourite tweets.
	 *
	 * Note: Actual returned number may be less than 10 due to reasons
	 *
	 * @param integer $count
	 * @return ArrayList
	 */
	public function Favorites($count = 4) {
		$user = SiteConfig::current_site_config()->TwitterUsername;

		return new ArrayList($this->twitterService->getFavorites($user, $count));
	}

	/**
	 * Converts an array of tweets into a template-compatible format
	 *
	 * @param array $tweets
	 * @return ArrayList
	 */
	protected function viewableTweets($tweets) {
		$items = new ArrayList();
		foreach ($tweets as $tweet) {
			$tweet['DateObject'] = DBField::create_field('SilverStripe\ORM\FieldType\DBDateTime', $tweet['Date']);
			$items->push(new ArrayData($tweet));
		}
		return $items;
	}

	/**
	 * Retrieves (up to) the last $count tweets from $user.
	 *
	 * Note: Actual returned number may be less than 10 due to reasons
	 *
	 * @param string $user Username to search for
	 * @param integer $count Number of tweets
	 * @return ArrayList List of tweets
	 */
	public function LatestTweetsUser($user, $count = 10) {

		// Check that the twitter user is configured
		if (empty($user)) return null;

		$tweets = $this->twitterService->getTweets($user, $count);
		return $this->viewableTweets($tweets);
	}

	/**
	 * Retrieves (up to) the last $count tweets searched by the $query
	 *
	 * Note: Actual returned number may be less than 10 due to reasons
	 *
	 * @param string $query Search terms
	 * @param integer $count Number of tweets
	 * @return ArrayList List of tweets
	 */
	public function SearchTweets($query, $count = 10) {

		if (empty($query)) return null;

		$tweets = $this->twitterService->searchTweets($query, $count);
		return $this->viewableTweets($tweets);
	}
}
