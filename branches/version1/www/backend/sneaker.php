<?
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include('../config.php');
include(mnminclude.'link.php');
include(mnminclude.'sneak.php');

$foo_link = new Link;

// The client requests version number
if (!empty($_GET['getv'])) {
	echo $sneak_version;
	die;
}

$now = time();
if(!($time=check_integer('time')) > 0) {
	$time = 0;
	$dbtime = date("YmdHis", $time-3600);
}  else {
	$dbtime = date("YmdHis", $time);
}

$last_timestamp = $time;

if(!empty($_GET['items']) && intval($_GET['items']) > 0) {
	$max_items = intval($_GET['items']);
}

header('Content-Type: text/html; charset=utf-8');

$client_version = $_GET['v'];
if (empty($client_version) || ($client_version != -1 && $client_version != $sneak_version)) {
	echo "window.location.reload(true);";
	exit();
}

// Only registered users can see the chat messages
if ($current_user->user_id > 0 && empty($_GET['nochat'])) {
	check_chat();
	get_chat($time);
}

if(intval($_GET['r']) % 5 == 0) update_sneakers();

if (empty($_GET['novote']) || empty($_GET['noproblem'])) get_votes($dbtime);


// Get the logs
$logs = $db->get_results("select UNIX_TIMESTAMP(log_date) as time, log_type, log_ref_id, log_user_id from logs where log_date > $dbtime order by log_date desc limit $max_items");

if ($logs) {
	foreach ($logs as $log) {
		switch ($log->log_type) {
			case 'link_new':
				if (empty($_GET['nonew'])) get_story($log->time, 'new', $log->log_ref_id, $log->log_user_id);
				break;
			case 'link_publish':
				if (empty($_GET['nopublished'])) get_story($log->time, 'published', $log->log_ref_id, $log->log_user_id);
				break;
			case 'comment_new':
				if (empty($_GET['nocomment'])) get_comment($log->time, 'comment', $log->log_ref_id, $log->log_user_id);
				break;
			case 'link_discard':
				if (empty($_GET['nodiscard'])) get_story($log->time, 'discarded', $log->log_ref_id, $log->log_user_id);
				break;
			case 'link_edit':
				if (empty($_GET['noedit'])) get_story($log->time, 'edited', $log->log_ref_id, $log->log_user_id);
				break;
			case 'comment_edit':
				if (empty($_GET['nocedit'])) get_comment($log->time, 'cedited', $log->log_ref_id, $log->log_user_id);
				break;
		}
	}
}

if($last_timestamp == 0) $last_timestamp = $now;

$ccntu .= $db->get_var("select count(*) from sneakers where sneaker_user > 0");
$ccnta .= $db->get_var("select count(*) from sneakers where sneaker_user = 0");
$ccnt = $ccntu+$ccnta . " ($ccntu+$ccnta)";
echo "ts=$last_timestamp;ccnt='$ccnt';\n";
if(count($events) < 1) exit;
krsort($events);

$counter=0;
echo "new_data = ([";
foreach ($events as $key => $val) {
	if ($counter>0) 
		echo ",";
	echo "{" . $val . "}";
	$counter++;
	if($counter>=$max_items) {
		echo "]);";
		exit();
	}
}
echo "]);";

function check_chat() {
	global $db, $current_user, $now, $globals, $events;
	if(empty($_REQUEST['chat'])) return;
	$comment = trim(preg_replace("/[\r\n\t]/", ' ', $_REQUEST['chat']));
	if ($current_user->user_id > 0 && strlen($comment) > 2) {
		// Sends a message back if the user has a very low karma
		if ($globals['min_karma_for_sneaker'] > 0 && $current_user->user_karma < $globals['min_karma_for_sneaker']) {
			$comment = _('no tienes suficiente karma para comentar en la fisgona').' ('.$current_user->user_karma.' < '.$globals['min_karma_for_sneaker'].')';
			send_chat_warn($comment);
			return;
		}
		if (preg_match('/^!/', $comment)) {
			require_once('sneaker-stats.php');
			$comment = check_stats($comment);
		} else {
			$comment = htmlspecialchars($comment);
			$comment = preg_replace('/(^|[\s\.,¿])\/me([\s\.,\?]|$)/', "$1<i>$current_user->user_login</i>$2", $comment);
		}

		$from = $now - 900;
		$db->query("delete from chats where chat_time < $from");
		$comment = $db->escape(trim($comment));
		if (strlen($comment)>0) {
			$db->query("insert into chats (chat_time, chat_uid, chat_user, chat_text) values ($now, $current_user->user_id, '$current_user->user_login', '$comment')");
		}

	}
}

function send_chat_warn($mess) {
	global $current_user, $now, $globals, $events;

	$comment = text_to_html(_('*Aviso*: ').$mess);
	$uid = $current_user->user_id;
	$who = $current_user->user_login;
	$timestamp = $now;
	$key = $timestamp . ':chat:'.$id;
	$type = 'chat';
	$status = _('chat');
	$events[$key] = 'ts:"'.$timestamp.'",type:"'.$type.'",votes:"0",com:"0",link:"0",title:"'.addslashes($comment).'",who:"'.addslashes($who).'",status:"'.$status.'",uid:"'.$uid.'"';
}

function get_chat($time) {
	global $db, $events, $last_timestamp, $max_items, $current_user;
	$res = $db->get_results("select * from chats where chat_time > $time order by chat_time desc limit $max_items");
	if (!$res) return;
	foreach ($res as $event) {
		$uid = $id = $event->chat_uid;
		$who = $event->chat_user;
		$timestamp = $event->chat_time;
		$key = $timestamp . ':chat:'.$id;
		$type = 'chat';
		$status = _('chat');
		$comment = text_to_html($event->chat_text);
		$events[$key] = 'ts:"'.$timestamp.'",type:"'.$type.'",votes:"0",com:"0",link:"0",title:"'.addslashes($comment).'",who:"'.addslashes($who).'",status:"'.$status.'",uid:"'.$uid.'"';
		if($timestamp > $last_timestamp) $last_timestamp = $timestamp;
	}
}


// Check last votes
function get_votes($dbtime) {
	global $db, $events, $last_timestamp, $foo_link, $max_items, $current_user;

	if (!empty($_GET['nopubvotes'])) 
		$pvotes = "and link_status != 'published'";
	else $pvotes = '';

	$res = $db->get_results("select vote_id, unix_timestamp(vote_date) as timestamp, vote_value, INET_NTOA(vote_ip_int) as vote_ip, vote_user_id, link_id, link_title, link_uri, link_status, link_date, link_published_date, link_votes, link_comments from votes, links where vote_type='links' and vote_date > $dbtime and link_id = vote_link_id $pvotes and vote_user_id != link_author order by vote_date desc limit $max_items");
	if (!$res) return;
	foreach ($res as $event) {
		if ($event->vote_value >= 0 && !empty($_GET['novote'])) continue;
		if ($event->vote_value < 0 && !empty($_GET['noproblem'])) continue;
		$foo_link->id=$event->link_id;
		$foo_link->uri=$event->link_uri;
		$link = $foo_link->get_relative_permalink();
		$id=$event->vote_id;
		$uid = $event->vote_user_id;
		if($uid > 0) {
			$res = $db->get_row("select user_login from users where user_id = $uid");
			$user = $res->user_login;
		} else {
			$user= preg_replace('/\.[0-9]+$/', '', $event->vote_ip);
		}
		if ($event->vote_value >= 0) {
			$type = 'vote';
			$who = $user;
		} else { 
			$type = 'problem';
			$who = get_negative_vote($event->vote_value);
			// Show user_login if she voted more than N negatives in one minute
			if($current_user->user_id > 0 && ($current_user->user_level == 'admin' || $current_user->user_level == 'god')) {
				$negatives_last_minute = $db->get_var("select count(*) from votes where vote_type='links' and vote_user_id=$uid and vote_date > date_sub(now(), interval 1 minute) and vote_value < 0");
				if($negatives_last_minute > 2 ) {
					$who .= "<br>($user)";
				}
			}
		}
		$status =  get_status($event->link_status);
		$key = $event->timestamp . ':votes:'.$id;
		$events[$key] = 'ts:"'.$event->timestamp.'",type:"'.$type.'",votes:"'.$event->link_votes.'", com:"'.$event->link_comments.'",link:"'.$link.'",title:"'.addslashes($event->link_title).'",who:"'.addslashes($who).'",status:"'.$status.'",uid:"'.$uid.'",id:"'.$event->link_id.'"';
		if($event->timestamp > $last_timestamp) $last_timestamp = $event->timestamp;
	}
}


function get_story($time, $type, $linkid, $userid) {
	global $db, $events, $last_timestamp, $foo_link;
	$event = $db->get_row("select user_login, link_title, link_uri, link_status, link_votes, link_comments from links, users where link_id = $linkid and user_id=$userid");
	if (!$event) return;
	$foo_link->id=$linkid;
	$foo_link->uri=$event->link_uri;
	$link = $foo_link->get_relative_permalink();
	$id=$linkid;
	$status =  get_status($event->link_status);
	$key = $time . ':'.$type.':'.$id;
	$events[$key] = 'ts:"'.$time.'",type:"'.$type.'",votes:"'.$event->link_votes.'",com:"'.$event->link_comments.'",link:"'.$link.'",title:"'.addslashes($event->link_title).'",who:"'.addslashes($event->user_login).'",status:"'.$status.'",uid:"'.$userid.'",id:"'.$linkid.'"';
	if($time > $last_timestamp) $last_timestamp = $time;
}

function get_comment($time, $type, $commentid, $userid) {
	global $db, $events, $last_timestamp, $foo_link, $max_items;
	$event = $db->get_row("select user_login, comment_user_id, comment_order, link_id, link_title, link_uri, link_status, link_date, link_published_date, link_votes, link_comments from comments, links, users where comment_id = $commentid and link_id = comment_link_id and user_id=$userid");
	if (!$event) return;
	$foo_link->id=$event->link_id;
	$foo_link->uri=$event->link_uri;
	$link = $foo_link->get_relative_permalink()."#comment-$event->comment_order";
	$who = $event->user_login;
	$status =  get_status($event->link_status);
	$key = $time . ':'.$type.':'.$commentid;
	$events[$key] = 'ts:"'.$time.'",type:"'.$type.'",votes:"'.$event->link_votes.'",com:"'.$event->link_comments.'",link:"'.$link.'",title:"'.addslashes($event->link_title).'",who:"'.addslashes($who).'",status:"'.$status.'",uid:"'.$userid.'",id:"'.$commentid.'"';
	if($time > $last_timestamp) $last_timestamp = $time;
}

function get_status($status) {
	switch ($status) {
		case 'published':
			$status = _('publicada');
			break;
		case 'queued':
			$status = _('pendiente');
			break;
		case 'discard':
			$status = _('descartada');
			break;
	}
	return $status;
}


function error($mess) {
	header('Content-Type: text/plain; charset=UTF-8');
	echo "ERROR: $mess";
	die;
}

function update_sneakers() {
	global $db, $globals, $now, $current_user;
	$key = $globals['user_ip'] . '-' . intval($_GET['k']);
	$db->query("replace into sneakers (sneaker_id, sneaker_time, sneaker_user) values ('$key', $now, $current_user->user_id)");
	if($_GET['r'] % 10 == 0) {
		$from = $now-120;
		$db->query("delete from sneakers where sneaker_time < $from");
	}
}
?>
