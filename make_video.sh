#!/bin/bash
# http://eddmann.com/posts/uploading-podcast-audio-to-youtube/
BASE_FOLDER=/var/www/vhosts/orpc.sg/httpdocs/sites/default/files
SERMONS_AUDIO="$BASE_FOLDER/sermons_audio"
SERMONS_YOUTUBE="$BASE_FOLDER/sermons_youtube"
IMAGE="$BASE_FOLDER/ETC_1502-X3.jpg"
YT_CRED="/var/local/youtube_sermon_credentials.json"

function makeMp4() {
  local image="$2"
  [ ! -e "$image"  ] && echo "Image file $image does not exist" && exit 1
  local audio="$1"
  [ ! -e "$audio"  ] && echo "Audio file $audio does not exist" && exit 1
  [ -z "$image" ] && image="$IMAGE"
  local output=$(makeOutputBaseName "$audio")
  output="$output.mp4"
  if [ -f "$output" ]; then
    echo "$output aleady generated"
    return
  fi
  echo "Generating $output"
  ffmpeg -loop 1 \
    -r 2 \
    -i "$image" \
    -i "$audio" \
    -vf "scale=720:trunc(ow/a/2)*2" \
    -c:v libx264 \
    -preset slow \
    -tune stillimage \
    -crf 18 \
    -c:a copy \
    -shortest \
    -threads 0 \
    -pix_fmt yuv420p \
    -y \
    $output
# other vf scale
# -vf="scale=trunc(iw/2)*2:trunc(ih/2)*2"
# scale="720:trunc(ow/a/2)*2"
# scale="360:trunc(ow/a/2)*2"
# scale="240:trunc(ow/a/2)*2"
# scale=-1:380
# But even then: no audio with quicktime player.
}

function uploadToYoutube() {
  local audio="$1"
  [ ! -e "$audio"  ] && echo "Audio file $audio does not exist" && exit 1
  local output=$(makeOutputBaseName "$audio")
  local ytlog="$output.yt.log"
  if [ -f $ytlog ]; then
    echo "$ytlog already uploaded"
    return
  fi
  local video="$output.mp4"
  if [ ! -f "$video" ]; then
    echo "$video cannot be found"
    return
  fi
  echo "TODO $ytlog"
  youtube-upload \
    --title="Sermon at ORPC: $title" \
    --description="$sermondate - $passage - http://orpc.sg/node/$nodeid" \
    --client-secrets=/var/local/youtube_sermon_credentials.json \
    $video
}

function makeOutputBaseName() {
  local audio="$1"
  local output="$SERMONS_YOUTUBE/"`basename ${audio%.*}`
  echo "$output"
}

function makeAllMp4s() {
  for filename in "$SERMONS_AUDIO"/*.mp3; do
    makeMp4 "$filename" "$IMAGE"
    exit
  done
}
function uploadAllYoutube() {
  for filename in "$SERMONS_AUDIO"/*.mp3; do
    uploadToYoutube "$filename"
  done
}



#makeAllMp4s
#uploadAllYoutube
#audio="$1" # double quotes sanatizes the input lah.
#[ -z "$audio" ] && audio="orpc_20150823_m.mp3"

for i in "$@"
do
case $i in
    -a=*|--audio=*)
    audio="${i#*=}"
    shift # past argument=value
    ;;
    -p=*|--passage=*)
    passage="${i#*=}"
    shift # past argument=value
    ;;
    -t=*|--title=*)
    title="${i#*=}"
    shift # past argument=value
    ;;
    -d=*|--date=*)
    sermondate="${i#*=}"
    shift # past argument=value
    ;;
    -n=*|--nodeid=*)
    nodeid="${i#*=}"
    shift # past argument=value
    ;;
    --default)
    DEFAULT=YES
    shift # past argument with no value
    ;;
    *)
            # unknown option
    ;;
esac
done

makeMp4 "$SERMONS_AUDIO/$audio" "$IMAGE"
uploadToYoutube "$SERMONS_AUDIO/$audio"
