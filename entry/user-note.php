<?php

// service closed until we can filter spam
//die ('[CLOSED]');

// ** alerts ** remove comment when alerts are on-line
//require_once 'alert_lib.inc';
include_once 'note-reasons.inc';
include_once 'spam-lib.inc';

$mailto = 'php-notes@lists.php.net';
$failto = 'jimw@php.net, alindeman@php.net';

if (!isset($user) || empty($note) || empty($sect) || empty($ip) || !isset($redirip))
  die("missing some parameters.");


/* SPAM Checks ******************************************/
$note_lc = strtolower($note);

// Disallow URL SPAM
if (check_spam_urls($note_lc, 4)) {
    die ('[SPAM WORD]');
}

// Compare the text against a known list of bad words
if (check_spam_words($note_lc, $words_blacklist)) {
    die('[SPAM WORD]');
}

// Check if the IP is blacklisted
if (($spamip=is_spam_ip($_SERVER['REMOTE_ADDR'])) || ($spamip=is_spam_ip($ip)) || ($spamip=is_spam_ip($redirip))) {
    die ("[SPAMMER] $spamip");
}

unset($note_lc);
/* End SPAM Checks ******************************************/

@mysql_connect("localhost","nobody", "")
  or die("failed to connect to database");
@mysql_select_db("phpmasterdb")
  or die("failed to select database");

/*
After a discussion in #php about the
vulnerability of the user notes system,
I decided to implement a bit of hack
prevention.  This makes sure that only
3 notes can be submitted per minute, which
is very reasonable considering the current
flow of notes usually submitted.  This prevents
a large flood of notes from coming in.
*/
$query = 'SELECT COUNT(*) FROM note WHERE ts >= (NOW() - INTERVAL 1 MINUTE)';
$result = @mysql_query ($query);

if (!$result) {
  mail ($failto,
       'failed manual note query',
       "Query Failed: $query\nError: ".mysql_error(),
       'From: webmaster@php.net');
  die("failed to query note db");
}

list ($count) = mysql_fetch_row ($result);

if ($count >= 3) {
  //Send error to myself.  If this happens too many times, I'll increase
  //the amount of allowed notes
  mail ('alindeman@php.net,didou@php.net',
	'[php-notes] Quota exceeded',
	'Too many notes submitted in one minute.  Consider increasing quota' . "\n" . 
        'Occured at '.date ('M d, Y g:i:s A') . "\n" .
	"User   : $user\n" .
	"Section: $section\n" .
	"Note   : $note",
	'From: webmaster@php.net'
       );
  die ('[TOO MANY NOTES]');
}

$sect = ereg_replace("\.php$","",$sect);

$query = "INSERT INTO note (user, note, sect, ts, status) VALUES ";
# no need to call htmlspecialchars() -- we handle it on output
$query .= "('$user','$note','$sect',NOW(), NULL)";

//na = not approved.  Don't display notes until they are approved by an editor
//This has been reverted until it has been discussed further.

//echo "<!--$query-->\n";
if (@mysql_query($query)) {
  $new_id = mysql_insert_id();	
  $msg = stripslashes($note);

  $msg .= "\n----\n";
  $msg .= "Server IP: {$_SERVER['REMOTE_ADDR']}";
  if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_VIA'])) {
    $msg .= " (proxied:";
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $msg .= " " . htmlspecialchars($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
    if (isset($_SERVER['HTTP_VIA'])) {
      $msg .= " " . htmlspecialchars($_SERVER['HTTP_VIA']);
    }
    $msg .= ")";
  }
  $msg .= "\nProbable Submitter: {$ip}" . ($redirip ? ' (proxied: '.htmlspecialchars($redirip).')' : '');

  $msg .= "\n----\n";
//  $msg .= $spam_data;
//  $msg .= "\n----\n";

  $msg .= "Manual Page -- http://www.php.net/manual/en/$sect.php\n";
  $msg .= "Edit        -- https://master.php.net/note/edit/$new_id\n";
  //$msg .= "Approve     -- https://master.php.net/manage/user-notes.php?action=approve+$new_id&report=yes\n";
  foreach ($note_del_reasons AS $reason) {
    $msg .= "Del: "
      . str_pad($reason, $note_del_reasons_pad)
      . "-- https://master.php.net/note/delete/$new_id/" . urlencode($reason) ."\n";
  }
  $msg .= str_pad('Del: other reasons', $note_del_reasons_pad) . "-- https://master.php.net/note/delete/$new_id\n";
  $msg .= "Reject      -- https://master.php.net/note/reject/$new_id\n";
  $msg .= "Search      -- https://master.php.net/manage/user-notes.php\n";
  # make sure we have a return address.
  if (!$user) $user = "php-general@lists.php.net";
  # strip spaces in email address, or will get a bad To: field
  $user = str_replace(' ','',$user);
  // see who requested an alert
  // ** alerts **
  //$mailto .=  get_emails_for_sect($sect);
  mail($mailto,"note $new_id added to $sect",$msg,"From: $user\r\nMessage-ID: <note-$new_id@php.net>");
} else {
  // mail it.
  mail($failto,
      'failed manual note query',
      "Query Failed: $query\nError: ".mysql_error(),
      'From: webmaster@php.net');
  die("failed to insert record");
}





//var_dump(is_spammer('127.0.0.1')); // false
//var_dump(is_spammer('127.0.0.2')); // true

?>
