<?php

// Cloud-based backup support functions.

require_once $rootpath . "/support/random.php";

$cb_messages = array();
function CB_Log($msg) {
	global $cb_messages;

	$cb_messages[] = $msg . "\n";
	echo $msg . "\n";
}

function CB_DisplayError($msg, $result = false, $exit = true) {
	ob_start();

	CB_Log(($exit ? "[Error] " : "") . $msg);

	if ($result !== false) {
		if (isset($result["error"])) {
			CB_Log("[Error] " . $result["error"] . " (" . $result["errorcode"] . ")");
		}
		if (isset($result["info"])) {
			var_dump($result["info"]);
		}
	}

	fwrite(STDERR, ob_get_contents());
	ob_end_clean();

	if ($exit) {
		exit();
	}
}

function CB_SendNotificationEmail($notificationinfo, $htmlmsg, $textmsg) {
	global $rootpath;

	require_once $rootpath . "/support/smtp.php";

	$headers     = SMTP::GetUserAgent("Thunderbird");
	$smtpoptions = array(
		"headers"     => $headers,
		"htmlmessage" => $htmlmsg,
		"textmessage" => $textmsg,
		"server"      => $notificationinfo["server"],
		"port"        => $notificationinfo["port"],
		"secure"      => $notificationinfo["secure"],
		"username"    => $notificationinfo["username"],
		"password"    => $notificationinfo["password"]
	);

	$fromaddr = $notificationinfo["from"];
	$subject  = $notificationinfo["subject"];
	foreach ($notificationinfo["recipients"] as $toaddr) {
		// SMTP only.  No POP before SMTP support.
		$result = SMTP::SendEmail($fromaddr, $toaddr, $subject, $smtpoptions);
		if (!$result["success"]) {
			CB_DisplayError("Unable to send notification e-mail from '" . $fromaddr . "' to '" . $toaddr . "'.", $result, false);
		}
	}
}

function CB_SendNotifications($notifications) {
	global $cb_messages;

	foreach ($notifications as $notificationinfo) {
		$filter = $notificationinfo["filter"];
		if ($filter === "") {
			$messages = $cb_messages;
		} else {
			$messages = array();
			foreach ($cb_messages as $line) {
				if (preg_match($filter, $line)) {
					$messages[] = htmlspecialchars($line);
				}
			}
		}

		if (count($messages)) {
			$htmlmessage = "<html><body>" . str_replace("\n", "<br />\n", htmlspecialchars(implode("\n", $messages))) . "<br />\n</body></html>";
			$textmessage = implode("\n", $messages) . "\n";

			CB_SendNotificationEmail($notificationinfo, $htmlmessage, $textmessage);
		}
	}

	$cb_messages = array();
}

function CB_SendNotificationsOnExit($notifications) {
	register_shutdown_function("CB_SendNotifications", $notifications);
}

function CB_ReleaseExclusiveLock($fp) {
	flock($fp, LOCK_UN);
	fclose($fp);
}

function CB_ExclusiveLock() {
	$fp = fopen(__FILE__, "rb");
	if (!flock($fp, LOCK_EX | LOCK_NB)) {
		CB_DisplayError("Unable to acquire exclusive lock.");
	}
	register_shutdown_function("CB_ReleaseExclusiveLock", $fp);
}

function CB_LoadConfig() {
	global $rootpath;

	if (file_exists($rootpath . "/config.dat")) {
		$result = json_decode(file_get_contents($rootpath . "/config.dat"), true);
	} else {
		$result = array();
	}
	if (!is_array($result)) {
		$result = array();
	}

	return $result;
}

function CB_SaveConfig($config) {
	global $rootpath;

	file_put_contents($rootpath . "/config.dat", json_encode($config, JSON_PRETTY_PRINT));
	@chmod($rootpath . "/config.dat", 0660);
}

function CB_GetBackupServices() {
	$result = array(
		"opendrive" => "OpenDrive"
	);

	return $result;
}

$cb_usernames = array();
function CB_GetUserName($uid) {
	global $cb_usernames;

	if (!function_exists("posix_getpwuid")) {
		return "";
	}

	if (!isset($cb_usernames[$uid])) {
		$user = posix_getpwuid($uid);
		if ($user === false || !is_array($user)) {
			$cb_usernames[$uid] = "";
		} else {
			$cb_usernames[$uid] = $user["name"];
		}
	}

	return $cb_usernames[$uid];
}

$cb_groupnames = array();
function CB_GetGroupName($gid) {
	global $cb_groupnames;

	if (!function_exists("posix_getgrgid")) {
		return "";
	}

	if (!isset($cb_groupnames[$gid])) {
		$group = posix_getgrgid($gid);
		if ($group === false || !is_array($group)) {
			$cb_groupnames[$gid] = "";
		} else {
			$cb_groupnames[$gid] = $group["name"];
		}
	}

	return $cb_groupnames[$gid];
}

function CB_GetDirFiles($path) {
	if (substr($path, - 1) === "/") {
		$path = substr($path, 0, - 1);
	}

	$result = array();
	$dir    = @opendir($path);
	if ($dir) {
		while (($file = readdir($dir)) !== false) {
			if ($file !== "." && $file !== "..") {
				// The lstat() call can fail with really long paths (> 260 bytes).
				$info = @lstat($path . "/" . $file);
				if ($info !== false) {
					$result[$file] = array(
						"name"         => $file,
						"symlink"      => (is_link($path . "/" . $file) ? readlink($path . "/" . $file) : ""),
						"attributes"   => $info["mode"],
						"owner"        => CB_GetUserName($info["uid"]),
						"group"        => CB_GetGroupName($info["gid"]),
						"filesize"     => (string) $info["size"],
						"lastmodified" => (string) $info["mtime"],
						"created"      => (string) $info["ctime"],
					);
				}
			}
		}

		closedir($dir);
	}

	uksort($result, "strnatcasecmp");

	return $result;
}

function CB_GetDBFiles($pid, $fordiff = true) {
	global $db;

	$result = array();

	$result2 = $db->Query("SELECT", array(
		"*",
		"FROM"  => "?",
		"WHERE" => "pid = ?",
	), "files", $pid);

	while ($row = $result2->NextRow()) {
		$result[$row->name] = array(
			"id"           => $row->id,
			"blocknum"     => $row->blocknum,
			"sharedblock"  => (int) $row->sharedblock,
			"name"         => $row->name,
			"symlink"      => $row->symlink,
			"attributes"   => (int) $row->attributes,
			"owner"        => $row->owner,
			"group"        => $row->group,
			"filesize"     => ($fordiff ? $row->filesize : $row->realfilesize),
			"lastmodified" => $row->lastmodified,
			"created"      => $row->created,
		);
	}

	uksort($result, "strnatcasecmp");

	return $result;
}

function CB_GetFilesDiff($orig, $new) {
	$result = array("remove" => array(), "add" => array(), "update" => array(), "traverse" => array());
	foreach ($orig as $id => $val) {
		if (!isset($new[$id])) {
			$result["remove"][$id] = $val;
		} else if (is_array($val) && is_array($new[$id])) {
			$matches = true;
			foreach ($val as $key => $val2) {
				if (!isset($new[$id][$key])) {
					$new[$id][$key] = $val2;
				} else if ($val2 !== $new[$id][$key]) {
					$new[$id]["orig_" . $key] = $val2;

					$matches = false;
				}
			}

			if (!$matches) {
				$result["update"][$id] = $new[$id];
			} else if ($new[$id]["symlink"] === "" && CB_IsDir($new[$id]["attributes"])) {
				$result["traverse"][$id] = $new[$id];
			}
		}
	}

	foreach ($new as $id => $val) {
		if (!isset($orig[$id])) {
			$result["add"][$id] = $val;
		}
	}

	return $result;
}

function CB_IsDir($attrs) {
	// S_IFMT -  0170000
	// S_IFDIR - 0040000

	return (($attrs & 0170000) === 0040000);
}

function CB_UnpackInt($data) {
	if ($data === false) {
		return false;
	}

	if (strlen($data) == 2) {
		$result = unpack("n", $data);
	} else if (strlen($data) == 4) {
		$result = unpack("N", $data);
	} else if (strlen($data) == 8) {
		$result = 0;
		for ($x = 0; $x < 8; $x ++) {
			$result = ($result * 256) + ord($data{$x});
		}

		return $result;
	} else {
		return false;
	}

	return $result[1];
}

function CB_PackInt64($num) {
	$result = "";

	if (is_int(2147483648)) {
		$floatlim = 9223372036854775808;
	} else {
		$floatlim = 2147483648;
	}

	if (is_float($num)) {
		$num = floor($num);
		if ($num < (double) $floatlim) {
			$num = (int) $num;
		}
	}

	while (is_float($num)) {
		$byte   = (int) fmod($num, 256);
		$result = chr($byte) . $result;

		$num = floor($num / 256);
		if (is_float($num) && $num < (double) $floatlim) {
			$num = (int) $num;
		}
	}

	while ($num > 0) {
		$byte   = $num & 0xFF;
		$result = chr($byte) . $result;
		$num    = $num >> 8;
	}

	$result = str_pad($result, 8, "\x00", STR_PAD_LEFT);
	$result = substr($result, - 8);

	return $result;
}

function CB_RawFileSize($fp) {
	$pos  = 0;
	$size = 1073741824;
	fseek($fp, 0, SEEK_SET);
	while ($size > 1) {
		fseek($fp, $size, SEEK_CUR);

		if (fgetc($fp) === false) {
			fseek($fp, - $size, SEEK_CUR);
			$size = (int) ($size / 2);
		} else {
			fseek($fp, - 1, SEEK_CUR);
			$pos += $size;
		}
	}

	while (fgetc($fp) !== false) {
		$pos ++;
	}

	return $pos;
}

// Drop-in replacement for hash_hmac() on hosts where Hash is not available.
// Only supports HMAC-MD5 and HMAC-SHA1.
if (!function_exists("hash_hmac")) {
	function hash_hmac($algo, $data, $key, $raw_output = false) {
		$algo = strtolower($algo);
		$size = 64;
		$opad = str_repeat("\x5C", $size);
		$ipad = str_repeat("\x36", $size);

		if (strlen($key) > $size) {
			$key = $algo($key, true);
		}
		$key = str_pad($key, $size, "\x00");

		$y = strlen($key) - 1;
		for ($x = 0; $x < $y; $x ++) {
			$opad[$x] = $opad[$x] ^ $key[$x];
			$ipad[$x] = $ipad[$x] ^ $key[$x];
		}

		$result = $algo($opad . $algo($ipad . $data, true), $raw_output);

		return $result;
	}
}

class CB_ServiceHelper {
	private $config, $deflate, $rng, $cipher1, $cipher2, $sign, $service, $db, $nextblock, $sharedblockdata, $sharedblocknum, $bytessent;

	public function Init($config) {
		global $rootpath;

		if (!isset($config["notifications"])) {
			CB_DisplayError("Backup configuration is incomplete or missing.  Run 'configure.php' first.");
		}

		CB_SendNotificationsOnExit($config["notifications"]);

		if ($config["smallfilelimit"] > $config["blocksize"] / 2) {
			CB_DisplayError("Backup configuration has an invalid 'smallfilelimit'.  Must be smaller than 'blocksize' / 2.");
		}
		if ($config["blocksize"] % 4096 != 0) {
			CB_DisplayError("Backup configuration has an invalid 'blocksize'.  Must be a multiple of 4096.");
		}
		if ($config["numincrementals"] < 0) {
			$config["numincrementals"] = 0;
		}

		$this->config  = $config;
		$this->service = false;

		// Set up compression.
		require_once $rootpath . "/support/deflate_stream.php";

		if (!DeflateStream::IsSupported()) {
			CB_DisplayError("One or more functions are not available for data compression.  Try enabling the 'zlib' module.");
		}

		$this->deflate = new DeflateStream;

		// Set up encryption.
		require_once $rootpath . "/support/phpseclib/Base.php";
		require_once $rootpath . "/support/phpseclib/Rijndael.php";
		require_once $rootpath . "/support/phpseclib/AES.php";

		$encryptkey = array();
		foreach ($this->config["encryption_key"] as $key => $val) {
			$encryptkey[$key] = hex2bin($val);
		}

		$this->rng     = new CSPRNG();
		$this->cipher1 = new phpseclib\Crypt\AES();
		$this->cipher1->setKey($encryptkey["key1"]);
		$this->cipher1->setIV($encryptkey["iv1"]);
		$this->cipher1->disablePadding();
		$this->cipher2 = new phpseclib\Crypt\AES();
		$this->cipher2->setKey($encryptkey["key2"]);
		$this->cipher2->setIV($encryptkey["iv2"]);
		$this->cipher2->disablePadding();
		$this->sign = $encryptkey["sign"];

		$this->db              = false;
		$this->nextblock       = - 1;
		$this->sharedblockdata = "";
		$this->sharedblocknum  = - 1;
		$this->bytessent       = 0;
	}

	public function InitService($config) {
		global $rootpath;

		$servicename = $config["service_info"]["service"];

		require_once $rootpath . "/support/cb_" . $servicename . ".php";

		$servicename2  = "CB_Service_" . $servicename;
		$this->service = new $servicename2;

		if (isset($config["service_info"]["options"])) {
			$this->service->Init($config["service_info"]["options"]);
		}

		return array("success" => true, "servicename" => $servicename, "service" => $this->service);
	}

	public function StartService() {
		$servicename = $this->config["service_info"]["service"];

		echo "Connecting to '" . $servicename . "'...\n";
		if ($this->service === false) {
			$this->InitService($this->config);
		}

		$result = $this->service->GetBackupInfo();
		if (!$result["success"]) {
			CB_DisplayError("Retrieving " . $servicename . " backup service information failed.", $result);
		}

		$result["servicename"] = $servicename;
		$result["service"]     = $this->service;

		return $result;
	}

	public function SetDB($db) {
		$this->db = $db;

		// Retrieve the next block number.
		$this->nextblock = (int) $this->db->GetOne("SELECT", array(
				"MAX(blocknum)",
				"FROM" => "?",
			), "files") + 1;
		if ($this->nextblock < 10) {
			$this->nextblock = 10;
		}

		// Initialize the shared block.
		$this->sharedblockdata = "";
		$this->sharedblocknum  = $this->nextblock;
		$this->nextblock ++;

		$this->bytessent = 0;
	}

	public function UploadFilePart(&$data, $blocknum, &$nextpart, $final = false) {
		// 32 bytes of overhead (4 byte prefix random, 4 byte data size, 20 byte hash, 4 byte suffix random).
		while (strlen($data) && ($final || strlen($data) >= $this->config["blocksize"] - 32)) {
			// Generate block.
			$block = $this->rng->GetBytes(4);

			$size  = (strlen($data) >= $this->config["blocksize"] - 32 ? $this->config["blocksize"] - 32 : strlen($data));
			$block .= pack("N", $size);

			$data2 = substr($data, 0, $size);
			$data  = substr($data, $size);
			$block .= $data2;

			$block .= hash_hmac("sha1", $data2, $this->sign, true);
			$block .= $this->rng->GetBytes(4);
			if (strlen($block) < $this->config["smallfilelimit"]) {
				$block .= $this->rng->GetBytes($this->config["smallfilelimit"] - strlen($block));
			}
			if (strlen($block) % 4096 != 0) {
				$block .= $this->rng->GetBytes(4096 - (strlen($block) % 4096));
			}

			// Encrypt the block.
			$block = $this->cipher1->encrypt($block);

			// Alter block.  (See:  http://cubicspot.blogspot.com/2013/02/extending-block-size-of-any-symmetric.html)
			$block = substr($block, - 1) . substr($block, 0, - 1);

			// Encrypt the block again.
			$block = $this->cipher2->encrypt($block);

			// Send the block to the service.
			echo "\tUploading block " . $blocknum . ":" . $nextpart . " (" . number_format(strlen($block), 0) . " bytes)\n";
			$result = $this->service->UploadBlock($blocknum, $nextpart, $block);
			if (!$result["success"]) {
				CB_DisplayError("Block upload failed", $result);
			}

			$this->bytessent += strlen($block);
			$nextpart ++;
		}
	}

	public function UploadFile($id, $filename, $forcedblock = false) {
		$fp = @fopen($filename, "rb");
		if ($fp !== false) {
			// Calculate the real file size since lstat() can't handle large files.
			$realfilesize = CB_RawFileSize($fp);
			fseek($fp, 0, SEEK_SET);

			$shared = ($forcedblock === false);
			$this->deflate->Init("wb");
			$stagingdata = "";
			$filesize    = $realfilesize;
			$nextpart    = 0;
			while ($filesize) {
				$data = fread($fp, ($filesize >= 1048576 ? 1048576 : $filesize));
				if ($data === false) {
					$data = "";
				}

				$filesize -= strlen($data);

				if ($data !== "") {
					$this->deflate->Write($data);
					$stagingdata .= $this->deflate->Read();

					if (strlen($stagingdata) > $this->config["smallfilelimit"]) {
						$shared = false;
					}

					$this->UploadFilePart($stagingdata, ($forcedblock !== false ? $forcedblock : $this->nextblock), $nextpart);
				}
			}

			// Last data chunk.
			$this->deflate->Finalize();
			$stagingdata .= $this->deflate->Read();
			if ($stagingdata !== "") {
				if (strlen($stagingdata) > $this->config["smallfilelimit"]) {
					$shared = false;
				}

				if ($shared) {
					echo "\tStoring file data in small file block.\n";
					$stagingdata = CB_PackInt64($id) . pack("N", strlen($stagingdata)) . $stagingdata;
					if (strlen($this->sharedblockdata) + strlen($stagingdata) <= $this->config["blocksize"] - 32) {
						$this->sharedblockdata .= $stagingdata;
					} else {
						echo "\tUploading small file block.\n";
						$this->UploadFilePart($this->sharedblockdata, $this->sharedblocknum, $nextpart, true);

						$this->sharedblocknum = $this->nextblock;
						$this->nextblock ++;

						$this->sharedblockdata = $stagingdata;
					}
				} else {
					$this->UploadFilePart($stagingdata, ($forcedblock !== false ? $forcedblock : $this->nextblock), $nextpart, true);
				}
			}

			// Update the database.
			if ($shared) {
				$this->db->Query("UPDATE", array(
					"files",
					array(
						"blocknum"     => ($realfilesize ? $this->sharedblocknum : 0),
						"sharedblock"  => "1",
						"realfilesize" => $realfilesize,
					),
					"WHERE" => "id = ?"
				), $id);
			} else if ($nextpart && $forcedblock === false) {
				$this->db->Query("UPDATE", array(
					"files",
					array(
						"blocknum"     => $this->nextblock,
						"realfilesize" => $realfilesize,
					),
					"WHERE" => "id = ?"
				), $id);

				$this->nextblock ++;
			}

			fclose($fp);
		}
	}

	public function GetBytesSent() {
		return $this->bytessent;
	}

	public function UploadSharedData() {
		if ($this->sharedblockdata !== "") {
			$nextpart = 0;
			$this->UploadFilePart($this->sharedblockdata, $this->sharedblocknum, $nextpart, true);

			$this->sharedblocknum = $this->nextblock;
			$this->nextblock ++;

			$this->sharedblockdata = "";
		}
	}

	public function ExtractBlock($destfp, $block, $shared) {
		if ($block === "" || strlen($block) % 4096 != 0) {
			return false;
		}

		// Decrypt the block.
		$block = $this->cipher2->decrypt($block);

		// Alter block.  (See:  http://cubicspot.blogspot.com/2013/02/extending-block-size-of-any-symmetric.html)
		$block = substr($block, 1) . substr($block, 0, 1);

		// Decrypt the block again.
		$block = $this->cipher1->decrypt($block);

		// 32 bytes of overhead (4 byte prefix random, 4 byte data size, 20 byte hash, 4 byte suffix random).
		$size = CB_UnpackInt(substr($block, 4, 4));
		if ($size > strlen($block) - 32) {
			return false;
		}

		$data  = substr($block, 8, $size);
		$hash  = substr($block, 8 + $size, 20);
		$hash2 = hash_hmac("sha1", $data, $this->sign, true);
		if ($hash !== $hash2) {
			return false;
		}

		if ($shared) {
			fwrite($destfp, $data);
		} else {
			// Uncompress the data.
			$this->deflate->Init("rb");
			while ($data !== "") {
				$size = (strlen($data) > 1048576 ? 1048576 : strlen($data));
				$this->deflate->Write(substr($data, 0, $size));
				$data  = (string) substr($data, $size);
				$data2 = $this->deflate->Read();
				if ($data2 !== "") {
					fwrite($destfp, $data2);
				}
			}

			$this->deflate->Finalize();
			$data2 = $this->deflate->Read();
			if ($data2 !== "") {
				fwrite($destfp, $data2);
			}
		}

		return true;
	}

	public function DownloadFile($destfilename, $incrementalid, $blocknum, $blockparts = false, $sharedblock = false) {
		if ($blockparts === false) {
			$result = $this->service->GetIncrementalBlockList($incrementalid);
			if (!$result["success"]) {
				CB_DisplayError("Unable to retrieve block list for incremental " . $incrementalid . ".", $result);
			}
			if (!isset($result["blocklist"][$blocknum])) {
				CB_DisplayError("Incremental " . $incrementalid . " does not have block " . $blocknum . ".");
			}

			$blockparts = $result["blocklist"][$blocknum];
		}

		ksort($blockparts);

		$fp = fopen($destfilename, "wb");
		if ($fp === false) {
			CB_DisplayError("Unable to create file '" . $destfilename . "'.");
		}

		foreach ($blockparts as $partnum => $info) {
			$result = $this->service->DownloadBlock($info);
			if (!$result["success"]) {
				CB_DisplayError("Unable to download block " . $blocknum . ":" . $partnum . " from incremental " . $incrementalid . ".", $result);
			}
			if (!$this->ExtractBlock($fp, $result["data"], $sharedblock)) {
				CB_DisplayError("Unable to decrypt/uncompress block " . $blocknum . ":" . $partnum . " from incremental " . $incrementalid . ".  Possible data corruption detected.", $result);
			}
		}

		fclose($fp);

		// Pre-calculate the index for the shared block.
		if ($sharedblock) {
			$fp = fopen($destfilename, "rb");
			if ($fp === false) {
				CB_DisplayError("Unable to open file '" . $destfilename . "'.");
			}

			$index = array();
			do {
				$id   = fread($fp, 8);
				$size = fread($fp, 4);
				if (strlen($id) !== 8 || strlen($size) !== 4) {
					break;
				}

				$id   = CB_UnpackInt($id);
				$size = CB_UnpackInt($size);
				if ($size < 0) {
					break;
				}

				$index[$id] = array("pos" => ftell($fp), "size" => $size);
				fseek($fp, $size, SEEK_CUR);
			} while (1);

			fclose($fp);

			file_put_contents($destfilename . ".idx", serialize($index));
		}
	}

	public function ExtractSharedFile($destfilename, $sharedfp, $indexinfo) {
		$fp = fopen($destfilename, "wb");
		if ($fp === false) {
			CB_DisplayError("Unable to create file '" . $destfilename . "'.");
		}

		fseek($sharedfp, $indexinfo["pos"], SEEK_SET);
		$data = fread($sharedfp, $indexinfo["size"]);

		// Uncompress the data.
		$this->deflate->Init("rb");
		while ($data !== "") {
			$size = (strlen($data) > 1048576 ? 1048576 : strlen($data));
			$this->deflate->Write(substr($data, 0, $size));
			$data  = (string) substr($data, $size);
			$data2 = $this->deflate->Read();
			if ($data2 !== "") {
				fwrite($fp, $data2);
			}
		}

		$this->deflate->Finalize();
		$data2 = $this->deflate->Read();
		if ($data2 !== "") {
			fwrite($fp, $data2);
		}

		fclose($fp);
	}

	public function MergeDown($incrementals) {
		global $rootpath;

		if (count($incrementals) < 2) {
			CB_DisplayError("Not enough incrementals exist to perform a merge down operation.  The base plus one incremental is required.");
		}
		if (!isset($incrementals[0]) || !isset($incrementals[1])) {
			CB_DisplayError("The backup is corrupt.  Unable to perform a merge down operation.");
		}

		echo "\tRetrieving base block list.\n";
		$result = $this->service->GetIncrementalBlockList(0);
		if (!$result["success"]) {
			CB_DisplayError("Unable to retrieve block list for the base.", $result);
		}

		$blocklist = $result["blocklist"];

		echo "\tRetrieving first incremental block list.\n";
		$result = $this->service->GetIncrementalBlockList(1);
		if (!$result["success"]) {
			CB_DisplayError("Unable to retrieve block list for the first incremental.", $result);
		}

		$blocklist2 = $result["blocklist"];

		echo "\tRetrieving deleted blocks.\n";
		@mkdir($rootpath . "/cache");
		$deletedfile = $rootpath . "/cache/mergedown_deleted.dat";
		$this->DownloadFile($deletedfile, 1, 1, $blocklist2[1]);

		// Create a temporary merge backup folder.
		echo "\tStarting merge down.\n";
		$result = $this->service->StartMergeDown($blocklist);
		if (!$result["success"]) {
			CB_DisplayError("Unable to initialize the remote merge down operation.", $result);
		}

		// Move deleted blocks to temporary merge backup folder.
		echo "\tProcessing deleted blocks.\n";
		$fp = fopen($deletedfile, "rb");
		if ($fp === false) {
			CB_DisplayError("Unable to open '" . $deletedfile . "' for reading.", $result);
		}
		while (($data = fread($fp, 8)) !== false && strlen($data) === 8) {
			$blocknum = CB_UnpackInt($data);

			if (isset($blocklist[$blocknum])) {
				echo "\tMoving block " . $blocknum . " into merge backup folder.\n";
				$result = $this->service->MoveBlockIntoMergeBackup($blocklist[$blocknum]);
				if (!$result["success"]) {
					CB_DisplayError("Unable to move block " . $blocknum . " into merge backup directory.", $result);
				}
			}
		}
		fclose($fp);

		@unlink($deletedfile);

		// Prepare to replace special blocks 0-9.
		for ($x = 0; $x < 10; $x ++) {
			if (isset($blocklist[$x]) && isset($blocklist2[$x])) {
				echo "\tMoving block " . $x . " into merge backup folder.\n";
				$this->service->MoveBlockIntoMergeBackup($blocklist[$x]);

				unset($blocklist[$x]);
			}
		}

		// Move all files from the first incremental into the base.
		echo "\tMoving incremental files into base.\n";
		foreach ($blocklist2 as $blocknum => $parts) {
			$result = $this->service->MoveBlockIntoBase($parts);
			if (!$result["success"]) {
				CB_DisplayError("Unable to move block " . $blocknum . " into base.", $result);
			}
		}

		// Finalize merge operation.
		echo "\tFinalizing merge down operation.\n";
		$result = $this->service->FinishMergeDown();
		if (!$result["success"]) {
			CB_DisplayError("Unable to complete the merge down operation.", $result);
		}

		return $result;
	}
}

// Check enabled extensions.
if (!extension_loaded("openssl")) {
	CB_DisplayError("The 'openssl' PHP module is not enabled.  Please update the file '" . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : "php.ini") . "' to enable the module.");
}
if (!extension_loaded("zlib")) {
	CB_DisplayError("The 'zlib' PHP module is not enabled.  Please update the file '" . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : "php.ini") . "' to enable the module.");
}

// Force an exclusive lock.
CB_ExclusiveLock();
?>
