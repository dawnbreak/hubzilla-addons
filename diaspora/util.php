<?php


function diaspora_handle_from_contact($contact_hash) {

	logger("diaspora_handle_from_contact: contact id is " . $contact_hash, LOGGER_DEBUG);

	$r = q("SELECT xchan_addr from xchan where xchan_hash = '%s' limit 1",
		dbesc($contact_hash)
	);
	if($r) {
		return $r[0]['xchan_addr'];
	}
	return false;
}

function diaspora_get_contact_by_handle($uid,$handle) {

	if(diaspora_is_blacklisted($handle))
		return false;
	require_once('include/channel.php');

	$sys = get_sys_channel();
	if(($sys) && ($sys['channel_id'] == $uid)) {
		$r = q("SELECT * FROM xchan where xchan_addr = '%s' limit 1",
			dbesc($handle)
		);
	}
	else {
		$r = q("SELECT * FROM abook left join xchan on xchan_hash = abook_xchan where xchan_addr = '%s' and abook_channel = %d limit 1",
			dbesc($handle),
			intval($uid)
		);
	}

	return (($r) ? $r[0] : false);
}

function find_diaspora_person_by_handle($handle) {

	$person = false;
	$refresh = false;

	if(diaspora_is_blacklisted($handle))
		return false;

	$r = q("select * from xchan left join hubloc on xchan_hash = hubloc_hash where hubloc_addr = '%s' limit 1",
		dbesc($handle)
	);
	if($r) {
		$person = $r[0];
		logger('find_diaspora_person_by handle: in cache ' . print_r($r,true), LOGGER_DATA, LOG_DEBUG);
		if($person['xchan_name_date'] < datetime_convert('UTC','UTC', 'now - 1 month')) {
			logger('Updating Diaspora cached record for ' . $handle);
			$refresh = true;
		}
	}

	if((! $person) || ($refresh)) {

		// try webfinger. Make sure to distinguish between diaspora, 
		// hubzilla w/diaspora protocol and friendica w/diaspora protocol.

		$result = discover_by_webbie($handle);
		if($result) {
			$r = q("select * from xchan left join hubloc on xchan_hash = hubloc_hash where hubloc_addr = '%s' limit 1",
				dbesc(str_replace('acct:','',$handle))
			);
			if($r) {
				$person = $r[0];
				logger('find_diaspora_person_by handle: discovered ' . print_r($r,true), LOGGER_DATA, LOG_DEBUG);
			}
		}
	}

	return $person;
}


function get_diaspora_key($handle) {
	logger('Fetching diaspora key for: ' . $handle, LOGGER_DEBUG);
	$r = find_diaspora_person_by_handle($handle);
	return(($r) ? $r['xchan_pubkey'] : '');
}



/**
 * Some utility functions for processing the Diaspora comment virus.
 *
 */  




function diaspora_sign_fields($fields,$prvkey) {

	if(! $fields)
		return '';

	$n = array();
	foreach($fields as $k => $v) {
		if($k !== 'author_signature' && $k !== 'parent_author_signature')
			$n[$k] = $v;
	}

	$s = implode($n,';');
	logger('signing_string: ' . $s);
	return base64_encode(rsa_sign($s,$prvkey));

}


function diaspora_verify_fields($fields,$sig,$pubkey) {

	if(! $fields)
		return false;

	$n = array();
	foreach($fields as $k => $v) {
		if($k !== 'author_signature' && $k !== 'parent_author_signature')
			$n[$k] = $v;
	}

	$s = implode($n,';');
	logger('signing_string: ' . $s);
	return rsa_verify($s,base64_decode($sig),$pubkey);

}

function diaspora_fields_to_xml($fields) {

	if(! $fields)
		return '';
	$s = '';
	foreach($fields as $k => $v) {
		$s .= '<' . $k . '>' . xmlify($v) . '</' . $k . '>' . "\n";
	}
	return rtrim($s);
}


function diaspora_build_relay_tags() {

	$alltags = array();

	$r = q("select * from pconfig where cat = 'diaspora' and k = 'followed_tags'");
	if($r) {
		foreach($r as $rr) {
			if(preg_match('|^a:[0-9]+:{.*}$|s',$rr['v'])) {
				$x = unserialize($rr['v']);
				if($x && is_array($x))
					$alltags = array_unique(array_merge($alltags,$x));
			}
		}
	}
	set_config('diaspora','relay_tags',$alltags);
	// Now register to pick up any changes
	$url = "https://the-federation.info/register/" . App::get_hostname();
	$ret = z_fetch_url($url);

}
	

function diaspora_magic_env($channel,$msg) {

	$data        = preg_replace('/s+/','', base64url_encode($msg));
	$keyhash     = base64url_encode(channel_reddress($channel));
	$type        = 'application/xml';
	$encoding    = 'base64url';
	$algorithm   = 'RSA-SHA256';
	$precomputed = '.YXBwbGljYXRpb24vYXRvbSt4bWw=.YmFzZTY0dXJs.UlNBLVNIQTI1Ng==';
	$signature   = base64url_encode(rsa_sign($data . $precomputed, $channel['channel_prvkey']));

	return replace_macros(get_markup_template('magicsig.tpl','addon/diaspora'),
		[
			'$data'      => $data,
			'$encoding'  => $encoding,
			'$algorithm' => $algorithm, 
			'$keyhash'   => $keyhash,
			'$signature' => $signature
		]
	);
}

function diaspora_share_unsigned(&$item,$author) {

	if(! $author) {
		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($item['author_xchan'])
		);
		if($r) {
			$author = $r[0];
		}
		else {
			return;
		}
	}

	// post will come across with the owner's identity. Throw a preamble onto the post to indicate the true author.
	$item['body'] = "\n\n"
		. '[quote]'
		. '[img]' . $author['xchan_photo_s'] . '[/img]'
		. ' '
		. '[url=' . $author['xchan_url'] . '][b]' . $author['xchan_name'] . '[/b][/url]' . "\n\n"
		. $item['body']
		. '[/quote]';

}


function diaspora_build_status($item,$owner) {

	$myaddr = channel_reddress($owner);

	if(intval($item['id']) != intval($item['parent'])) {
		logger('attempted to send a comment as a top-level post');
		return;
	}

	if(($item['item_wall']) && ($item['owner_xchan'] != $item['author_xchan']) &&
		get_pconfig($owner['channel_id'],'diaspora','sign_unsigned')) {
		diaspora_share_unsigned($item,(($item['author']) ? $item['author'] : null));
	}

	$images = array();

	$title = $item['title'];
	$body = bb_to_markdown($item['body']);

	$poll = '';

	$public = (($item['item_private']) ? 'false' : 'true');

	$created = datetime_convert('UTC','UTC',$item['created'],'Y-m-d H:i:s \U\T\C');

	$created_at = datetime_convert('UTC','UTC',$item['created'],ATOM_TIME);

	if(defined('DIASPORA_V2')) {

		// common attributes

		$arr = [
			'author'     => $myaddr,
			'guid'       => $item['mid'],
			'created_at' => $created_at,
		];

		// context specific attributes

		if((! $item['item_private']) && ($ret = diaspora_is_reshare($item['body']))) {
			$arr['root_author'] = $ret['root_handle'];
			$arr['root_guid']   = $ret['root_guid'];
			$msg = arrtoxml('reshare', $arr);
		} 
		else {
			$arr['public'] = $public;
			$arr['text']   = $body;

			if($item['app'])
				$arr['provider_display_name'] = $item['app'];

			if($item['location'] || $item['coord']) {
				//@TODO once we figure out if they will accept location and coordinates separately,
				// at present it seems you need both and fictitious locations aren't acceptable
			}
			$msg = arrtoxml('status_message', $arr);
		}
	}
	else {

		// Old style messages using templates - Detect a share element and do a reshare

		if((! $item['item_private']) && ($ret = diaspora_is_reshare($item['body']))) {
			$msg = replace_macros(get_markup_template('diaspora_reshare.tpl','addon/diaspora'),
				[
					'$root_handle' => xmlify($ret['root_handle']),
					'$root_guid' => $ret['root_guid'],
					'$guid' => $item['mid'],
					'$handle' => xmlify($myaddr),
					'$public' => $public,
					'$created' => $created,
					'$provider' => (($item['app']) ? $item['app'] : t('$projectname'))
				]
			);
		} 
		else {
			$msg = replace_macros(get_markup_template('diaspora_post.tpl','addon/diaspora'),
				[
					'$body' => xmlify($body),
					'$guid' => $item['mid'],
					'$poll' => $poll,
					'$handle' => xmlify($myaddr),
					'$public' => $public,
					'$created' => $created,
					'$provider' => (($item['app']) ? $item['app'] : t('$projectname'))
				]
			);
		}
	}

	return $msg;
}



function get_diaspora_reshare_xml($url,$recurse = 0) {

	$x = z_fetch_url($url);
	if(! $x['success'])
		$x = z_fetch_url(str_replace('https://','http://',$url));
	if(! $x['success']) {
		logger('get_diaspora_reshare_xml: unable to fetch source url ' . $url);
		return;
	}

	logger('get_diaspora_reshare_xml: source: ' . $x['body'], LOGGER_DEBUG);

	$source_xml = xml2array($x['body'],false,0,'tag');

	if(! $source_xml) {
		logger('get_diaspora_reshare_xml: unparseable result from ' . $url);
		return '';
	}

	if($source_xml) {
		if(array_key_exists('xml',$source_xml) && array_key_exists('post',$source_xml['xml'])) 
			$source_xml = $source_xml['xml']['post'];
	}
	if($source_xml['status_message']) {
		return $source_xml;
	}

	// see if it's a reshare of a reshare
	
	if($source_xml['reshare'])
		$xml = $source_xml['reshare'];
	else 
		return false;

	if(($xml['root_diaspora_id'] || $xml['root_author']) && $xml['root_guid'] && $recurse < 15) {
		$orig_author = notags(diaspora_get_root_author($xml));
		$orig_guid = notags(unxmlify($xml['root_guid']));
		$source_url = 'https://' . substr($orig_author,strpos($orig_author,'@')+1) . '/p/' . $orig_guid . '.xml';
		$y = get_diaspora_reshare_xml($source_url,$recurse+1);
		if($y)
			return $y;
	}
	return false;
}

