# Deleted Tweet Tracker

I wrote this to keep a log of tweets from any given user, and to flag when a user deletes a tweet (while still retaining it and when it wasn't found anymore). I realize I could have wrote in python or something better, but...

![alt text](https://memegenerator.net/img/images/600x600/16815035/meh-lisa.jpg "Eh...")

Run composer install, write vars into an .env file, run `php t.php` to get your first batch. Add to a 1min cron job for more automated fun :)

Running `php v.php` will spit out the deleted tweets in order of when they were tweeted (this is more just to save myself the sql query)

Other things to note:

1. Adding `-v` to either command will add verbose output and a list of stats at the bottom.
2. I use Pushbullet to ping my devices on detection, but feel free to comment out/use something else.
3. I discard/don't get retweets cause it defeats the purpose.
4. I didn't include the sqlite file, but it's easy enough to figure out (let me know I should include an empty one anyways)