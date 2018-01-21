<?php

require_once('vendor/autoload.php');
date_default_timezone_set('UTC');

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

	public function __construct($options = [],$verbose = false)
	{
		$this->pb = new Pushbullet\Pushbullet(getenv('pbkey'));

		Eden\Core\Control::i();

		$this->database = eden('sqlite', getenv('dbpath'));
		$this->target = getenv('targetscreen');
		$this->verbose = $verbose;

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

		$this->statuses = $this->twitter->request('statuses/user_timeline', 'GET', $options);


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

	public function recordNewTweets()
	{
		foreach($this->statuses as $s){
			$id = intval($s->id);
			$body = $s->full_text;
			$created = strtotime($s->created_at);

			if($this->target !== strtolower($s->user->screen_name))
			{
				//This happened somehow so an extra check is made here
				if($this->verbose){
					echo 'Skipping '.$id." since wrong target somehow \n";
				}
				continue;
			}

			if($created <= $this->tooOldDays){
				if($this->verbose){
					echo 'Skipping '.$id." since it's too old \n";
				}
				$this->oldCount++;
				continue;
			}

			if(!$this->include_replies && is_int($s->in_reply_to_status_id) && $s->in_reply_to_user_id !== $s->user->id){
				if($this->verbose){
					echo 'Skipping '.$id.' since it\'s a tweet reply to '.explode(' ',$body)[0]."\n";
				}
				$this->replyCount++;
				continue;
			}

			$q = $this->tweetExists($id);
			if($q !== FALSE)
			{
				if(intval($q['obsolete']) == 1){
		            continue;
			    }

			    if($q['tweet_body'] != $body){
			    	echo 'Updated body message for '.$id."\n";
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
				echo "Added new tweet!\n";
			}

			$this->newCount++;
		}
	}

	public function markObsolete()
	{
		$this->database->updateRows('tweets_arc', ['obsolete'=>1], ['date<=%s', $this->tooOldDays]);
	}

	public function checkDeleted()
	{
		$q = $this->database->query('SELECT * FROM tweets_arc WHERE DATETIME(updated_on,\'unixepoch\') < :id AND obsolete = 0 AND deleted IS NULL',array(':id' => date('Y-m-d H:i:s',time()-180)));
		foreach($q as $t){
			$this->database->updateRows('tweets_arc', ['deleted'=>1,'deleted_at'=>time()], ['tweet_id=%s', intval($t['tweet_id'])]);
			$this->pb->allDevices()->pushNote("I've found a deleted tweet for ".$this->target."!", "Here it is: ".$t['tweet_body']);
		}
	}

	public function getDeleted()
	{
		return $this->database->query('SELECT * FROM tweets_arc WHERE deleted = 1 ORDER BY tweet_id ASC');
	}

	public function printStats()
	{
		$ob = count($this->database->query('SELECT * FROM tweets_arc WHERE obsolete = 1'));
		$total = count($this->database->query('SELECT * FROM tweets_arc'));
		$totalW = count($this->database->query('SELECT * FROM tweets_arc WHERE obsolete = 0 AND deleted IS NULL'));
		$totalD = count($this->database->query('SELECT * FROM tweets_arc WHERE deleted = 1'));
		$avgD = $this->database->query('SELECT (CAST(AVG(updated_on - date) as integer)/60)  as average  FROM tweets_arc WHERE deleted = 1')[0]['average'];

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
		echo "Obsoleted Tweets (stored, but over $this->days days old): $ob\n";
		echo "Deleted Tweets: $totalD\n";
		echo "Average time between create/deletion: $avgD minutes\n\n";
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
}