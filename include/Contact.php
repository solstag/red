<?php


function map_perms($channel,$zguid,$zsig) {

	$is_contact = false;
	$is_site    = false;
	$is_network = false;
	$is_anybody = true;


	// To avoid sending the lengthy target_sig with each request,
	// We should provide an array of results for each target
	// and let the sender match the signature.

	if(strlen($zguid) && strlen($zsig)) {
		
		$is_network = true;

		$r = q("select * from contact where guid = '%s' and uid = %d limit 1",
			dbesc($zguid),
			intval($channel['channel_id'])
		);
		if($r && count($r)) {
			$is_contact = true;
			$contact = $r[0];
		}
		$r = q("select * from channel where channel_guid = '%s'",
			dbesc($zguid)
		);
		if($r && count($r)) {
			foreach($r as $rr) {
				if(base64url_encode(rsa_sign($rr['channel_guid'],$rr['channel_prvkey'])) === $zsig) {
					$is_site = true;
					break;
				}
			}
		}
	}

	$perms = array(
		'view_stream'   => array('channel_r_stream',  PERMS_R_STREAM ),
		'view_profile'  => array('channel_r_profile', PERMS_R_PROFILE),
		'view_photos'   => array('channel_r_photos',  PERMS_R_PHOTOS),
		'view_contacts' => array('channel_r_abook',   PERMS_R_ABOOK),

		'send_stream'   => array('channel_w_stream',  PERMS_W_STREAM),
		'post_wall'     => array('channel_w_wall',    PERMS_W_WALL),
		'tag_deliver'   => array('channel_w_tagwall', PERMS_W_TAGWALL),
		'post_comments' => array('channel_w_comment', PERMS_W_COMMENT),
		'post_mail'     => array('channel_w_mail',    PERMS_W_MAIL),
		'post_photos'   => array('channel_w_photos',  PERMS_W_PHOTOS),
		'chat'          => array('channel_w_chat',    PERMS_W_CHAT),
	);


	$ret = array();

	foreach($perms as $k => $v) {
		$ret[$k] = z_check_perms($k,$v,$channel,$contact,$is_contact,$is_site,$is_network,$is_anybody);

	}

	return $ret;

}

function z_check_perms($k,$v,$channel,$contact,$is_contact,$is_site,$is_network,$is_anybody) {

	$allow = (($contact['self']) ? true : false);
	
	switch($channel[$v[0]]) {
		case PERMS_PUBLIC:
				if($is_anybody)
					$allow = true;
				break;
		case PERMS_NETWORK:
				if($is_network)
					$allow = true;
				break;
		case PERMS_SITE:
				if($is_site)
					$allow = true;
				break;
		case PERMS_CONTACTS:
				if($is_contact)
					$allow = true;
				break;
		case PERMS_SPECIFIC:
				if($is_contact && is_array($contact) && ($contact['my_perms'] & $v[1]))
					$allow = true;
				break;
		default:
				break;
	}
	return $allow; 
}


















// Included here for completeness, but this is a very dangerous operation.
// It is the caller's responsibility to confirm the requestor's intent and
// authorisation to do this.

function user_remove($uid) {
	if(! $uid)
		return;
	$a = get_app();
	logger('Removing user: ' . $uid);

	$r = q("select * from user where uid = %d limit 1", intval($uid));

	call_hooks('remove_user',$r[0]);

	// save username (actually the nickname as it is guaranteed 
	// unique), so it cannot be re-registered in the future.

	q("insert into userd ( username ) values ( '%s' )",
		$r[0]['nickname']
	);

	q("DELETE FROM `contact` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `gcign` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `group` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `group_member` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `intro` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `event` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `item` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `item_id` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `mail` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `mailacct` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `manage` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `notify` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `photo` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `attach` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `profile` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `profile_check` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `pconfig` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `search` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `spam` WHERE `uid` = %d", intval($uid));
	q("DELETE FROM `user` WHERE `uid` = %d", intval($uid));
	if($uid == local_user()) {
		unset($_SESSION['authenticated']);
		unset($_SESSION['uid']);
		goaway($a->get_baseurl());
	}
}


function contact_remove($id) {

	$r = q("select uid from contact where id = %d limit 1",
		intval($id)
	);
	if((! count($r)) || (! intval($r[0]['uid'])))
		return;

	$archive = get_pconfig($r[0]['uid'], 'system','archive_removed_contacts');
	if($archive) {
		q("update contact set `archive` = 1, `network` = 'none', `writable` = 0 where id = %d limit 1",
			intval($id)
		);
		return;
	}

	q("DELETE FROM `contact` WHERE `id` = %d LIMIT 1",
		intval($id)
	);
	q("DELETE FROM `item` WHERE `contact-id` = %d ",
		intval($id)
	);
	q("DELETE FROM `photo` WHERE `contact-id` = %d ",
		intval($id)
	);
	q("DELETE FROM `mail` WHERE `contact-id` = %d ",
		intval($id)
	);
	q("DELETE FROM `event` WHERE `cid` = %d ",
		intval($id)
	);
	q("DELETE FROM `queue` WHERE `cid` = %d ",
		intval($id)
	);

}


// sends an unfriend message. Does not remove the contact

function terminate_friendship($user,$self,$contact) {


	$a = get_app();

	require_once('include/datetime.php');

	if($contact['network'] === NETWORK_OSTATUS) {

		$slap = replace_macros(get_markup_template('follow_slap.tpl'), array(
			'$name' => $user['username'],
			'$profile_page' => $a->get_baseurl() . '/profile/' . $user['nickname'],
			'$photo' => $self['photo'],
			'$thumb' => $self['thumb'],
			'$published' => datetime_convert('UTC','UTC', 'now', ATOM_TIME),
			'$item_id' => 'urn:X-dfrn:' . $a->get_hostname() . ':unfollow:' . random_string(),
			'$title' => '',
			'$type' => 'text',
			'$content' => t('stopped following'),
			'$nick' => $user['nickname'],
			'$verb' => 'http://ostatus.org/schema/1.0/unfollow', // ACTIVITY_UNFOLLOW,
			'$ostat_follow' => '' // '<as:verb>http://ostatus.org/schema/1.0/unfollow</as:verb>' . "\r\n"
		));

		if((x($contact,'notify')) && (strlen($contact['notify']))) {
			require_once('include/salmon.php');
			slapper($user,$contact['notify'],$slap);
		}
	}
	elseif($contact['network'] === NETWORK_DFRN) {
		require_once('include/items.php');
		dfrn_deliver($user,$contact,'placeholder', 1);
	}

}


// Contact has refused to recognise us as a friend. We will start a countdown.
// If they still don't recognise us in 32 days, the relationship is over,
// and we won't waste any more time trying to communicate with them.
// This provides for the possibility that their database is temporarily messed
// up or some other transient event and that there's a possibility we could recover from it.
 
if(! function_exists('mark_for_death')) {
function mark_for_death($contact) {

	if($contact['archive'])
		return;

	if($contact['term_date'] == '0000-00-00 00:00:00') {
		q("UPDATE `contact` SET `term_date` = '%s' WHERE `id` = %d LIMIT 1",
				dbesc(datetime_convert()),
				intval($contact['id'])
		);
	}
	else {

		// TODO: We really should send a notification to the owner after 2-3 weeks
		// so they won't be surprised when the contact vanishes and can take
		// remedial action if this was a serious mistake or glitch

		$expiry = $contact['term_date'] . ' + 32 days ';
		if(datetime_convert() > datetime_convert('UTC','UTC',$expiry)) {

			// relationship is really truly dead. 
			// archive them rather than delete
			// though if the owner tries to unarchive them we'll start the whole process over again

			q("update contact set `archive` = 1 where id = %d limit 1",
				intval($contact['id'])
			);

			//contact_remove($contact['id']);

		}
	}

}}

if(! function_exists('unmark_for_death')) {
function unmark_for_death($contact) {
	// It's a miracle. Our dead contact has inexplicably come back to life.
	q("UPDATE `contact` SET `term_date` = '%s' WHERE `id` = %d LIMIT 1",
		dbesc('0000-00-00 00:00:00'),
		intval($contact['id'])
	);
}}

if(! function_exists('contact_photo_menu')){
function contact_photo_menu($contact) {

	$a = get_app();
	
	$contact_url="";
	$pm_url="";
	$status_link="";
	$photos_link="";
	$posts_link="";
	$poke_link="";

	$sparkle = false;
	if($contact['network'] === NETWORK_DFRN) {
		$sparkle = true;
		$profile_link = $a->get_baseurl() . '/redir/' . $contact['id'];
	}
	else
		$profile_link = $contact['url'];

	if($profile_link === 'mailbox')
		$profile_link = '';

	if($sparkle) {
		$status_link = $profile_link . "?url=status";
		$photos_link = $profile_link . "?url=photos";
		$profile_link = $profile_link . "?url=profile";
		$pm_url = $a->get_baseurl() . '/message/new/' . $contact['id'];
	}

	$poke_link = $a->get_baseurl() . '/poke/?f=&c=' . $contact['id'];
	$contact_url = $a->get_baseurl() . '/contacts/' . $contact['id'];
	$posts_link = $a->get_baseurl() . '/network/?cid=' . $contact['id'];

	$menu = Array(
		t("Poke") => $poke_link,
		t("View Status") => $status_link,
		t("View Profile") => $profile_link,
		t("View Photos") => $photos_link,		
		t("Network Posts") => $posts_link, 
		t("Edit Contact") => $contact_url,
		t("Send PM") => $pm_url,
	);
	
	
	$args = array('contact' => $contact, 'menu' => &$menu);
	
	call_hooks('contact_photo_menu', $args);
	
	$o = "";
	foreach($menu as $k=>$v){
		if ($v!="") {
			if(($k !== t("Network Posts")) && ($k !== t("Send PM")) && ($k !== t('Edit Contact')))
				$o .= "<li><a target=\"redir\" href=\"$v\">$k</a></li>\n";
			else
				$o .= "<li><a href=\"$v\">$k</a></li>\n";
		}
	}
	return $o;
}}


function random_profile() {
	$r = q("select url from gcontact where url like '%%://%%/profile/%%' order by rand() limit 1");
	if(count($r))
		return dirname($r[0]['url']);
	return '';
}


function contacts_not_grouped($uid,$start = 0,$count = 0) {

	if(! $count) {
		$r = q("select count(*) as total from contact where uid = %d and self = 0 and id not in (select distinct(`contact-id`) from group_member where uid = %d) ",
			intval($uid),
			intval($uid)
		);

		return $r;


	}

	$r = q("select * from contact where uid = %d and self = 0 and id not in (select distinct(`contact-id`) from group_member where uid = %d) and blocked = 0 and pending = 0 limit %d, %d",
		intval($uid),
		intval($uid),
		intval($start),
		intval($count)
	);

	return $r;
}

