<?php
# Imgc
# Imgc - MediaWiki Image caching extension based on OpenStreetMap
#
# Usage:
# <Imgc>url=http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png|
#       width=300|height=200|expire=30|display=none</Imgc>
#
# Will output
# <img src="URL_LOCAL" width=300 height=200>Image from URL on DATE</img>
# where URL =
# <a href="http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png">upload.wikimedia.org</a>
# and URL_LOCAL =
# $wgImgcPath/upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png
#
# If display=none, the div that shows "From .... on DATE" is given
# style="display:none".  The default is display=block.
#
# If original image was written less than EXPIRE seconds since this
# call, the image is re-cached.  The default is expire=86400. Note that
# you may need to clear the MediaWiki cache to see changes (append
# ?action=purge to URL of the page where you used <imgc>).
# $wgImgcPath is set in LocalSettings.php and defaults to
# $wgUploadPath.
#
# Global variables:
# $wgImgcPath = "images";
# $wgImgcmaxsizeKB = 3000;
#
##############################################################################
# Note: This will not work when there is a query string
# See Imgc3.php for an attempt at addressing this.
# The problem is that amperstands are translated to &amp; when passed to
# system()
# fopen()
# file_get_contents()
# etc.
#
# For example, system('curl http://url.com/query?abc=2&def=3') results in
# a request for http://url.com/query?abc=2&amp;def=3
# which is not a valid URL. Hack solution is to create a script called
# curl2 that replace &amp; with & and then calls curl.  I think a
# better way to do this is to just interface with wget, which deals
# with file and directory naming is a clean way.  Below I have implemented
# some of this functionality, but it would be difficult to address
# all possible cases.

# Why am I using $wgScriptPath instead of $wgUploadPath?
##############################################################################

$wgExtensionFunctions[] = "xwfmap";

function xwfmap() {
	global $wgParser;
	$wgParser->setHook("imgc", "imgc");
}

# The callback function
function imgc($input) {

	# Parse pipe separated name value pairs (e.g. 'aaa=bbb|ccc=ddd')
	$paramStrings = explode('|', $input);
	foreach ($paramStrings as $paramString) {
		$eqPos = strpos($paramString, "=");
		if ($eqPos === false) {
			$params[$paramString] = "true";
		} else {
			$params[substr($paramString, 0, $eqPos)] = htmlspecialchars(substr($paramString, $eqPos + 1));
		}
	}

	// Width and height parameters to put in img tag.
	$width = $params["width"];
	$height = $params["height"];
	$expireSeconds = (int) $params["expire"];
	$display = $params["display"];

	if ($expireSeconds == "") {
		$expireSeconds = 86400;
	}
	if ($display == "") {
		$divopen = "<div style=\"" . "font-size:80%;display:block\">";
	} else {
		$divopen = "<div style=\"" . "font-size:80%;display:$display\">";
	}
	$error = "";
	$status = "";

	$localFilePath = "";
	if ($error == "") {
		$remoteURL = $params["url"];
		$tmp = parse_url($remoteURL);
		$localFileName = "/".$tmp["host"].$tmp["path"];

		global $wgImgcPath;
		global $wgScriptPath;

		if ( empty($wgImgcPath)) {
			global $wgUploadPath;
			$wgImgcPath = $wgUploadPath;
		}

		$localFilePath = $wgImgcPath.$localFileName;

		if (!is_dir(dirname($localFilePath))) {
			mkdir(dirname($localFilePath), 0755, true);
		}

		$status = fetchFileCacheOrRemote($remoteURL, $localFilePath, $expireSeconds);

		if (substr_count($status, "Error") > 1)
		$error = "Image download failed with status: $status";
	}

	if ($error == "") {
		$cachedon   = date("F d Y H:i:s", filemtime($localFilePath));
		$hostshort = preg_replace("/^.*?\./", "", $tmp["host"]);
		$hostshort = $tmp["host"];
		
		$sourceinfo  = "From $remoteURL on $cachedon.";
		$sourceinfoa  = "From <a href='$remoteURL'>$hostshort</a> on $cachedon.";
		
		$outputo = "<img title='$sourceinfo' src='$wgScriptPath/$localFilePath' ";
		if ($height == "") {
			$output = $outputo . "width="."\"".$width."\"".">";
		} else if ($width == "") {
			$output = $outputo . "height="."\"".$height."\"".">";
		} else if (($width == "") & ($height == "")) {
			$output = $outputo . "\"".">";
		} else {
			$output = $outputo . "width="."\"" . $width."\"" . "height="."\"" . $height."\"" . ">";
		}
		
		$output  = $output. $divopen."<i>$sourceinfoa</i></div>";
	} else {

		// Write error message
		$output = "";
		$output .= "<font color=\"red\"><b>Imgc.php error:</b> ".$error."</font><br />\n";
		$output .= htmlspecialchars($input);

	}

	return $output;
}

/*
 * Fetch a file from a remote URL if cached version does not exist
 * or if cached version is expired.  Return value is string that states cache status.
 */
function fetchFileCacheOrRemote($remoteUrl, $localFileName, $expireSeconds) {

	if (file_exists($localFileName)) {
		// Local file found
		$modified = filemtime($localFileName);
		if ($modified < (time() - $expireSeconds)) {
			$status  = "Cached file (fetched ".date("F d Y H:i:s.", $modified).") too old<br/>";
			$status .= fetchRemoteFile($remoteUrl, $localFileName);
		} else {
			$status = "Cached file (last fetched ".date("F d Y H:i:s.", $modified).") not expired<br/>";
		}

	} else {
		$status  = "File not cached<br />";
		$status .= fetchRemoteFile($remoteUrl, $localFileName);
	}

	return $status;
}


/**
 * Fetch a file from a specified remoteUrl, and write it
 * to the local file system with the specified localFileName
 * Returns is success or an error message if the file was too big.
 */
function fetchRemoteFile($remoteUrl, $localFileName) {

	global $IP; # Mediawiki Installation Path
	$localFileNameTemp = $localFileName.".tmp";
	$destination = fopen($IP."/".$localFileNameTemp, "w");

	if (empty($wgImgcmaxsizeKB)) {
		global $wgImgcmaxsizeKB;
		$wgImgcmaxsizeKB=800;
	}

	$maxsizeKB = $wgImgcmaxsizeKB; # Max file size before the download stops
	$maxsize   = 1024 * $maxsizeKB;
	$chunkSize = 2048; # Fetch 2 KB in each iteration

	if (false) {
	$opts = array('http' => array('method' => 'GET','max_redirects' => '5'));
	$context = stream_context_create($opts);
	$stream = fopen($remoteUrl, "r",false,$context);

	$a = stream_get_contents($stream);

	fwrite($destination, $a);
	fclose($destination);
        rename($localFileNameTemp,$localFileName);

	if ($a) {
	   rename($localFileNameTemp,$localFileName);
	   return "Success: Fetched $remoteUrl.";
        } else {
           unlink($localFileNameTemp);
	   return "Message: Error when attempting to retrieve image.";
        }
        }

	if (true) { 
	$source = fopen($remoteUrl, "r");
	$length = 0;
	while (($a = fread($source, $chunkSize)) && ($length <= $maxsize)) {
		$length = $length + $chunkSize;
		fwrite($destination, $a);
	}
	fclose($source);
	fclose($destination);

	if ($length == 0) {
		unlink($localFileNameTemp);
		return "Message: Remote file no longer exists.";
	}
	if ($length > $maxsize) {
		# Clean up partially downloaded file.
		unlink($localFileNameTemp);
		print "Error: Image size of $length KB exceeded the maximum allowed of $maxsizeKB KB.";
	} else {
		rename($localFileNameTemp,$localFileName);
		return "Success: Fetched $remoteUrl.";
	}
	}
}

?>
