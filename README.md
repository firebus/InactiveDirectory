# InactiveDirectory

InactiveDirectory is a script plus a datastore that watches an LDAP directory and sends notifications when user records are added or removed.

## Requirements

You'll need PHP and sqlite3. On ubuntu 12.04 I found I needed to install php5-ldap, php5-sqlite, php5-curl

## Installation

* Copy config.ini.example to config.ini
* Edit config.ini. Most of the options are self-explanatory (I hope), but some maybe not:
	* ldap.ldap_filter: an ldap filter that should return a list of users. 
	  It can be as simple as an OU or objectCategory, or can be a complicated filter designed to exclude invalid records
	* ldap.ldap_skip_ou_list: A comma-separated (sorry) list of OUs that should be excluded from the records returned by the 
	  filter. Don't include "OU=". We'll search DNs returned for OUs with these names, and will ignore any that match