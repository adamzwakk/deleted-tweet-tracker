<?php

require_once('DeletedTweets.php');

$verbose = isset(getopt("v::")['v']);

$dt = new DeletedTweets();

$dt->getTweets();
$dt->recordNewTweets();
$dt->markObsolete();
$dt->checkDeleted();
$dt->cleanup();

if($dt->isVerbose()){
	$dt->printStats();
}

echo "Done!\n";