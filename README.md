# Deleted Tweet Tracker

Run composer install, write vars into an .env file, run `php t.php` to get your first batch. Add to a 1min cron job for more automated fun :)

Running `php v.php` will spit out the deleted tweets in order of when they were tweeted (this is more just to save myself the sql query)

Other things to note:

1. Adding `-v` to either command will add verbose output and a list of stats at the bottom.
2. I use Pushbullet to ping my devices on detection, but feel free to comment out/use something else.
3. I discard/don't get retweets cause it defeats the purpose.
4. I didn't include the sqlite file, but it's easy enough to figure out (let me know I should include an empty one anyways)