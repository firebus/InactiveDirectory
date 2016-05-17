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
		$deadUsers = getAndMarkDeadUsers();
		$newUsers = getNewUsers();
		list($updatedUsers, $deadUsers, $newUsers) = getUpdatedUsers($deadUsers, $newUsers);
		sendUserNotifications($updatedUsers, $deadUsers, $newUsers);
		sendSummaryNotifications($updatedUsers, $deadUsers, $newUsers);
		if ($deadUsers || $newUsers || $updatedUsers) {
			notifyHipchat(
				"$totalUsers total users. $regularUsers regular, $contractUsers contractors, $otherUsers uncategorized.",
				"yellow");
		}
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
			"hired" DATETIME,
			"created" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			"updated" DATETIME,
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
				array('dn', 'cn', 'title', 'department', 'physicalDeliveryOfficeName', 'mail', 'hireDateCustom'));
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
	if ($config['ldap']['ldap_skip_ou_list']) {
		$skip_ous = split(',', $config['ldap']['ldap_skip_ou_list']);
	}
	foreach ($users as $key => $user) {
		if (is_int($key)) {
			foreach ($skip_ous as $ou) {
				if (strstr($user['dn'], "OU=$ou")) {
					logger(array('step' => 'updateUser', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'ou',
						'dn' => $user['dn'], 'ou' => $ou));
					$totalUsers--;
					continue 2;
				}
			}
			if (empty($user['title']) || empty($user['department'])) {
				logger(array('step' => 'updateUser', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'attributes',
					'dn' => $user['dn']));
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
			$hireDate = '';
			if (isset($user['hiredatecustom'][0])) {
				$hireDateParts = explode('/', $user['hiredatecustom'][0]);
				$hireDate = date('Y-m-d', mktime(0, 0, 0, $hireDateParts[0], $hireDateParts[1], $hireDateParts[2]));
			}	
			$sth = $dbh->prepare("INSERT OR REPLACE INTO deathwatch"
				. " (id, dn, cn, title, department, location, mail, hired, created, updated)"
				. " VALUES ((SELECT id FROM deathwatch WHERE dn = ?), ?, ?, ?, ?, ?, ?, date(?),"
				. " (SELECT created FROM deathwatch WHERE dn = ?), datetime(?, 'unixepoch'))");
			$sth->execute(array($user['dn'], $user['dn'], $user['cn'][0], $user['title'][0], $user['department'][0],
				$location, $mail, $hireDate, $user['dn'], $updated));
			logger(array('step' => 'updateUser', 'action' => 'post_update', 'status' => 'success', 'dn' => $user['dn']));
		}
	}
	
	return array($totalUsers, $regularUsers, $contractUsers, $otherUsers);
}

# any users that were not just updated are dead. mark them dead. send a notification.
function getAndMarkDeadUsers() {
	global $dbh, $updated;
	$result = $dbh->query("SELECT id, dn, cn, title, department, location, mail, hired"
		. " FROM deathwatch WHERE dead = 0 AND updated < datetime($updated, 'unixepoch')");
	$deadUsers = $result->fetchAll(PDO::FETCH_ASSOC);
	foreach ($deadUsers as $deadUser) {
		# TODO: don't send goodbye w/o checking for errors
		$sth = $dbh->prepare("UPDATE deathwatch SET dead = 1 WHERE id = ?");
		$sth->execute(array($deadUser['id']));
		logger(array('step' => 'getAndMarkDeadUsers', 'action' => 'mark', 'status' => 'success', 'dn' => $deadUser['dn']));
	}
	
	return $deadUsers;
}

# any users with created time that is later than updated time are new. send a notification.
function getNewUsers() {
	global $dbh;
	$result = $dbh->query("SELECT dn, cn, title, department, location, mail"
		. " FROM deathwatch WHERE dead = 0 AND updated <= created");
	$newUsers = $result->fetchAll(PDO::FETCH_ASSOC);
	foreach ($newUsers as $newUser) {
		logger(array('step' => 'getNewUsers', 'action' => 'get', 'status' => 'success', 'dn' => $newUser['dn']));
	}
	return $newUsers;
}

function getUpdatedUsers($deadUsers, $newUsers) {
	$updatedUsers = array();
	# If we have the same CN in both dead and new, we should log it as an update
	if ($deadUsers && $newUsers) {
		foreach ($deadUsers as $deadKey => $deadUser) {
			foreach ($newUsers as $newKey => $newUser) {
				if ($deadUser['cn'] == $newUser['cn']
					|| $deadUser['mail'] == $newUser['mail']) {
					$updatedUsers[] = array(
						'dead' => $deadUser,
						'new' => $newUser,
					);
					unset($deadUsers[$deadKey]);
					unset($newUsers[$newKey]);
					logger(array('step' => 'findUpdatedUsers', 'action' => 'get', 'status' => 'success', 'dn' => $newUser['dn']));
				}
			}
		}
	}
	
	return array($updatedUsers, $deadUsers, $newUsers);
}

function sendUserNotifications($updatedUsers, $deadUsers, $newUsers) {
	foreach ($updatedUsers as $updatedUser) {
		$type = getUserType($updatedUser['new']['dn'], $updatedUser['new']['cn']);
		$notification = "updated {$updatedUser['new']['cn']}, $type, {$updatedUser['new']['mail']},"
		. " {$updatedUser['new']['title']} in {$updatedUser['new']['department']} at {$updatedUser['new']['location']}\\n";
		$notification .= "dn was {$updatedUser['dead']['dn']} now {$updatedUser['new']['dn']}\\n";
		foreach (array('cn', 'mail', 'title', 'department', 'location') as $field) {
			if ($updatedUser['dead'][$field] != $updatedUser['new'][$field]) {
				$notification .= "$field was {$updatedUser['dead'][$field]} now {$updatedUser['new'][$field]}\\n";
			}
		}
			
		notifyHipchat($notification, "yellow");
	}
	foreach ($deadUsers as $deadUser) {
		$type = getUserType($deadUser['dn'], $deadUser['cn']);
		notifyHipchat(
			"goodbye {$deadUser['cn']}, $type, {$deadUser['mail']}, {$deadUser['title']} in {$deadUser['department']}"
				. " at {$deadUser['location']} was hired on {$deadUser['hired']}",
			"red");
	}
	foreach ($newUsers as $newUser) {
		$type = getUserType($newUser['dn'], $newUser['cn']);
		notifyHipchat(
			"welcome {$newUser['cn']}, $type, {$newUser['mail']}, {$newUser['title']} in {$newUser['department']} at"
				. " {$newUser['location']}",
			"green");
	}
}

function sendSummaryNotifications($updatedUsers, $deadUsers, $newUsers) {
	# Notification summaries
	if ($updatedUsers) {
		notifyHipchat(count($updatedUsers) . " users updated.", "yellow");
	}
	if ($deadUsers) {
		notifyHipchat(count($deadUsers) . " users removed.", "red");
	}
	if ($newUsers) {
		notifyHipchat(count($newUsers) . " users added.", "green");
	}
}

function notifyHipchat($message, $color) {
	global $config;
	$url = "{$config['hipchat']['hipchat_url']}/v2/room/{$config['hipchat']['hipchat_room']}/notification?"
		. "auth_token={$config['hipchat']['hipchat_token']}";
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