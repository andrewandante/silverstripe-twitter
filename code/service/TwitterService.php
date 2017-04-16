<?php

namespace SilverStripe\Twitter\Services;
// Require third party lib
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Cache\CacheFactory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;
use TwitterOAuth;

require_once __DIR__ . "/../../thirdparty/twitteroauth/twitteroauth/twitteroauth.php";

/**
 * JSON powered twitter service
 *
 * @link http://www.webdevdoor.com/javascript-ajax/custom-twitter-feed-integration-jquery/
 * @link http://www.webdevdoor.com/php/authenticating-twitter-feed-timeline-oauth/
 *
 * @author Damian Mooyman
 *
 * @package twitter
 */
class TwitterService implements ITwitterService {

	/**
	 * @config use https for inserted media (prevents mixed content warnings on SSL websites)
	 */
	private static $use_https = false;

	/**
	 * Generate a new TwitterOAuth connection
	 *
	 * @return TwitterOAuth
	 */
	protected function getConnection() {
		$member = Member::currentUser();
		$siteConfig = SiteConfig::current_site_config();

		$consumerKey = $member->TwitterAppConsumerKey ?: $siteConfig->TwitterAppConsumerKey;
		$consumerSecret = $member->TwitterAppConsumerSecret ?: $siteConfig->TwitterAppConsumerSecret;
		$accessToken = $member->TwitterAppAccessToken ?: $siteConfig->TwitterAppAccessToken;
		$accessSecret = $member->TwitterAppAccessSecret ?: $siteConfig->TwitterAppAccessSecret;

		return new TwitterOAuth($consumerKey, $consumerSecret, $accessToken, $accessSecret);
	}

	public function getConfiguration() {
		$cache = Injector::inst()->get(CacheInterface::class . '.TwitterConfig');
		if ($cache->has('configuration')) {
			return $cache->get('configuration');
		}
		$connection = $this->getConnection();
		$response = json_decode($connection->get("https://api.twitter.com/1.1/help/configuration.json"));
		if ($response && is_array($response)) {
			$cache->set('configuration', $response);
		}
		return $response;
	}

	function getTweets($user, $count) {

		// Check user
		if (empty($user)) return null;

		$member = Member::currentUser();
		$siteConfig = SiteConfig::current_site_config();

		$includeRTs = $member->TwitterIncludeRTs ?: $siteConfig->TwitterIncludeRTs;
		$excludeReplies = $member->TwitterExcludeReplies ?: $siteConfig->TwitterExcludeReplies;

		// Call rest api
		$arguments = http_build_query(array(
			'screen_name' => $user,
			'count' => $count,
			'include_rts' => $includeRTs,
			'exclude_replies' => $excludeReplies
		));
		$connection = $this->getConnection();
		$response = $connection->get("https://api.twitter.com/1.1/statuses/user_timeline.json?$arguments");

		// Parse all tweets
		$tweets = array();
		if ($response && is_array($response)) {
			foreach ($response as $tweet) {
				$tweets[] = $this->parseTweet($tweet);
			}
		}

		return $tweets;
	}

	function updateStatus($status, $media = null) {
		$config = $this->getConfiguration();
		$linkLength = self::$use_https ? $config->short_url_length_https : $config->short_url_length;
		if (strlen($status) > (140 - $linkLength)) {
			throw new \InvalidArgumentException('Status contains too many characters');
		}

		$arguments = http_build_query([
			'status' => $status
		]);
		$connection = $this->getConnection();
		return $connection->post("https://api.twitter.com/1.1/statuses/update.json?$arguments");
	}

	/**
	 * get favourite tweets associated with the user.
	 */
	function getFavorites($user, $count) {

		// Check user
		if (empty($user)) return null;

		// Call rest api
		$arguments = http_build_query(array(
			'screen_name' => $user,
			'count' => $count
		));
		$connection = $this->getConnection();
		$response = $connection->get("https://api.twitter.com/1.1/favorites/list.json?$arguments");

		// Parse all tweets
		$tweets = array();
		if ($response && is_array($response)) {

			foreach ($response as $tweet) {
				$tweets[] = $this->parseTweet($tweet);
			}
		}

		return $tweets;
	}

	function searchTweets($query, $count) {

		$tweets = array();
		if (!empty($query)) {
			// Call rest api
			$arguments = http_build_query(array(
				'q' => (string)$query,
				'count' => $count,
				'include_rts' => true
			));
			$connection = $this->getConnection();
			$response = $connection->get("https://api.twitter.com/1.1/search/tweets.json?$arguments");

			// Parse all tweets
			if ($response) {
			 	foreach ($response->statuses as $tweet) {
					$tweets[] = $this->parseTweet($tweet);
				}
			}
		}

		return $tweets;
	}

	/**
	 * Calculate the time ago in days, hours, whichever is the most significant
	 *
	 * @param string $time Input time as a string
	 * @param integer $detail Number of time periods to display. Increasing provides greater time detail.
	 * @return string
	 */
	public static function determine_time_ago($time, $detail = 1) {
		$difference = time() - strtotime($time);

		if ($difference < 1) {
			return _t('Date.LessThanMinuteAgo', 'less than a minute');
		}

		$periods = array(
			365 * 24 * 60 * 60 => 'year',
			30 * 24 * 60 * 60 => 'month',
			24 * 60 * 60 => 'day',
			60 * 60 => 'hour',
			60 => 'min',
			1 => 'sec'
		);

		$items = array();

		foreach ($periods as $seconds => $description) {
			// Break if reached the sufficient level of detail
			if(count($items) >= $detail) break;

			// If this is the last element in the chain, round the value.
			// Otherwise, take the floor of the time difference
			$quantity = $difference / $seconds;
			if(count($items) === $detail - 1) {
				$quantity = round($quantity);
			} else  {
				$quantity = intval($quantity);
			}

			// Check that the current period is smaller than the current time difference
			if($quantity <= 0) continue;

			// Append period to total items and continue calculation with remainder
			if($quantity !== 1) $description .= 's';
			$items[] = $quantity.' '. _t("Date.".strtoupper($description), $description);
			$difference -= $quantity * $seconds;
		}
		$time = implode(' ', $items);
		return _t(
			'Date.TIMEDIFFAGO',
			'{difference} ago',
			'Time since tweet',
			array('difference' => $time)
		);
	}

	/**
	 * Converts a tweet response into a simple associative array of fields
	 *
	 * @param stdObject $tweet Tweet object
	 * @return array Array of fields with Date, User, and Content as keys
	 */
	public function parseTweet($tweet) {

		$profileLink = "https://twitter.com/" . Convert::raw2url($tweet->user->screen_name);
		$tweetID = $tweet->id_str;
		$https = ( Config::inst()->get(get_class(),"use_https") ? "_https" : "" );

		//
		// Date format.
		//
		$tweetDate = \DateTime::createFromFormat('D M j H:i:s O Y', $tweet->created_at);
		$d = DBDatetime::create()->setValue($tweetDate->getTimestamp());

		return array(
			'ID' => $tweetID,
			'Date' => $d,
			'TimeAgo' => self::determine_time_ago($tweetDate->getTimestamp()),
			'Name' => $tweet->user->name,
			'User' => $tweet->user->screen_name,
			'AvatarUrl' => $tweet->user->{"profile_image_url$https"},
			'Content' => $this->parseText($tweet),
			'Link' => "{$profileLink}/status/{$tweetID}",
			'ProfileLink' => $profileLink,
			'ReplyLink' => "https://twitter.com/intent/tweet?in_reply_to={$tweetID}",
			'RetweetLink' => "https://twitter.com/intent/retweet?tweet_id={$tweetID}",
			'FavouriteLink' => "https://twitter.com/intent/favorite?tweet_id={$tweetID}"
		);
	}

	/**
	 * Inject a hyperlink into the body of a tweet
	 *
	 * @param array $tokens List of characters/words that make up the tweet body,
	 * with each index representing the visible character position of the body text
	 * (excluding markup).
	 * @param stdObject $entity The link object
	 * @param string $link 'href' tag for the link
	 * @param string $title 'title' tag for the link
	 */
	protected function injectLink(&$tokens, $entity, $link, $title) {
		$startPos = $entity->indices[0];
		$endPos = $entity->indices[1];

		// Inject <a tag at the start
		$tokens[$startPos] = sprintf(
			"<a href='%s' title='%s' target='_blank'>%s</a>",
			Convert::raw2att($link),
			Convert::raw2att($title),
			Convert::raw2att($title)
		);
		$characters = $endPos - $startPos - 1;
		array_splice($tokens, $startPos + 1, $characters, array_fill($startPos + 1, $characters, ''));
	}

	/**
	 * Inject photo media into the body of a tweet
	 *
	 * @param array $tokens List of characters/words that make up the tweet body,
	 * with each index representing the visible character position of the body text
	 * (excluding markup).
	 * @param stdObject $entity The photo media object
	 */
	protected function injectPhoto(&$tokens, $entity) {
		$startPos = $entity->indices[0];
		$endPos = $entity->indices[1];
		$https = ( Config::inst()->get(get_class(),"use_https") ? "_https" : "" );

		// Inject a+image tag at the last token position
		$tokens[$endPos] = sprintf(
			"<a href='%s' title='%s'><img src='%s' width='%s' height='%s' target='_blank' /></a>",
			Convert::raw2att($entity->url),
			Convert::raw2att($entity->display_url),
			Convert::raw2att($entity->{"media_url$https"}),
			Convert::raw2att($entity->sizes->small->w),
			Convert::raw2att($entity->sizes->small->h)
		);

		// now empty-out the preceding tokens
		for($i = $startPos; $i < $endPos; $i++){ $tokens[$i] = ''; }
	}


	/**
	 * Parse the tweet object into a HTML block
	 *
	 * @param stdObject $tweet Tweet object
	 * @return string HTML text
	 */
	protected function parseText($tweet) {
		$rawText = $tweet->text;

		// tokenise into words for parsing (multibyte safe)
		$tokens = preg_split('/(?<!^)(?!$)/u', $rawText);

		// Inject links
		foreach ($tweet->entities->urls as $url) {
			$this->injectLink($tokens, $url, $url->url, $url->display_url);
		}

		// Inject hashtags
		foreach ($tweet->entities->hashtags as $hashtag) {
			$link = 'https://twitter.com/search?src=hash&q=' . Convert::raw2url('#' . $hashtag->text);
			$text = "#" . $hashtag->text;

			$this->injectLink($tokens, $hashtag, $link, $text);
		}

		// Inject mentions
		foreach ($tweet->entities->user_mentions as $mention) {
			$link = 'https://twitter.com/' . Convert::raw2url($mention->screen_name);
			$this->injectLink($tokens, $mention, $link, '@'.$mention->name);
		}

		// Inject photos
		// unlike urls & hashtags &tc, media is not always defined
		if(property_exists($tweet->entities, 'media')){
			foreach ($tweet->entities->media as $med_item) {
				if($med_item->type == 'photo'){
					$this->injectPhoto($tokens, $med_item);
				}
			}
		}

		// Re-combine tokens
		return implode('', $tokens);
	}

}
