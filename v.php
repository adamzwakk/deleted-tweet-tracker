<?php

require_once('DeletedTweets.php');

//Since we're only reading here, convert to local timezones
date_default_timezone_set(getenv('timezone'));

$dt = new DeletedTweets();
$q = $dt->printDeleted();

if($dt->isVerbose()){
	$dt->printStats();
}