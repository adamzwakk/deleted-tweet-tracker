<?php

require_once('DeletedTweets.php');

$verbose = isset(getopt("v::")['v']);

$target = '';

$dt = new DeletedTweets(
	$target,
	[
		'day_cutoff' => 7
	],
	$verbose);

$dt->getTweets();
$dt->recordNewTweets();
$dt->markObsolete();
$dt->checkDeleted();
$dt->cleanup()

if($verbose){
	$dt->printStats();
}

echo "Done!\n";