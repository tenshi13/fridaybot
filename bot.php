<?php
require_once('vendor/autoload.php');
require_once('./config.php');

if(!isset($config) &&
   !isset($config['bot_token']) ){
    exit('Please setup config file first. Look at config.php.sample in folder root.');
}

$loop = \React\EventLoop\Factory::create();

#pbecom channel
$ch = false;
$messageStack = [];

$client = new \Slack\RealTimeClient($loop);
$client->setToken($config['bot_token']);
$client->connect();

$client->connect()->then(function () {
    echo "Connected!\n";
});

$client->on('message', function ($data) use ($client) {
    global $messageStack, $ch, $users, $usersByUsername, $commands;

    $commands = ['list users'   => [],
                 'list bots'    => [],
                 'jira summary' => ['args'  => ["-d" => ["type" => "int"]] ],
                                    'usage' => "jira summary -d [days]"];

    reinit ($client);

    // is this a mentioned command?
    $fridayId = $usersByUsername['friday']->getId();
    if( !isMentionMessages($fridayId, $data['text']) ) {
        return;
    }

    // is this a duplicated message raised by the socket events library
    $messageKey = serialize($data);
    if( isset( $messageStack[$messageKey]) ||                   // avoid duplicate message
        $data['user']===$usersByUsername['friday']->getId() ) { // not your own messages, friday :(
        unset($messageStack[$messageKey]);
        return;
    }
    $messageStack[$messageKey] = $messageKey;

    // extract the actual command
    $data['cmd'] = commandFromMentionMessages($fridayId, $data['text']);
    echo "Incoming message: ".$data['cmd']."\n";

    // only talk to me for now ok?
    if($data['user']!==$usersByUsername['tenshi13']->getId() ) {
        $response = "Sorry, master said not to talk to strangers for now...";

        $client->getDMById ($data['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
            $client->send($response, $channel);
        });
        return;
    }

    // invalid command
    if( !isValidCommand($data['cmd']) ) {
        $response = "Sorry, I do not understand that command yet";

        $client->getDMById ($data['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
            $client->send($response, $channel);
        });
        return;
    }

    try {
        $response = commandRouter($client, $data);
        $client->getDMById ($data['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $message) {
            $client->send($response, $channel);
        });
    }catch (Exception $e) {
        $client->getDMById ($data['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $message) {
            $client->send($response, $channel);
        });
    }

//    if ($data['text']==='jira summary') {
//        $message = "None";
//        $completedTasks = reqTaskCompletedInPass2Days();
//        if( is_array ($completedTasks) && count($completedTasks) > 0 ) {
//            $message = outputSummary( $completedTasks );
//        }
//        echo $message;
//
//        $client->getDMById ($data['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $message) {
//            $client->send($message, $channel);
//        });
//
//        curl_close($ch);
//    }

//    if( isMentionMessages($client->bot_id, $data['text']) &&
//        $data['user']==$client->getMasterId()) {
//
//        echo "here".$data['text'];
//    }
});

function isMentionMessages($botId, $message) {
    if( substr( $message, 0, strlen($botId)+3 ) === "<@".$botId.">" ) {
        return true;
    }
    return false;
}

function commandFromMentionMessages($botId, $message) {
    return trim(str_replace ("<@".$botId.">", '', $message));
}

function isValidCommand($inputCommand) {
    global $commands;

    foreach($commands as $cmdKey => $commandFormat) {
        if( substr( $inputCommand, 0, strlen ($cmdKey) ) === $cmdKey ) {
            return true;
        }
    }

    return false;
}

function commandRouter($client, $request) {
    global $commands;
    $inputCommand = $request['cmd'];

    // get the right command
    $command = '';
    $args    = false;
    foreach($commands as $cmdKey => $commandFormat) {
        if( substr( $inputCommand, 0, strlen ($cmdKey) ) === $cmdKey ) {
            $command = $cmdKey;
            // build the command arguements if there's any
            if( strlen($inputCommand) > strlen ($cmdKey) ) {
                $rawArgs = explode(" ", trim(substr( $inputCommand, strlen ($cmdKey), strlen ($inputCommand) )));

                // build option againts option value
                $args = [];
                for($a=0;$a<count($rawArgs);$a+=2) {
                    $args[ $rawArgs[$a] ] = $rawArgs[ $a+1 ];
                }
            }
        }
    }

    $functionName =  lcfirst(str_replace (' ', '', ucwords(trim($command))));
    echo "Calling $functionName \n";

    if( $functionName=='' ||
        !function_exists($functionName) ){
        throw Exception("Sorry, invalid command...");
    }

    try {
        $response = call_user_func_array($functionName, [$client, $request, $args]);
        $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
            $client->send($response, $channel);
        });
    } catch (Exception $e) {
        $response = "Command failed";
        $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
            $client->send($response, $channel);
        });
    }

}

function reinit($client) {
    global $users, $usersByUsername;

    $users = $client->getUsers();
    foreach($users as $user) {
        $usersByUsername[$user->getUsername()] = $user;
    }

}

function listBots($client, $request, $args = null) {
    $response =  "Ok, listing bots \n";
    $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
        $client->send($response, $channel);
    });

    $bots = $client->getBots ();
    $response = '';
    foreach($bots as $bot){
        /**
         * @Var Slack\Bot
         */
        $response .= "Bot : ".$bot->getId()." - ".$bot->getName()."\n";
    }

    return $response;
}

function listUsers($client, $request, $args = null) {
    $response =  "Ok, listing bots \n";
    $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
        $client->send($response, $channel);
    });

    /**
     * @Var Slack\User
     */
    $users = $client->getUsers();
    $response = '';
    foreach($users as $user){
        $response .= "User : ".$user->getId()." - ".$user->getUsername()."\n";
    }

    return $response;
}

function jiraSummary($client, $request, $args = null) {
    global $commands, $ch;

    /**
    $response =  "Accessing Jira and Compiling Summary... \n";
    $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
        $client->send($response, $channel);
    });
    **/

    $response = "Sorry, seems like there is no task for the pass ".(int)$args['-d']." days...";
    $completedTasks = reqTaskCompletedInPassXDays( (int)$args['-d'] );
    if( is_array ($completedTasks) && count($completedTasks) > 0 ) {
        $response = outputSummary( $completedTasks );
    }
    echo $response;

    $client->getDMById ($request['channel'])->then (function (\Slack\DirectMessageChannel $channel) use ($client, $response) {
        $client->send($response, $channel);
    });

    curl_close($ch);
}

function outputSummary($completedTasks) {
    $message = '';

    if( is_array($completedTasks) && count($completedTasks) > 0 ) {
        foreach($completedTasks as $epicName => $tasks) {
            $message .= "[".$epicName."]\n";

            foreach($tasks as $task) {
                $message .= " - ".$task['key']." ".$task['summary']."\n";
            }
            $message .= "\n";
        }
    } else {
      $message = 'None';
    }

    return $message;
}

function reqTaskCompletedInPassXDays($passedDays) {
    // https://pacificbrands.atlassian.net/issues/?filter=14606
    //$jql = 'resolution is not EMPTY AND resolutiondate >= -'.$passedDays.'d AND issuetype not in subTaskIssueTypes() AND resolution = Done AND sprint in openSprints() ORDER BY cf[10011] ASC, type';
    //$jql = 'resolution is not EMPTY AND resolutiondate >= -7d AND issuetype not in subTaskIssueTypes() AND resolution = Done ORDER BY cf[10011] ASC, type';
      $jql = 'resolution is not EMPTY AND resolutiondate >= -'.$passedDays.'d AND issuetype not in subTaskIssueTypes() AND resolution = Done ORDER BY cf[10011] ASC, type';

    echo "Query : ".$jql."\n";

    $results = jiraApiReq($jql);

    // compile tasks
    $tasks = [];
    $epiclinks = [];

    // no results
    if( !is_array ($results) || count($results)<=0 ) return false;

    foreach( $results as $result ) {
        $key       = $result['key'];
        $summary   = $result['fields']['summary'];
        $issuetype = trim(strtolower ($result['fields']['issuetype']['name']));
        if($issuetype==='bug' &&
           strtolower(substr( $summary, 0, 3 )) !== "fix" ) {
            $summary = "Fix : ".$summary;
        }

        $epiclink  = 'BAU';
        if( isset($result['fields']['customfield_10011']) && trim($result['fields']['customfield_10011'])!=='' ) {
            $epiclink = trim($result['fields']['customfield_10011']);
            $epiclinks [$epiclink]= $epiclink; // unique
        }

        $tasks[$epiclink][] = [ 'key'       => $key,
                                'summary'   => $summary,
                                'issuetype' => $issuetype ];
    }

    // grab the epic link's epic name
    return reqEpicLinks($epiclinks, $tasks);
}

function reqEpicLinks($epiclinks, $tasks) {
    $epicTaskKeys = implode (", ", $epiclinks);
    $jql = 'key in ('.$epicTaskKeys.')';
//    $fields = ["key",
//               "customfield_10010"];

    $completedTasks = [];
    $results = jiraApiReq($jql);

    foreach($results as $result) {
        if( !isset($result['fields']['customfield_10010']) || trim($result['fields']['customfield_10010'])==='' )continue;
        $key = $result['key'];
        $epicName = $result['fields']['customfield_10010'];

        $epicsTasks = $tasks[$key];
        $completedTasks[$epicName] = $epicsTasks;
    }

    $completedTasks['BAU'] = $tasks['BAU'];

    return $completedTasks;
}

function jiraApiReq($jql, $fields = null) {
    global $config, $ch;

    if(!$ch) {
        $ch = curl_init();
    }

    $data = ['jql' => $jql,
             'startAt' => 0,
             'maxResults' => '50',
             'validateQuery' => 'warn',
             'fieldsByKeys' => false
    ];
    if(isset($fields)){
        $data['fields'] = $fields;
    }

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json'
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_VERBOSE, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_URL, $config['jira_rest_url']);
    curl_setopt($ch, CURLOPT_USERPWD, $config['jira_login'].':'.$config['jira_token']);

    $result = curl_exec($ch);
    $ch_error = curl_error($ch);

    if ($ch_error) {
        echo "cURL Error: $ch_error";
        exit;
    } else {
        //echo $result;
    }

    return json_decode($result, true)['issues'];
}

$loop->run();
