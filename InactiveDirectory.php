<?php

global $config;
$config = parse_ini_file('config.ini', TRUE);

global $updated;
$updated = time();

# connect to database
global $dbh;
$dbh = new PDO("sqlite:" . __DIR__ . '/deathwatch.sq3');

logger(array('step' => 'main', 'action' => 'start'));
$firstRun = setupDatabase();
if ($firstRun) {
	logger(array('step' => 'setupDatabase', 'action' => 'first_run', 'status' => 'success'));
}

$users = getUsers();
if ($users) {
	logger(array('step' => 'getUsers', 'status' => 'success', 'count' => $users['count']));
	list($totalUsers, $regularUsers, $contractUsers, $internUsers, $otherUsers) = updateUsers($users);
	if ($firstRun) {
		notify("first run. $count users.", "yellow");
	} else {
		$deadUsers = getAndMarkDeadUsers();
		$newUsers = getNewUsers();
		list($updatedUsers, $deadUsers, $newUsers) = getUpdatedUsers($deadUsers, $newUsers);
		sendUserNotifications($updatedUsers, $deadUsers, $newUsers);
		sendSummaryNotifications($updatedUsers, $deadUsers, $newUsers);
		notify("$totalUsers total users. $regularUsers regular, $contractUsers contractors, $internUsers interns,"
			. " $otherUsers uncategorized.", "yellow");
	}
} else {
	logger(array('step' => 'getUsers', 'status' => 'failure', 'error' => 'no users'));
}
logger(array('step' => 'main', 'action' => 'finish'));

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
			"new_id" INTEGER,
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
				array('dn', 'cn', 'title', 'department', 'physicalDeliveryOfficeName', 'mail', 'hireDateCustom', 'whenCreated'));
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
	$internUsers = 0;
	$otherUsers = 0;
	$skip_ous = array();
	if ($config['ldap']['ldap_skip_ou_list']) {
		$skip_ous = explode(',', $config['ldap']['ldap_skip_ou_list']);
	}
	$dbh->query('BEGIN EXCLUSIVE TRANSACTION');
	foreach ($users as $key => $user) {
		if (is_int($key)) {
			foreach ($skip_ous as $ou) {
				if (strstr($user['dn'], "OU=$ou")) {
					logger(array('step' => 'updateUsers', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'ou',
						'dn' => $user['dn'], 'ou' => $ou));
					$totalUsers--;
					continue 2;
				}
			}
			if (empty($user['title']) || empty($user['department'])) {
				logger(array('step' => 'updateUsers', 'action' => 'skip_user', 'status' => 'success', 'reason' => 'attributes',
					'dn' => $user['dn']));
				$totalUsers--;
				continue;
			}
			
			$type = getUserType($user['dn'], $user['cn'], $user['title'][0]);
			switch ($type) {
				case 'Intern':
					$internUsers++;
					break;
				case 'Regular':
					$regularUsers++;
					break;
				case 'Contractor':
					$contractUsers++;
					break;
				default:
					$otherUsers++;
			}
			
			logger(array('step' => 'updateUsers', 'action' => 'pre_update', 'status' => 'success', 'dn' => $user['dn']));
			$mail = isset($user['mail'][0]) ? $user['mail'][0] : '';
			$location = isset($user['physicaldeliveryofficename'][0]) ? $user['physicaldeliveryofficename'][0] : '';
			$hireDate = calculateHireDate($user);
			$sth = $dbh->prepare("INSERT OR REPLACE INTO deathwatch"
				. " (id, dn, cn, title, department, location, mail, hired, created, updated)"
				. " VALUES ((SELECT id FROM deathwatch WHERE dn = ?), ?, ?, ?, ?, ?, ?, date(?),"
				. " (SELECT created FROM deathwatch WHERE dn = ? AND dead = 0), datetime(?, 'unixepoch'))");
			$sth->execute(array($user['dn'], $user['dn'], $user['cn'][0], $user['title'][0], $user['department'][0],
				$location, $mail, $hireDate, $user['dn'], $updated));
		}
	}
	$dbh->query('COMMIT TRANSACTION');
	
	logger(array('step' => 'updateUsers', 'action' => 'finish', 'status' => 'success', 'total' => $totalUsers,
		'regular' => $regularUsers, 'contractor' => $contractUsers, 'intern' => $internUsers, 'other' => $otherUsers));
	return array($totalUsers, $regularUsers, $contractUsers, $internUsers, $otherUsers);
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
	$result = $dbh->query("SELECT id, dn, cn, title, department, location, mail"
		. " FROM deathwatch WHERE dead = 0 AND updated <= created");
	$newUsers = $result->fetchAll(PDO::FETCH_ASSOC);
	foreach ($newUsers as $newUser) {
		logger(array('step' => 'getNewUsers', 'action' => 'get', 'status' => 'success', 'dn' => $newUser['dn']));
	}
	return $newUsers;
}

function getUpdatedUsers($deadUsers, $newUsers) {
	global $dbh;
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
					$sth = $dbh->prepare("UPDATE deathwatch SET new_id = ? WHERE id = ?");
					$sth->execute(array($newUser['id'], $deadUser['id']));
					logger(array('step' => 'findUpdatedUsers', 'action' => 'get', 'status' => 'success', 'dn' => $newUser['dn']));
				}
			}
		}
	}
	
	return array($updatedUsers, $deadUsers, $newUsers);
}

function sendUserNotifications($updatedUsers, $deadUsers, $newUsers) {
	foreach ($updatedUsers as $updatedUser) {
		$type = getUserType($updatedUser['new']['dn'], $updatedUser['new']['cn'], $updatedUser['new']['title']);
		$notification = "updated {$updatedUser['new']['cn']}, $type, {$updatedUser['new']['mail']},"
		. " {$updatedUser['new']['title']} in {$updatedUser['new']['department']} at {$updatedUser['new']['location']}<br>";
		foreach (array('cn', 'mail', 'title', 'department', 'location') as $field) {
			if ($updatedUser['dead'][$field] != $updatedUser['new'][$field]) {
				$notification .= "$field was {$updatedUser['dead'][$field]} now {$updatedUser['new'][$field]}<br>";
			}
		}
		notify($notification, "yellow", "update");
		logger(array(
			'step' => 'sendUserNotifications', 'action' => 'updated', 'dn' => $updatedUser['new']['dn']));
	}
	foreach ($deadUsers as $deadUser) {
		$type = getUserType($deadUser['dn'], $deadUser['cn'], $deadUser['title']);
		$notification = "goodbye {$deadUser['cn']}, $type, {$deadUser['mail']}, "
		. "{$deadUser['title']} in {$deadUser['department']} at {$deadUser['location']} was hired on {$deadUser['hired']}";
		notify($notification, "red", "goodbye");
		logger(array(
			'step' => 'sendUserNotifications', 'action' => 'dead', 'dn' => $deadUser['dn']));
	}
	foreach ($newUsers as $newUser) {
		$type = getUserType($newUser['dn'], $newUser['cn'], $newUser['title']);
		$notification = "welcome {$newUser['cn']}, $type, {$newUser['mail']}, {$newUser['title']} in {$newUser['department']} at"
				. " {$newUser['location']}";
		notify($notification, "green", "welcome");
		logger(array(
			'step' => 'sendUserNotifications', 'action' => 'new', 'dn' => $newUser['dn']));
	}
}

function sendSummaryNotifications($updatedUsers, $deadUsers, $newUsers) {
	logger(array('step' => 'sendSummaryNotifications', 'action' => 'summary', 'updated' => count($updatedUsers),
		'dead' => count($deadUsers), 'new' => count($newUsers)));
	# Notification summaries
	if ($updatedUsers) {
		notify(count($updatedUsers) . " users updated.", "yellow", "update");
	}
	if ($deadUsers) {
		notify(count($deadUsers) . " users removed.", "red", "goodbye");
	}
	if ($newUsers) {
		notify(count($newUsers) . " users added.", "green", "welcome");
	}
}

function notify($message, $color, $type) {
	global $config;
	$result = TRUE;
	
	echo "$message\n";
	
	if ($config['hipchat']['hipchat_enabled']
		&& ($config['hipchat']['hipchat_goodbyes'] || $type != "goodbye")) {
		$result = '';
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
			logger(array('step' => 'notify', 'status' => 'failure', 'error' => $result));
			$result = FALSE;
		} elseif ($result === FALSE) {
			logger(array('step' => 'notify', 'status' => 'failure', 'error' => 'notification failed'));
			$result = FALSE;
		}
	}
	return $result;
}

function getUserType($dn, $cn, $title) {
	global $config;
	if (strpos($title, 'Intern') !== FALSE) {
		$type = 'Intern';
	} elseif (strpos($dn, "OU=Standard") !== FALSE) {
		$type = "Regular";
	} elseif (strpos($dn, "OU=Contractors") !== FALSE) {
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

function calculateHireDate($user) {
	$hireDate = '';
	if (isset($user['hiredatecustom'][0])) {
		$hireDateParts = explode('/', $user['hiredatecustom'][0]);
		$hireDate = date('Y-m-d', mktime(0, 0, 0, $hireDateParts[0], $hireDateParts[1], $hireDateParts[2]));
		logger(array('step' => 'calculateHireDate', 'action' => 'hiredatecustom', 'date' => $hireDate, 'ldap' => $user['hiredatecustom'][0]));
	} elseif (isset($user['whencreated'][0])) {
		$hireDate = substr($user['whencreated'][0], 0, 4) . '-' . substr($user['whencreated'][0], 4, 2) . '-' . substr($user['whencreated'][0], 6, 2);
		logger(array('step' => 'calculateHireDate', 'action' => 'whencreated', 'date' => $hireDate, 'ldap' => $user['whencreated'][0]));
	}

	return $hireDate;
}