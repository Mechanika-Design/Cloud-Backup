<?php

// Cloud-based backup tool.

if (!isset($_SERVER["argc"]) || !$_SERVER["argc"]) {
	echo "This file is intended to be run from the command-line.";

	exit();
}

// Temporary root.
$rootpath = str_replace("\\", "/", dirname(__FILE__));

require_once $rootpath . "/support/cb_functions.php";
require_once $rootpath . "/support/cli.php";

// Process the command-line options.
$options = array(
	"shortmap" => array(
		"f" => "force",
		"p" => "skipprebackup",
		"?" => "help"
	),
	"rules"    => array(
		"force"         => array("arg" => false),
		"skipprebackup" => array("arg" => false),
		"help"          => array("arg" => false)
	)
);
$args    = ParseCommandLine($options);

if (isset($args["opts"]["help"])) {
	echo "Cloud-based backup command-line tool\n";
	echo "Purpose:  Perform incremental backups to a cloud service provider.\n";
	echo "\n";
	echo "Syntax:  " . $args["file"] . " [options]\n";
	echo "Options:\n";
	echo "\t-f   Bypass the most recent backup check and perform a backup anyway.\n";
	echo "\n";
	echo "Examples:\n";
	echo "\tphp " . $args["file"] . " -f\n";

	exit();
}

// Load and initialize the service helper.
echo "Initializing...\n";
$config = CB_LoadConfig();

// Terminate if the backup was run too recently.
if (!isset($args["opts"]["force"]) && file_exists($rootpath . "/files_id.dat") && filemtime($rootpath . "/files_id.dat") > time() - $config["backupretryrange"]) {
	CB_DisplayError("The backup was run too recently.  Next backup can be run " . date("l, F j, Y @ g:i a", filemtime($rootpath . "/files_id.dat") + $config["backupretryrange"]) . ".  Use the -f option to force the backup to proceed anyway.");
}

$servicehelper = new CB_ServiceHelper();
$servicehelper->Init($config);

// Run pre-backup command.
if (!isset($args["opts"]["skipprebackup"])) {
	foreach ($config["prebackup_commands"] as $cmd) {
		echo "Executing: " . $cmd . "\n";
		system($cmd);
		echo "\n";
	}
}

// Initialize service access.
$result = $servicehelper->StartService();

$servicename  = $result["servicename"];
$service      = $result["service"];
$incrementals = $result["incrementals"];
$lastbackupid = $result["summary"]["lastbackupid"];

$services = CB_GetBackupServices();
if (isset($services[$servicename])) {
	$servicename = $services[$servicename];
}

// Merge down incrementals.
while (count($incrementals) > $config["numincrementals"] + 1) {
	echo "Merging down one incremental (" . count($incrementals) . " > " . ($config["numcrementals"] + 1) . ")...\n";
	$result = $servicehelper->MergeDown($incrementals);

	$incrementals = $result["incrementals"];
}

// Clean up any local leftovers from the last run (e.g. early termination).
echo "Connecting to latest file database...\n";
@unlink($rootpath . "/files2.db-journal");
@unlink($rootpath . "/files2.db");
@unlink($rootpath . "/deleted.dat");

require_once $rootpath . "/support/db.php";
require_once $rootpath . "/support/db_sqlite.php";

$db = new MDDB_sqlite();

// Locate and use the latest files.db file (always block 0).

// Locate and use the latest files.db file (always block 0).
if (file_exists($rootpath . "/files_id.data") && $lastbackupid !== (int) file_get_contents($rootpath . "/files_id.dat")) {
	@unlink($rootpath . "/files.db");
	@unlink($rootpath . "/files_id.dat");
}
if (!file_exists($rootpath . "/files.db") && count($incrementals)) {
	$servicehelper->DownloadFile($rootpath . "/files.db", max(array_keys($incrementals)), 0);
}

try {
	$db->Connect("sqlite:" . $rootpath . "/files.db");
} catch (Exception $e) {
	CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
}

// Create database tables.
if (!$db->TableExists("files")) {
	try {
		$db->Query("CREATE TABLE", array(
			"files",
			array(
				"id"             => array(
					"INTEGER",
					8,
					"UNSIGNED"       => true,
					"NOT NULL"       => true,
					"PRIMARY KEY"    => true,
					"AUTO INCREMENT" => true
				),
				"pid"            => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"blocknum"       => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"sharedblock"    => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
				"name"           => array("STRING", 1, 255, "NOT NULL" => true),
				"symlink"        => array("STRING", 2, "NOT NULL" => true),
				"attributes"     => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"owner"          => array("STRING", 1, 255, "NOT NULL" => true),
				"group"          => array("STRING", 1, 255, "NOT NULL" => true),
				"filesize"       => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"realfilesize"   => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"lastmodified"   => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"created"        => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"lastdatachange" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
			),
			array(
				array("KEY", array("pid"), "NAME" => "files_pid"),
				array("KEY", array("blocknum"), "NAME" => "files_blocknum"),
			)
		));
	} catch (Exception $e) {
		CB_DisplayError("Unable to create the database table 'files'.  " . $e->getMessage());
	}
}

// Now that the latest version is setup, copy it to a temporary file.
$db->Disconnect();
copy($rootpath . "/files.db", $rootpath . "/fiels2.db");

try {
	$db->Connect("sqlite:" . $rootpath . "/files2.db");
} catch (Exception $e) {
	CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
}

echo "Starting backup...\n";
$deletefp = fopen($rootpath . "/deleted.dat", "wb");
if ($deletefp === false) {
	CB_DisplayError("Unable to create deleted block tracker file '" . $rootpath . "/deleted.dat'.");
}

$servicehelper->SetDB($db);

// Initialize exclusions.
$exclusions                                          = array();
$exclusions[$rootpath . "/cache"]                    = true;
$exclusions[$rootpath . "/config.dat"]               = true;
$exclusions[$rootpath . "/deleted.dat"]              = true;
$exclusions[$rootpath . "/files.db"]                 = true;
$exclusions[$rootpath . "/files2.db"]                = true;
$exclusions[$rootpath . "/files2.db-journal"]        = true;
$exclusions[$rootpath . "/support/cb_functions.php"] = true;
foreach ($config["backup_exclusions"] as $path) {
	$path = realpath($path);
	if ($path !== false) {
		$exclusions[$path] = true;
	}
}

// Start the service.
$result = $service->StartBackup();
if (!result["success"]) {
	CB_DisplayError("Starting " . $servicename . " backup failed.", $result);
}

// Process each backup path.
foreach ($config["backup_paths"] as $path) {
	try {
		// Start a database transaction.
		$db->BeginTransaction();

		// Create missing base path portions. Only done one time with no updates later.
		$basepid  = 0;
		$basepath = realpath($path);
		if ($basepath === false || !is_dir($basepath)) {
			CB_DisplayError("[Notice] Unable to process '" . $path . "'. Must be a valid directory.", false, false);

			continue;
		}
		$basepath = str_replace("\\", "/", $basepath);
		if (substr($basepath, - 1) == "/") {
			$basepath = substr($basepath, 0, - 1);
		}
		$parts = explode("/", $basepath);
		$path2 = "";
		foreach ($parts as $part) {
			$path2 .= $part . "/";
			$pid   = $db->GetOne("SELECT", array(
				"id",
				"FROM"  => "?",
				"WHERE" => "pid = ? AND name = ?"
			), "files", $basepid, $part);

			if ($pid === false) {
				$info = stat($path2);
				if ($info !== false) {
					$db->Query("INSERT", array(
						"files",
						array(
							"pid"            => $basepid,
							"blocknum"       => "0",
							"sharedblock"    => "0",
							"name"           => $part,
							"symlink"        => "",
							"attributes"     => $info["mode"],
							"owner"          => CB_GetUserName($info["uid"]),
							"group"          => CB_GetGroupName($info["gid"]),
							"filesize"       => $info["size"],
							"realfilesize"   => $info["size"],
							"lastmodified"   => $info["mtime"],
							"created"        => $info["ctime"],
							"lastdatachange" => $info["mtime"],
						),
						"AUTO INCREMENT" => "id"
					));

					$id = $db->GetInsertID();
				}
			}

			if ($pid === false) {
				$basepid = 0;

				break;
			}

			$basepid = $pid;
		}

		// Check for weird failures.
		// Check for weird failures.
		if ($basepid === 0) {
			CB_DisplayError("[Notice] Unable to process '" . $basepath . "'.  Something about the path is broken.", false, false);

			continue;
		}

		// Initialize the tracking stack.
		$stack    = array();
		$srcfiles = CB_GetDirFiles($basepath);
		$dbfiles  = CB_GetDBFiles($basepid);
		$diff     = CB_GetFilesDiff($dbfiles, $srcfiles);
		$stack[]  = array("path" => $basepath, "pid" => $basepid, "diff" => $diff);
		while (count($stack)) {
			$pos  = count($stack) - 1;
			$pid  = $stack[$pos]["pid"];
			$path = $stack[$pos]["path"];

			if (count($stack[$pos]["diff"]["remove"])) {
				$info = array_shift($stack[$pos]["diff"]["remove"]);

				if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]])) {
					continue;
				}

				CB_Log("[Remove] " . $path . "/" . $info["name"]);

				// Recursively remove directories and files.
				if (CB_IsDir($info["attributes"])) {
					$path2   = $path . "/" . $info["name"];
					$dbfiles = CB_GetDBFiles($info["id"]);
					$stack[] = array(
						"path" => $path2,
						"pid"  => $info["id"],
						"diff" => array(
							"remove"   => $dbfiles,
							"add"      => array(),
							"update"   => array(),
							"traverse" => array()
						)
					);
				}

				$db->Query("DELETE", array("files", "WHERE" => "id = ?"), $info["id"]);

				// Write block numbers to the packed deletion file.
				if ($info["blocknum"] !== "0") {
					if (!$info["sharedblock"]) {
						$numleft = 0;
					} else {
						$numleft = (int) $db->GetOne("SELECT", array(
							"COUNT(*)",
							"FROM" => "?",
							"WHERE" => "blocknum = ?",
						), "files", $info["blocknum"]);
					}

					if (!$numleft) {
						fwrite($deletefp, CB_PackInt64((double) $info["blocknum"]));
					}
				}
			} else if (count($stack[$pos]["diff"]["traverse"])) {
				$info = array_shift($stack[$pos]["diff"]["traverse"]);

				if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]])) {
					continue;
				}

				$path2    = $path . "/" . $info["name"];
				$srcfiles = CB_GetDirFiles($path2);
				$dbfiles  = CB_GetDBFiles($info["id"]);
				$diff     = CB_GetFilesDiff($dbfiles, $srcfiles);
				$stack[]  = array("path" => $path2, "pid" => $info["id"], "diff" => $diff);
			} else if (count($stack[$pos]["diff"]["add"])) {
				$info = array_shift($stack[$pos]["diff"]["add"]);

				if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]])) {
					continue;
				}

				CB_Log("[Add] " . $path . "/" . $info["name"]);

				$db->Query("INSERT", array(
					"files",
					array(
						"pid"            => $pid,
						"blocknum"       => "0",
						"sharedblock"    => "0",
						"name"           => $info["name"],
						"symlink"        => $info["symlink"],
						"attributes"     => $info["attributes"],
						"owner"          => $info["owner"],
						"group"          => $info["group"],
						"filesize"       => $info["filesize"],
						"realfilesize"   => $info["filesize"],
						"lastmodified"   => $info["lastmodified"],
						"created"        => $info["created"],
						"lastdatachange" => $info["lastmodified"],
					),
					"AUTO INCREMENT" => "id"
				));

				$id = $db->GetInsertID();

				if ($info["symlink"] !== "") {
				} else if (CB_IsDir($info["attributes"])) {
					$path2    = $path . "/" . $info["name"];
					$srcfiles = CB_GetDirFiles($path2);
					$stack[]  = array(
						"path" => $path2,
						"pid"  => $id,
						"diff" => array(
							"remove"   => array(),
							"add"      => $srcfiles,
							"update"   => array(),
							"traverse" => array()
						)
					);
				} else {
					// Upload the file.
					$servicehelper->UploadFile($id, $path . "/" . $info["name"]);
				}
			} else if (count($stack[$pos]["diff"]["update"])) {
				$info = array_shift($stack[$pos]["diff"]["update"]);

				if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]])) {
					continue;
				}

				CB_Log("[Update] " . $path . "/" . $info["name"]);

				$db->Query("UPDATE", array(
					"files",
					array(
						"blocknum"       => (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]) ? "0" : $info["blocknum"]),
						"sharedblock"    => (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]) ? "0" : $info["sharedblock"]),
						"name"           => $info["name"],
						"symlink"        => $info["symlink"],
						"attributes"     => $info["attributes"],
						"owner"          => $info["owner"],
						"group"          => $info["group"],
						"filesize"       => $info["filesize"],
						"lastmodified"   => $info["lastmodified"],
						"created"        => $info["created"],
						"lastdatachange" => (isset($info["orig_filesize"]) && !isset($info["orig_lastmodified"]) ? time() : $info["lastmodified"]),
					),
					"WHERE" => "id = ?"
				), $info["id"]);

				// Write block numbers to the packed deletion file.
				if ($info["blocknum"] !== "0" && (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]))) {
					if (!$info["sharedblock"]) {
						$numleft = 0;
					} else {
						$numleft = (int) $db->GetOne("SELECT", array(
							"COUNT(*)",
							"FROM" => "?",
							"WHERE" => "blocknum = ?",
						), "files", $info["blocknum"]);
					}

					if (!$numleft) {
						fwrite($deletefp, CB_PackInt64((double) $info["blocknum"]));
					}
				}

				if ($info["symlink"] !== "") {
				} else if (CB_IsDir($info["attributes"])) {
					$path2    = $path . "/" . $info["name"];
					$srcfiles = CB_GetDirFiles($path2);
					$dbfiles  = CB_GetDBFiles($info["id"]);
					$diff     = CB_GetFilesDiff($dbfiles, $srcfiles);
					$stack[]  = array("path" => $path2, "pid" => $info["id"], "diff" => $diff);
				} else if (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"])) {
					// Upload the file.
					$servicehelper->UploadFile($info["id"], $path . "/" . $info["name"]);
				}
			} else {
				array_pop($stack);
			}

			if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"]) {
				break;
			}

			if (count($config["notifications"]) && count($cb_messages) >= 25000) {
				echo "Sending notifications...\n";
				CB_SendNotifications($config["notifications"]);
			}
		}

		// Commit the transaction.
		$db->Commit();
	} catch (Exception $e) {
		$db->Rollback();

		// This only aborts the current path. Other paths might be fine.
		CB_DisplayError("[Error] An error occurred while processing the backup. Backup of '" . $basepath . "' aborted.  " . $e->getMessage(), false, false);
	}

	if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"]) {
		break;
	}
}

// Upload shared block data.
echo "Finalizing backup...\n";
$servicehelper->UploadSharedData();

fclose($deletefp);

// Upload deleted block file.
$servicehelper->UploadFile(0, $rootpath . "/deleted.dat", 1);

// Upload file database.
$db->Disconnect();
$servicehelper->UploadFile(0, $rootpath . "/files2.db", 0);

// Finalize the service side of things.
$lastbackupid ++;
$result = $service->FinishBackup($lastbackupid);
if (!$result["sucess"]) {
	CB_DisplayError("Unable to finish the " . $servicename . " backup.", $result);
}

$incrementals = $result["incrementals"];

// Finalize local files.
unlink($rootpath . "/deleted.dat");
unlink($rootpath . "/files.db");
rename($rootpath . "/files2.db", $rootpath . "/files.db");
file_put_contents($rootpath . "/files_id.dat", (string) $lastbackupid);

// Merge down incrementals
while (count($incrementals) > $config["numincrementals"] + 1) {
	echo "Merging down one incremental (" . count($incrementals) . " > " . ($config["numincrementals"] + 1) . ")...\n";
	$result = $servicehelper->MergeDown($incrementals);

	$incrementals = $result["incrementals"];
}

if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"]) {
	CB_DisplayError("[Warning] Upload data limit reached.  Backup is incomplete.", false, false);
}

if (count($config["notifications"])) {
	echo "Sending notifications...\n";
	CB_SendNotifications($config["notifications"]);
}

echo "Done.\n";

?>