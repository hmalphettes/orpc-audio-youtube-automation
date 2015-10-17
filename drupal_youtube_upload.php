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
$sermon_youtube=$node->field_sermon_youtube;
if (empty($sermon_youtube) == false) {
  echo "A youtube sermon for ".$title." was already uploaded at: ".$sermon_youtube[LANGUAGE_NONE][0]["url"]." - todo: check the name and decide to re-upload. ".$nodeid, "\n";
  return;
}

$audio=$node->field_sermonaudio[LANGUAGE_NONE][0]["filename"];
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
$snippet->setDescription($date . " Passage: " . $passage . " orpc.sg/node/" . $nodeid);
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

node_save($node);

?>
