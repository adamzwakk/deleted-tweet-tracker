<?php

require_once('vendor/autoload.php');
date_default_timezone_set('UTC');

Eden\Core\Control::i();
$database = eden('sqlite', 'db.db3');  //instantiate

$q = $database->query('SELECT * FROM tweets_arc WHERE deleted = 1  ORDER BY tweet_id ASC');

if(count($q)){
	foreach($q as $t)
	{
		if(strpos($t['tweet_body'],'@') === 0)
		{
			continue;
		}
		echo "\n================\n";
		echo '[Tweeted: '.date('Y-m-d H:i:s',$t['date'])."] -- [Last seen: ".date('Y-m-d H:i:s',$t['updated_on'])."]\n".$t['tweet_body']."\n";
		echo "================\n\n";
	}
} else {
	echo "Nothing yettttt!\n";
}