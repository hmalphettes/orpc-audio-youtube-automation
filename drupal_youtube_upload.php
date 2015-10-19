<?php
/* Make sure this is valid code once made into a single line
to be able to use this as a drupal rule script */

/* debugging: load an example */
function init(&$var) {
    if(!isset($var)) {
        $v = drush_get_option('node');
        $nid = intval($v);
        if ($nid == 0) { $nid = 2529; }
        $var = node_load($nid);
    }
}
init($node);

$nodeid=$node->nid;
$title=$node->title;

/* Make sure there is a sermon to upload */
$audio_field=$node->field_sermonpassage;
if (empty($audio_field)) {
  echo "No audio field specified yet - bye";
  return;
}

$audio=$node->field_sermonaudio[LANGUAGE_NONE][0]["filename"];
$audio_ts=$node->field_sermonaudio[LANGUAGE_NONE][0]["timestamp"];
if (empty($audio) || empty($audio_ts)) {
  echo "No filename for the sermon yet - bye";
  return;
}

$fs_audio="/var/www/vhosts/orpc.sg/httpdocs/sites/default/files/sermons_audio/".$audio;
if (file_exists($fs_audio) == false) {
  echo "The audio file ".$fs_audio." does not exist yet - bye";
  return;
}

/** Compare the signature of the file with what drupal recorded and what might have been
uploaded to you tube already. Decide to re-upload or not */

$fsstat = stat($fs_audio);
$fsstat_ctime = $fsstat["ctime"];
$audio_ts = $node->field_sermonaudio[LANGUAGE_NONE][0]["timestamp"];

$sermon_youtube=$node->field_sermon_youtube;

/* strange but I observed a difference of 1ms in what is stored in the database and what is read on the file system */
if (abs($fsstat_ctime - $audio_ts) > 50) {
  /** The file was modified without drupal knowing about it  */
  $force_reupload = true;
  $node->field_sermonaudio[LANGUAGE_NONE][0]["timestamp"] = $fsstat_ctime;
} else if (empty($sermon_youtube) == false) {
  $sermon_youtube_ts = $sermon_youtube[LANGUAGE_NONE][0]['attributes']['_timestamp'];
  $sermon_youtube_fn = $sermon_youtube[LANGUAGE_NONE][0]['attributes']['_filename'];
  if (empty($sermon_youtube_ts) == false && ($sermon_youtube_ts < $fsstat_ctime)) {
    echo "There was an upload but the timestamp of the file is not the same anymore: re-upload ".$audio;
  } else if (empty($sermon_youtube_fn) == false && (strcmp($sermon_youtube_fn,$audio) !== 0 )) {
    echo "There was an upload but the name of the file is not the same anymore: re-upload ".$audio;
  } else {
    echo "A youtube sermon for ".$title." was already uploaded at: ".$sermon_youtube[LANGUAGE_NONE][0]["url"]." - nodeid=".$nodeid, "\n";
    return;
  }
}


$passage=$node->field_sermonpassage[LANGUAGE_NONE][0]["value"];
$date=$node->field_sermondate[LANGUAGE_NONE][0]["value"];


exec('/usr/local/bin/make_video.sh --audio="'.$audio.'"',$results,$status);
$videoPath=end($results);

/* Upload to youtube; use the youtube_upload module for authentication into youtube:
 it has a lovely UI for configuration of the credentials
 extend the object so we get accesss to the Google_Client and Google_Service_YouTube
http://cgit.drupalcode.org/youtube_uploader/tree/youtube_uploader.ytapi.inc
 */
module_load_include("inc", "youtube_uploader", "youtube_uploader.ytapi");
class XYoutubeUploaderYtapi extends YoutubeUploaderYtapi {
   public function getClient() { return $this->client; }
   public function getYt() { return $this->yt; }
}
$ytua=new XYoutubeUploaderYtapi();
$ytua->getFreshToken();
$client = $ytua->getClient();
$youtube = $ytua->getYt();

/* Sample code from https://developers.google.com/youtube/v3/code_samples/php?hl=en#resumable_uploads */
$snippet = new Google_Service_YouTube_VideoSnippet();
$snippet->setTitle($title);
$snippet->setDescription($date . " Passage: " . $passage . " http://orpc.sg/node/" . $nodeid);
$snippet->setTags(array("orpc", "sermon"));

$status = new Google_Service_YouTube_VideoStatus();
$status->privacyStatus = "public";

$video = new Google_Service_YouTube_Video();
$video->setSnippet($snippet);
$video->setStatus($status);

$chunkSizeBytes = 1 * 1024 * 1024;

$client->setDefer(true);

$insertRequest = $youtube->videos->insert("status,snippet", $video);

$media = new Google_Http_MediaFileUpload(
    $client,
    $insertRequest,
    'video/*',
    null,
    true,
    $chunkSizeBytes
);
$media->setFileSize(filesize($videoPath));

/* Read the media file and upload it chunk by chunk. */
$status = false;
$handle = fopen($videoPath, "rb");
while (!$status && !feof($handle)) {
  $chunk = fread($handle, $chunkSizeBytes);
  $status = $media->nextChunk($chunk);
}

fclose($handle);

/* If you want to make other calls after the file upload, set setDefer back to false */
$client->setDefer(false);

/** Update the link */
$node->field_sermon_youtube[LANGUAGE_NONE][0]["url"] = 'https://www.youtube.com/watch?v=' . $status['id'];
$node->field_sermon_youtube[LANGUAGE_NONE][0]["title"] = $title;
/** Store the new timestamp so we know how it compares */
$node->field_sermon_youtube[LANGUAGE_NONE][0]['attributes']['_timestamp'] = $fsstat_ctime;
$node->field_sermon_youtube[LANGUAGE_NONE][0]['attributes']['_filename'] = $audio;

/** Commit updated node in the database */
node_save($node);

?>
