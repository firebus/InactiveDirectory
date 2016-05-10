<?php

# ubuntu package requirements: php5-ldap, php5-sqlite, php5-curl

global $config;
$config = parse_ini_file('config.ini', TRUE);

global $updated;
$updated = time();

# connect to database
global $dbh;
$dbh = new PDO("sqlite:" . __DIR__ . '/deathwatch.sq3');			

$firstRun = setupDatabase();
if ($firstRun) {
	logger(array('step' => 'setupDatabase', 'action' => 'first_run', 'status' => 'success'));
}

$users = getUsers();
if ($users) {
	logger(array('step' => 'getUsers', 'status' => 'success', 'count' => $users['count']));
	list($totalUsers, $regularUsers, $contractUsers, $otherUsers) = updateUsers($users);
	logger(array('step' => 'updateUsers', 'status' => 'success', 'count' => $totalUsers));
	if ($firstRun) {
		notifyHipchat("first run. $count users.", "yellow");
	} else {
		$deadCount = processDeadUsers();
		$newCount = processNewUsers();
		if ($deadCount) {
			notifyHipchat("$deadCount users removed.", "red");
		}
		if ($newCount) {
			notifyHipchat("$newCount users added.", "green");
		}
		notifyHipchat("$totalUsers total users. $regularUsers regular, $contractUsers contractors, $otherUsers uncategorized.", "yellow");
	}
} else {
	logger(array('step' => 'getUsers', 'status' => 'failure', 'error' => 'no users'));
}

# create the table on first run
function setUpDatabase() {
	global $dbh;
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

function getUsers() {
	global $config;
	# connnect to ldap
	$link_id = ldap_connect($config['ldap']['ldap_host']);
	$users = FALSE;
	if (ldap_set_option($link_id, LDAP_OPT_PROTOCOL_VERSION, 3)) {
		if (ldap_bind($link_id, $config['ldap']['ldap_bind_user'], $config['ldap']['ldap_bind_password'])) {
			$result_id = ldap_search($link_id, $config['ldap']['ldap_base_dn'], $config['ldap']['ldap_filter'],
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
function updateUsers($users) {
	global $config, $dbh, $updated;
	$totalUsers = $users['count'];
	$regularUsers = 0;
	$contractUsers = 0;
	$otherUsers = 0;
	$skip_ous = array();
	if ($skip_ou_list) {
		$skip_ous = split(',', $config['ldap']['ldap_skip_ou_list']);
	}
	foreach ($users as $key => $user) {
		if (is_int($key)) {
			foreach ($skip_ous as $ou) {
				if (strstr($user['dn'], "OU=$ou")) {
					logger(array('step' => 'updateUser', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'ou', 'dn' => $user['dn'], 'ou' => $ou));
					$totalUsers--;
					continue 2;
				}
			}
			if (empty($user['title']) || empty($user['department'])) {
				logger(array('step' => 'updateUser', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'attributes', 'dn' => $user['dn']));
				$totalUsers--;
				continue;
			}
			
			$type = getUserType($user['dn'], $user['cn']);
			if ($type == "Regular") {
				$regularUsers++;
			} elseif ($type == "Contractor") {
				$contractUsers++;
			} else {
				$otherUsers++;
			}
			
			logger(array('step' => 'updateUser', 'action' => 'pre_update', 'status' => 'success', 'dn' => $user['dn']));
			$mail = isset($user['mail'][0]) ? $user['mail'][0] : '';
			$location = isset($user['physicaldeliveryofficename'][0]) ? $user['physicaldeliveryofficename'][0] : '';
			$sth = $dbh->prepare("INSERT OR REPLACE INTO deathwatch"
				. " (id, dn, cn, title, department, location, mail, created, updated)"
				. " VALUES ((SELECT id FROM deathwatch WHERE dn = ?), ?, ?, ?, ?, ?, ?,"
				. " (SELECT created FROM deathwatch WHERE dn = ?), datetime(?, 'unixepoch'))");
			$sth->execute(array($user['dn'], $user['dn'], $user['cn'][0], $user['title'][0], $user['department'][0],
				$location, $mail, $user['dn'], $updated));
			logger(array('step' => 'updateUser', 'action' => 'post_update', 'status' => 'success', 'dn' => $user['dn']));
		}
	}
	
	return array($totalUsers, $regularUsers, $contractUsers, $otherUsers);
}

# any users that were not just updated are dead. mark them dead. send a notification.
function processDeadUsers() {
	global $config, $dbh, $updated;
	$result = $dbh->query("SELECT id, dn, cn, title, department, location, mail, created"
		. " FROM deathwatch WHERE dead = 0 AND updated < datetime($updated, 'unixepoch')");
	$dead_users = $result->fetchAll(PDO::FETCH_ASSOC);
	$count = 0;
	foreach ($dead_users as $dead_user) {
		$count++;
		# TODO: don't send goodbye w/o checking for errors
		$sth = $dbh->prepare("UPDATE deathwatch SET dead = 1 WHERE id = ?");
		$sth->execute(array($dead_user['id']));
		error_log (date('c') . " action=dead_user dn=\"{$dead_user['dn']}\"");
		$type = getUserType($dead_user['dn'], $dead_user['cn']);
		notifyHipchat(
			"goodbye {$dead_user['cn']} - $userType - ({$dead_user['mail']}), {$dead_user['title']} in {$dead_user['department']}"
				. " at {$dead_user['location']} joined on {$dead_user['created']}",
			"red");
	}
	
	return $count;
}

# any users with created time that is later than updated time are new. send a notification.
function processNewUsers() {
	global $config, $dbh;
	$result = $dbh->query("SELECT dn, cn, title, department, location, mail"
		. " FROM deathwatch WHERE dead = 0 AND updated <= created");
	$new_users = $result->fetchAll(PDO::FETCH_ASSOC);
	$count = 0;
	foreach ($new_users as $new_user) {
		$count++;
		error_log(date('c') . " action=new_user dn=\"{$new_user['dn']}\"");
		$type = getUserType($new_user['dn'], $new_user['cn']);
		notifyHipchat(
			"welcome {$new_user['cn']} - $userType - ({$new_user['mail']}), {$new_user['title']} in {$new_user['department']} at"
				. " {$new_user['location']}",
			"green");
	}
	return $count;
}

function notifyHipchat($message, $color) {
	global $config;
	$url = "{$config['hipchat']['hipchat_url']}/v2/room/{$config['hipchat_room']}/notification?auth_token={$config['hipchat']['hipchat_token']}";
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
	if ($result) {
		logger(array('step' => 'notifyHipchat', 'status' => 'failure', 'error' => $result));
	} elseif ($result === FALSE) {
		logger(array('step' => 'notifyHipchat', 'status' => 'failure', 'error' => 'notification failed'));
	}
}

function getUserType($dn, $cn) {
	global $config;
	if (strstr($dn, "OU=Standard") !== FALSE) {
		$type = "Regular";
	} elseif (strstr($dn, "OU=Contractor") !== FALSE) {
		$type = "Contractor";
	} else {
		$type = str_replace(",{$config['ldap']['ldap_base_dn']}", "", $dn);
		$type = str_replace("CN=$cn,", "", $type);
		logger(array('step' => 'getUserType', 'status' => 'failure', 'dn' => $dn, 'type' => $type));
	}
	
	return $type;
}

function logger($fields) {
	$event = date('c');
	foreach ($fields as $field => $value) {
		$event .= " $field=\"" . addcslashes($value, '"') . '"' ;
	}
	error_log($event);
}