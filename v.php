<?php

require_once('DeletedTweets.php');

date_default_timezone_set('UTC');

$verbose = isset(getopt("v::")['v']);

$dt = new DeletedTweets([
		'day_cutoff' => 7
	],
	$verbose);

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