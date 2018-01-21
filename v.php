<?php

require_once('DeletedTweets.php');

//Since we're only reading here, convert to local timezones
date_default_timezone_set(getenv('timezone'));

$verbose = isset(getopt("v::")['v']);

$dt = new DeletedTweets(null, [], $verbose);
$q = $dt->getDeleted();

if(count($q)){
	foreach($q as $t)
	{
		echo "\n================\n";
		echo '[Tweeted: '.date('Y-m-d H:i:s',$t['date'])."] -- [Last seen: ".date('Y-m-d H:i:s',$t['updated_on'])."]\n";
		echo $t['tweet_body']."\n";
		echo "================\n\n";
	}
} else {
	echo "Nothing yettttt!\n";
}

if($verbose){
	$dt->printStats();
}