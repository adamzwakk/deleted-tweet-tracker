<?php

require_once('vendor/autoload.php');

//Learned the hard way that Twitter uses UTC time, so record everything that way
date_default_timezone_set('UTC');
error_reporting(E_WARNING);

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load(true);

class DeletedTweets {

	protected $database;
	protected $pb;
	protected $twitter;

	protected $target;
	protected $verbose;
	protected $days;
	protected $tooOldDays;
	protected $initrows;
	protected $oldCount;
	protected $replyCount;
	protected $newCount;
	protected $include_replies;

	public function __construct($options = [])
	{
		$this->pb = new Pushbullet\Pushbullet(getenv('pbkey'));

		Eden\Core\Control::i();

		$this->database = eden('sqlite', getenv('dbpath'));
		$this->target = getenv('targetscreen');
		$this->verbose = isset(getopt("v::")['v']);

		$this->oldCount = 0;
		$this->replyCount = 0;
		$this->newCount = 0;

		$day1 = 86400;
		$this->days = getenv('day_cutoff',5);
		$this->tooOldDays = time()-($day1*$this->days);

		$this->statuses = [];
		$this->include_replies = filter_var(getenv('include_replies'), FILTER_VALIDATE_BOOLEAN);
		$this->initrows = $this->getAllFromDB();
	}

	public function getTweets()
	{
		$this->twitter = new Twitter(getenv('twCkey'), 
			getenv('twCsec'), 
			getenv('twAtok'), 
			getenv('twAsec'));

		if($this->verbose){
			echo 'Getting recent tweets from '.$this->target."...\n";
		}

		$options = [
			'screen_name' => $this->target,
			'count' => 300,
			'tweet_mode' => 'extended',
			'include_rts'=>false
		];

		if(!$this->include_replies)
		{
			$options['exclude_replies'] = true;
		}

		try
		{
			$this->statuses = $this->twitter->request('statuses/user_timeline', 'GET', $options);
		} 
		catch(TwitterException $e)
		{
			die('Twitter Error: '.$e->getMessage());
		}

		if(count($this->statuses))
		{
			if($this->verbose){
				echo 'Got Tweets for '.$this->target.", parsing...\n";
			}
		}
		else
		{
			die('Couldnt find tweets for '.$this->target);
		}
	}

	public function getAllFromDB()
	{
		return $this->database->query('SELECT * FROM tweets_arc');
	}

	public function tweetExists($id)
	{
		foreach($this->initrows as $t)
		{
			// if you exist and you arent deleted yet...
			if($t['tweet_id'] == $id && is_null($t['deleted']))
			{
				return $t;
			}
		}

		return false;
	}

	public function tweetSimilar($id,$text)
	{
		foreach($this->initrows as $t)
		{
			if($id == $t['tweet_id'] || intval($t['obsolete'])){
				continue;
			}
			//probably a previous tweet was a typo
			similar_text(strtolower($t['tweet_body']),strtolower($text),$percent);
			if(intval($percent) > 75 && !is_null($t['deleted']))
			{
				if($this->verbose)
				{
					echo "Neat! A tweet (".$t['tweet_id'].") that was deleted cause of a typo, better make a note of that...\n";
				}
				$this->database->updateRows('tweets_arc', ['tweet_body'=>"{typo:$id} ".$t['tweet_body'],'obsolete'=>1], ['tweet_id=%s', $t['tweet_id']]);
				return true;
			}
		}

		return false;
	}

	public function recordNewTweets()
	{
		foreach($this->statuses as $s){
			$id = intval($s->id);
			$body = $s->full_text;
			$created = strtotime($s->created_at);

			if($this->verbose)
			{
				//echo "[Tweet $id] Taking a look...\n";
			}

			if($this->target !== strtolower($s->user->screen_name))
			{
				//This happened somehow so an extra check is made here
				if($this->verbose){
					echo "[Tweet $id] Skipping since wrong target somehow \n";
				}
				continue;
			}

			if($created <= $this->tooOldDays){
				if($this->verbose){
					//echo "[Tweet $id] skipping since it's too old \n";
				}
				$this->oldCount++;
				continue;
			}

			if(!$this->include_replies && is_int($s->in_reply_to_status_id) && $s->in_reply_to_user_id !== $s->user->id){
				if($this->verbose){
					echo "[Tweet $id] since it\'s a tweet reply to ".explode(' ',$body)[0]."\n";
				}
				$this->replyCount++;
				continue;
			}

			// check if this is a typo update and update the deleted tweet accordingly
			$this->tweetSimilar($id,$body);

			$q = $this->tweetExists($id);
			if($q !== FALSE)
			{
				if(intval($q['obsolete']) == 1){
		            continue;
			    }

			    if($q['tweet_body'] != $body){
			    	if($this->verbose){
			    		echo "[Tweet $id] Updated body message for $id\n";
			    	}
			    	$this->database->updateRows('tweets_arc', ['tweet_body'=>$body], ['tweet_id=%s', $id]);
			    }

				$this->database->updateRows('tweets_arc', ['updated_on'=>time()], ['tweet_id=%s', $id]);
				continue;
			}

			$this->database->insertRow('tweets_arc', [
				'tweet_id' => $id,
				'tweet_body' => $body,
				'date' => $created,
				'updated_on'=>time()
			]);

			if($this->verbose){
				echo "[Tweet $id] Added new tweet!\n";
			}

			$this->newCount++;
		}

		if(!$this->newCount)
		{
			echo "\n[ No new tweets to to add! ]";
		}
	}

	public function markObsolete()
	{
		$this->database->updateRows('tweets_arc', ['obsolete'=>1], ['date<=%s AND obsolete = 0', ($this->tooOldDays+86400)]);
	}

	public function checkDeleted()
	{
		// If tweet hasn't been seen for 3 minutes (and is within the X day limit), consider it deleted...
		$q = $this->database->query('SELECT * FROM tweets_arc WHERE DATETIME(updated_on,\'unixepoch\') < :id AND obsolete = 0 AND deleted IS NULL',array(':id' => date('Y-m-d H:i:s',time()-180)));
		foreach($q as $t){
			$this->database->updateRows('tweets_arc', ['deleted'=>1,'deleted_at'=>time()], ['tweet_id=%s', intval($t['tweet_id'])]);
			$this->pb->allDevices()->pushNote("I've found a deleted tweet for ".$this->target."!", "Tweeted [".date('Y-m-d H:i:s',$t['date'])."]: ".$t['tweet_body']);
		}
	}

	public function printDeleted()
	{
		$q = $this->database->query('SELECT * FROM tweets_arc WHERE deleted = 1 ORDER BY tweet_id ASC');
		if(count($q)){
			foreach($q as $t)
			{
				$flagCheck = preg_split("/{typo:(.*?)}/", $t['tweet_body']);

				echo "\n================\n";
				if(count($flagCheck) == 1)
				{
					$this->renderTweet($t);
				}
				else if(count($flagCheck) == 2)
				{
					// This is horrendous but oh well it prints pretty
					preg_match("/{typo:(.*?)}/", $t['tweet_body'], $typo);
					$t['tweet_body'] = str_replace(array("\r", "\n"), ' ', $flagCheck[1]);
					$this->renderTweet($t,'[Typo\'d/deleted tweet] ');

					if(count($typo))
					{
						$refq = $this->database->query('SELECT * FROM tweets_arc WHERE tweet_id = '.intval($typo[1]));
						if($refq)
						{
							echo "\n^----v\n";
							$this->renderTweet($refq[0],'[Newer Tweet:'.$refq[0]['tweet_id'].'] ');
						}
						else
						{
							echo "\n";
						}
					}
				}
				echo "\n================\n\n";
			}
		} else {
			echo "Nothing yettttt!\n";
		}
	}

	public function printStats()
	{
		//These queries are horrible but I'm lazy and are ran manually for fun
		$ob = count($this->database->query('SELECT * FROM tweets_arc WHERE obsolete = 1'));
		$total = count($this->database->query('SELECT * FROM tweets_arc'));
		$totalW = count($this->database->query('SELECT * FROM tweets_arc WHERE obsolete = 0 AND deleted IS NULL'));
		$totalD = count($this->database->query('SELECT * FROM tweets_arc WHERE deleted = 1 AND tweet_body NOT LIKE "{typo:%"'));
		$totalDT = count($this->database->query('SELECT * FROM tweets_arc WHERE deleted = 1 AND tweet_body LIKE "{typo:%"'));
		$avgD = $this->database->query('SELECT (CAST(AVG(updated_on - date) as integer)/60)  as average  FROM tweets_arc WHERE deleted = 1 AND tweet_body NOT LIKE "{typo:%"')[0]['average'];
		$avgDT = $this->database->query('SELECT (CAST(AVG(updated_on - date) as integer)/60)  as average  FROM tweets_arc WHERE deleted = 1 AND tweet_body LIKE "{typo:%"')[0]['average'];

		$mostTweets = $this->database->query("SELECT strftime('%Y-%m-%d', date, 'unixepoch') d, count(*) num FROM tweets_arc GROUP BY d ORDER BY num DESC LIMIT 1")[0];
		$mostTweetsD = $this->database->query("SELECT strftime('%Y-%m-%d', updated_on, 'unixepoch') d, count(*) num FROM tweets_arc WHERE deleted = 1 GROUP BY d ORDER BY num DESC LIMIT 1")[0];

		echo "\n\n=======STATUS FOR ".strtoupper($this->target)."=======\n";
		if($this->oldCount){
			echo "Old Tweets Skipped (over $this->days days old still in Twitter API response): $this->oldCount\n";
		}
		if($this->include_replies && $this->replyCount){
			echo "Replies Skipped: $this->replyCount\n";
		}
		if($this->newCount){
			echo "New Tweets: $this->newCount\n\n";
		}

		echo "Still watching $totalW tweets for deletion\n";
		echo "Obsoleted Tweets (stored, but over $this->days days old): $ob\n\n";
		
		echo "Deleted Tweets (for no reason?): $totalD\n";
		echo "Deleted Tweets (cause of typo/grammar): $totalDT\n";
		echo "Deleted Tweets Total: ".($totalD + $totalDT)."\n\n";
		
		echo "Average time between create/deletion: $avgD minutes\n";
		echo "Average time between create/deletion cause typo: $avgDT minutes\n\n";
		echo "That's ".round((($total-($totalD + $totalDT))/$total)*100,2)."% deleted!\n\n";

		echo "Most active day: ".$mostTweets['d']." (".$mostTweets['num']." Tweets)\n";
		echo "Most deletes in a day: ".$mostTweetsD['d']." (".$mostTweetsD['num']." deletes)\n\n";

		echo "Total Tweets stored: $total\n";
		echo "================".str_repeat('=', strlen($this->target))."========\n\n";
	}

	public function cleanup()
	{
		$q = $this->database->query('SELECT * FROM tweets_arc WHERE tweet_body LIKE \'@%\'');
		foreach($q as $t){
			if($this->include_replies === FALSE && strpos($t['tweet_body'],'@') === 0)
			{
				echo 'Cleaning out a reply ('.$t['tweet_id'].") that we\'re not suposed to have\n";
				$this->database->deleteRows('tweets_arc', ['tweet_id=%s', intval($t['tweet_id'])]);
				continue;
			}
		}
	}

	public function isVerbose()
	{
		return $this->verbose;
	}

	private function renderTweet($t, $prefix = '')
	{
		//cleanup
		$t['tweet_body'] = str_replace(array("\r", "\n"), ' ', $t['tweet_body']);

		echo $prefix.'['.$t['tweet_id'].'] [Tweeted: '.date('Y-m-d H:i:s',$t['date'])."] -- [Last seen: ".date('Y-m-d H:i:s',$t['updated_on'])."]\n";	
		echo trim($t['tweet_body']);
	}
}
