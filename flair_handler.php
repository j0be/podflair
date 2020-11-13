<?php
    $confStr = file_get_contents('conf.json', false);
    $conf = json_decode($confStr);

    if (!$_GET['code'] && !$conf->code || array_key_exists('auth', $_GET)) { // We have this after authorization
        $rand = rand(1, 1000000);
        $loginUrl = 'https://www.reddit.com/api/v1/authorize' .
            '?client_id=' . $conf->client_id .
            '&response_type=code'.
            '&state=' . $rand .
            '&redirect_uri=' . $conf->redirect_uri .
            '&duration=' . $conf->duration .
            '&scope=' . $conf->scope;

        setcookie('podflair_rand', $rand, time() + (86400 * 30), "/");
        header('Location: ' . $loginUrl);

        die();
    }


    if ($_GET['state'] && $_COOKIE['podflair_rand'] !== $_GET['state']) {
        die('You didn\'t generate the proper local token');
    }

    $conf->code = $_GET['code'];

    // BASE OAUTH OBJECTS
    require('OAuth2/Client.php');
    require('OAuth2/GrantType/IGrantType.php');
    require('OAuth2/GrantType/AuthorizationCode.php');
    $userAgent = 'Podflair/0.1 by j0be';
    $client = new OAuth2\Client($conf->client_id, $conf->secret, OAuth2\Client::AUTH_TYPE_AUTHORIZATION_BASIC);
    $client->setCurlOption(CURLOPT_USERAGENT, $userAgent);
    $client->setAccessTokenType(OAuth2\Client::ACCESS_TOKEN_BEARER);

    function handleToken($result, $conf, $user) {
        $access_result = json_decode($result);

        $conf->access_token = $access_result->access_token;
        $conf->refresh_token = $access_result->refresh_token;

        if ($user) {
            $conf->access_tokens->{$user} = $access_result->access_token;
            $conf->refresh_tokens->{$user} = $access_result->refresh_token;
        }

        $fp = fopen('conf.json', 'w');
        fwrite($fp, json_encode($conf));
        fclose($fp);

        return $conf->access_token;
    }

    function refreshToken($refreshtoken, $user) {
        echo "Expired token. Let's refresh\r\n";

        $url = 'https://www.reddit.com/api/v1/access_token';
        $data = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshtoken,
            'redirect_uri' => $conf->redirect_uri
        );

        $options = array(
            'http' => array(
                'header'  =>
                    'Authorization: Basic ' . base64_encode($conf->client_id . ':' . $conf->secret) . "\r\n" .
                    'Content-type: application/x-www-form-urlencoded',
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            die('We failed to get the access token from that code');
        }

        $client->setAccessToken(handleToken($result, $conf, $user));
    }

    // If we have a new auth code, let's handle access tokens
    if ($_GET['code']) {
        // Now we need to get access token
        $url = 'https://www.reddit.com/api/v1/access_token';
        $data = array(
            'grant_type' => 'authorization_code',
            'code' => $conf->code,
            'redirect_uri' => $conf->redirect_uri
        );

        $options = array(
            'http' => array(
                'header'  =>
                    'Authorization: Basic ' . base64_encode($conf->client_id . ':' . $conf->secret) . "\r\n" .
                    'Content-type: application/x-www-form-urlencoded',
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            die('We failed to get the access token from that code');
        }

        $token = handleToken($result, $conf, false);
        $client->setAccessToken($token);

        // Get which user we're logged in to store the access token
        $userUrl = 'https://oauth.reddit.com/api/v1/me.json';
        $response = $client->fetch($userUrl);
        $user = $response['result']['name'];

        $token = handleToken($result, $conf, $user);

        if(!$user) {
            die("Uh-oh. We didn't get the user off that account. Please try again (refresh)");
        }

        if (!$conf->access_tokens->{$user}) {
            $conf->access_tokens->{$user} = $access_result->access_token;
        }

        $fp = fopen('conf.json', 'w');
        fwrite($fp, json_encode($conf));
        fclose($fp);

        $urlinfo = parse_url($_SERVER["REQUEST_URI"]);
        header('Location: ' . $urlinfo['path']);

        die();
    }

    // Get the log with the base access token
    $outertoken = $conf->access_tokens->{'podcastsmod'} ?: $conf->access_token;
    $client->setAccessToken($outertoken);
    $modlogUrl = 'https://oauth.reddit.com/r/podcasts/about/log.json?limit=100&type=removelink';
    $response = $client->fetch($modlogUrl);

    if ($response['code'] === 401 && $conf->refresh_token) {
        refreshToken($conf->refresh_token);
    }

    if ($response['code'] === 403) {
        die('Forbidden');
    }

    $removals = $response['result']['data']['children'];

    if ($removals) {
        $removalsStr = file_get_contents('removals.json', false);
        $removalArr = array_slice(json_decode($removalsStr), 0, 100);

        $reasonsStr = file_get_contents('reasons.json', false);
        $reasons = json_decode($reasonsStr);
        $reason_keys = array_keys(get_object_vars($reasons));

        foreach ($removals as $removal) {
            $mod = $removal['data']['mod'];

            // Automod will always leave a comment if it should
            if ($mod !== 'AutoModerator') {
                $target_fullname = $removal['data']['target_fullname'];
                $target_id = preg_replace('/^t3_/', '', $target_fullname);

                if (!in_array($target_id, $removalArr)) {
                    array_push($removalArr, $target_id);

                    $target_url = 'https://oauth.reddit.com/r/podcasts/comments/' . $target_id;
                    $response = $client->fetch($target_url);

                    $flair = trim($response['result'][0]['data']['children'][0]['data']['link_flair_text']);

                    if (in_array($flair, $reason_keys)) {
                        $comments = $response['result'][1]['data']['children'];
                        $filteredComments = array_filter($comments, function ($comment) use ($mod) {
                            return
                                // Commenting this out for now. It's really if ANY mod responded
                                // $comment['data']['author'] === $mod &&
                                $comment['data']['distinguished'];
                        });

                        if (count($filteredComments) === 0) {
                            $useDefaultAccount = $conf->access_tokens->{$mod} ? false : true;
                            $token = $conf->access_tokens->{$mod} ?: $conf->access_tokens->{'podcastsmod'};
                            if ($token !== $outertoken) {
                                $client->setAccessToken($token);
                                $modlogUrl = 'https://oauth.reddit.com/r/podcasts/about/log.json?limit=1&type=removelink';
                                $response = $client->fetch($modlogUrl);
                                if ($response['code'] === 401 && $conf->refresh_tokens->{$mod}) {
                                    refreshToken($conf->refresh_tokens->{$mod}, $mod);
                                }
                            }
                            $message = $reasons->{$flair} ?: false;

                            if ($useDefaultAccount) {
                                $message .= "\r\n\r\n*I am a bot, and this action was performed automatically. Please [contact the moderators of this subreddit](/message/compose/?to=%2Fr%2Fpodcasts) if you have any questions or concerns.*";
                            }

                            if ($message) {
                                $comment_url = 'https://oauth.reddit.com/api/comment';
                                $comment_response = $client->fetch($comment_url, array(
                                    'api_type' => 'json',
                                    'return_rtjson' => true,
                                    'text' => $message,
                                    'thing_id' => $target_fullname
                                ), 'POST');

                                if ($comment_response['result']) {
                                    echo "Made comment for $target_id: $flair<br/>\r\n";

                                    $distinguish_url = 'https://oauth.reddit.com/api/distinguish';
                                    $distinguish_response = $client->fetch($distinguish_url, array(
                                        'api_type' => 'json',
                                        'how' => 'yes',
                                        'id' => 't1_' . $comment_response['result']['id'],
                                        'sticky' => true
                                    ), 'POST');

                                    if ($distinguish_response) {
                                        echo "Distinguished " . $comment_response['result']['id'] . "<br>\r\n";
                                    } else {
                                        echo "Failed to distinguish " . $comment_response['result']['id'] . "<br>\r\n";
                                    }
                                } else {
                                    echo "Error submitting comment for $target_id: $flair. Check again later.<br/>\r\n";
                                    array_pop($removalArr);
                                }
                            } else {
                                echo "Couldn't get a message for '$flair'<br>\r\n";
                            }
                        }
                    } else {
                        echo $flair . ' not in removal reasons' . "<br>\r\n";
                    }
                } else {
                    echo 'Already checked ' . $target_id . "<br>\r\n";
                }
            }
        }

        $fp = fopen('removals.json', 'w');
        fwrite($fp, json_encode($removalArr));
        fclose($fp);
    } else {
        echo 'Could not get any removals from the subreddit' . "<br>\r\n";
        print_r($response);
    }

?>