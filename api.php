<?php
header("Access-Control-Allow-Origin: *");

$token = $_COOKIE["auth_key"];
if (isset($_COOKIE["server_url"])) {
    $serverURL = $_COOKIE["server_url"];
} else {
    $serverURL = get_server_url($token);
}

function get_server_url($token): string {
    $response = file_get_contents("https://plex.tv/api/resources?includeHttps=1&X-Plex-Token=".$token);

    if ($response === false) {
        echo "Error fetching server listing.";
        exit;
    }

    libxml_use_internal_errors(true);
    $data = simplexml_load_string($response);

    if (!$data) {
        echo "Error parsing server listing XML.";
        exit;
    }

    foreach ($data->children() as $device) {
        if ($device['product'] == "Plex Media Server") {
            foreach ($device->children() as $connection) {
                if ($connection["local"] == "0") {
                    setcookie("server_url", $connection["uri"], time() + (86400 * 3650), "/vinylr");
                    return $connection["uri"];
                }
            }
        }
    }

    return "";
}

if (isset($_GET['type']) && $_GET['type'] == 'request') {
    $search = $_GET['query'];
    if ($search) {
        $url = $serverURL.'/hubs/search?query=';
        $url .= urlencode($search);
        $url .= '&limit=7';
        $url .= '&X-Plex-Text-Format=plain';
        $url .= '&X-Plex-Token='.$token;

        if (($response_xml_data = file_get_contents($url))===false) {
            echo "Error fetching search results XML\n";
        } else {
            libxml_use_internal_errors(true);
            $data = simplexml_load_string($response_xml_data);

            if ($data) {

                foreach ($data->children() as $hub) {
                    if ($hub['type'] == "album") {
                        foreach($hub->children() as $album) {
                            $album["thumb"] = $serverURL.'/photo/:/transcode?width=500&height=500&minSize=1&upscale=1&url='
                                .urlencode($album["thumb"]).'&X-Plex-Token='.$token;

                            $albumURL = $serverURL.$album["key"].'?X-Plex-Token='.$token;
                            if (($albumXML = file_get_contents($albumURL))===false) {
                                echo "Error fetching album XML\n";
                            } else {
                                $albumData = simplexml_load_string($albumXML);

                                if ($albumData) {
                                    if ($albumData->children()->count() > 1) {
                                        for ($i = 0; $i < $albumData->children()->count(); $i++) {
                                            $track = $albumData->children()->Track[$i];
                                            foreach ($track->attributes() as $attr) {
                                                $album->tracks[$i][$attr->getName()] = $attr;
                                            }
                                        }
                                    } else {
                                        $track = $albumData->children()->Track;
                                        foreach ($track->attributes() as $attr) {
                                            $album->tracks[0][$attr->getName()] = $attr;
                                        }
                                    }
                                }
                            }

                        }

                        echo json_encode($hub->children());
                        exit;
                    }
                }

            } else {
                echo "Error loading XML\n";
                foreach(libxml_get_errors() as $error) {
                    echo "\t", $error->message;
                }
            }
        }

        exit;
    }
}

if (isset($_POST['type']) && $_POST['type'] == 'scrobble') {

    $output = array();
    $tracks = $_POST['tracks'];

    foreach ($tracks as $track) {
        $track_info = explode('-', $track);

        $scrobbleURL = $serverURL.'/:/timeline?ratingKey='.$track_info[0].'&key=%2Flibrary%2Fmetadata%2F'.$track_info[0].
            '&state=playing&hasMDE=1&time=0&duration='.$track_info[1].'&X-Plex-Token='.$token.'&X-Plex-Client-Identifier='.$_COOKIE["client_id"];
        $response = file_get_contents($scrobbleURL);

        if (empty($response)) {
            array_push($output, $track);
            continue;
        }

        $scrobbleURL = $serverURL.'/:/timeline?ratingKey='.$track_info[0].'&key=%2Flibrary%2Fmetadata%2F'.$track_info[0].
            '&state=stopped&hasMDE=1&time='.$track_info[1].'&duration='.$track_info[1].'&X-Plex-Token='.$token.'&X-Plex-Client-Identifier='.$_COOKIE["client_id"];
        $response = file_get_contents($scrobbleURL);

        if (empty($response)) {
            array_push($output, $track);
            continue;
        }

        $scrobbleURL = $serverURL.'/:/scrobble?key='.$track_info[0]
            .'&identifier=com.plexapp.plugins.library&X-Plex-Platform=Vinylr%20for%20Plex&X-Plex-Device-Name=Vinyl&X-Plex-Token='.$token;
        file_get_contents($scrobbleURL);
    }

    echo json_encode($output);
    exit;
}
