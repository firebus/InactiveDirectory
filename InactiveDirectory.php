<?php

# ubuntu package requirements: php5-ldap, php5-sqlite, php5-curl

$config = parse_ini_file('config.ini', TRUE);
$updated = time();

# connect to database
$dbh = new PDO("sqlite:" . __DIR__ . '/deathwatch.sq3');			

$firstRun = setupDatabase($dbh);
if ($firstRun) {
    error_log(date('c') . ' action=first_run');
}

$users = getUsers($config['ldap']);
if ($users) {
	error_log(date('c') . " action=ldapsearch count={$users['count']}");
	$count = updateUsers($dbh, $users, $config['ldap']['ldap_skip_ou_list'], $updated);
	error_log(date('c') . " action=update_complete count=$count");
	if ($firstRun) {
		notifyHipchat($config['hipchat'], "first run. $count users.", "yellow");
	} else {
		$deadCount = processDeadUsers($config['hipchat'], $dbh, $updated);
		$newCount = processNewUsers($config['hipchat'], $dbh);
		if ($deadCount || $newCount) {
			$newCount = $count - $deadCount + $newCount;
			notifyHipchat($config['hipchat'], "$newCount users.", "yellow");
		}
	}
} else {
	error_log(date('c') . ' action=users message="no users"');
}

# create the table on first run
function setUpDatabase($dbh) {
	$firstRun = FALSE;
	$statement = $dbh->query("select * from sqlite_master");
	$result = $statement->fetchAll();
	if ($statement === FALSE || count($result) == 0) {
		$dbh->query('CREATE TABLE "deathwatch" (
			"id" INTEGER PRIMARY KEY,
			"dn" TEXT UNIQUE,
			"cn" TEXT,
			"title" TEXT,
			"department TEXT,
			"location" TEXT,
			"mail" TEXT,
			"created" NUMERIC NOT NULL DEFAULT CURRENT_TIMESTAMP,
			"updated" NUMERIC,
			"dead" INTEGER NOT NULL DEFAULT 0
		);');
		$firstRun = TRUE;
	}
	
	return $firstRun;
}

function getUsers($config) {
	# connnect to ldap
	$link_id = ldap_connect($config['ldap_host']);
	$users = FALSE;
	if (ldap_set_option($link_id, LDAP_OPT_PROTOCOL_VERSION, 3)) {
		if (ldap_bind($link_id, $config['ldap_bind_user'], $config['ldap_bind_password'])) {
			$result_id = ldap_search($link_id, $config['ldap_base_dn'], $config['ldap_filter'],
				array('dn', 'cn', 'title', 'department', 'physicalDeliveryOfficeName', 'mail'));
			if ($result_id) {
				$users = ldap_get_entries($link_id, $result_id);
			}
			ldap_unbind($link_id);
		}
	}

	return $users;
}

# update/insert all valid users into the database
function updateUsers($dbh, $users, $skip_ou_list, $updated) {
	$count = $users['count'];
	$skip_ous = array();
	if ($skip_ou_list) {
		$skip_ous = split(',', $skip_ou_list);
	}
	foreach ($users as $key => $user) {
		if (is_int($key)) {
			foreach ($skip_ous as $ou) {
				if (strstr($user['dn'], "OU=$ou")) {
					error_log(date('c') . " action=skipping_user reason=ou dn=\"{$user['dn']} ou=$ou");
					$count--;
					continue 2;
				}
			}
			if (empty($user['title']) || empty($user['department'])) {
				error_log(date('c') . " action=skipping_user reason=missing_attributes dn=\"{$user['dn']}");
				$count--;
				continue;
			}
			
			error_log(date('c') . " action=update_user dn=\"{$user['dn']}\"");
			$mail = isset($user['mail'][0]) ? $user['mail'][0] : '';
			$location = isset($user['physicaldeliveryofficename'][0]) ? $user['physicaldeliveryofficename'][0] : '';
			$sth = $dbh->prepare("INSERT OR REPLACE INTO deathwatch"
				. " (id, dn, cn, title, department, location, mail, created, updated)"
				. " VALUES ((SELECT id FROM deathwatch WHERE dn = ?), ?, ?, ?, ?, ?, ?,"
				. " (SELECT created FROM deathwatch WHERE dn = ?), datetime(?, 'unixepoch'))");
			$sth->execute(array($user['dn'], $user['dn'], $user['cn'][0], $user['title'][0], $user['department'][0],
				$location, $mail, $user['dn'], $updated));
		}
	}
	
	return $count;
}

# any users that were not just updated are dead. mark them dead. send a notification.
function processDeadUsers($config, $dbh, $updated) {
	$result = $dbh->query("SELECT id, dn, cn, title, department, location, mail"
		. " FROM deathwatch WHERE dead = 0 AND updated < datetime($updated, 'unixepoch')");
	$dead_users = $result->fetchAll(PDO::FETCH_ASSOC);
	$count = 0;
	foreach ($dead_users as $dead_user) {
		$count++;
		# TODO: don't send goodbye w/o checking for errors
		$sth = $dbh->prepare("UPDATE deathwatch SET dead = 1 WHERE id = ?");
		$sth->execute(array($dead_user['id']));
		error_log (date('c') . " action=dead_user dn=\"{$dead_user['dn']}\"");
		$result = notifyHipchat(
			$config, 
			"goodbye {$dead_user['cn']} ({$dead_user['mail']}), {$dead_user['title']} in {$dead_user['department']} at {$dead_user['location']}",
			"red");
		if ($result) {
			error_log(date('c') . ' action=hipchat_notification message="' . addcslashes($result, '"') . '"');
		} elseif ($result === FALSE) {
			error_log(date('c') . ' action=hipchat_notification message="notification failed"');
		}
	}
	
	return $count;
}

# any users with created time that is later than updated time are new. send a notification.
function processNewUsers($config, $dbh) {
	$result = $dbh->query("SELECT dn, cn, title, department, location, mail"
		. " FROM deathwatch WHERE dead = 0 AND updated <= created");
	$new_users = $result->fetchAll(PDO::FETCH_ASSOC);
	$count = 0;
	foreach ($new_users as $new_user) {
		$count++;
		error_log(date('c') . " action=new_user dn=\"{$new_user['dn']}\"");
		$result = notifyHipchat(
			$config,
			"welcome {$new_user['cn']} ({$new_user['mail']}), {$new_user['title']} in {$new_user['department']} at {$new_user['location']}",
			"green");
		if ($result) {
			error_log(date('c') . ' action=hipchat_notification message="' . addcslashes($result, '"') . '"');
		} elseif ($result === FALSE) {
			error_log(date('c') . ' action=hipchat_notification message="notification failed"');
		}
	}
	return $count;
}

function notifyHipchat($config, $message, $color) {
	$url = "{$config['hipchat_url']}/v2/room/{$config['hipchat_room']}/notification?auth_token={$config['hipchat_token']}";
	#TODO escape $message properly
	$data = "{\"color\":\"$color\", \"message\":\"$message\", \"notify\": true}";

	//  Initiate curl
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, TRUE);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$result = curl_exec($ch);
	curl_close($ch);
	return $result;
}