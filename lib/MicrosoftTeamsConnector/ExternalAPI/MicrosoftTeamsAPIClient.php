<?php

namespace Inbenta\MicrosoftTeamsConnector\ExternalAPI;

use Exception;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Inbenta\ChatbotConnector\Utils\DotAccessor;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\MicrosoftTeamsConnector\ExternalDigester\MicrosoftTeamsDigester;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

class MicrosoftTeamsAPIClient
{

    const OAUTH_LOGIN_URL = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/token';
    const OAUTH_SCOPE_URL = 'https://api.botframework.com/.default';

    /**
     * The auth token.
     *
     * @var array
     */
    protected $authToken;

    /**
     * Bot Framework base activity structure to send back response
     *
     * @var array|null
     */
    protected $activity;

    /**
     * Target URL and endpoint
     *
     * @var array|null
     */
    protected $targetEndpoint;

    /**
     * Teams user who sends the message
     *
     * @var array|null
     */
    protected $sender;

    /**
     * Teams channel to send back response to.
     *
     * @var string|null
     */
    protected $channel;

    /**
     * SessionManager instance
     *
     * @var SessionManager|null
     */
    protected $session;

    /**
     * App conf
     *
     * @var DotAccessor
     */
    public $conf;

    /**
     * Create a new instance.
     *
     * @param array $requestBody Request body array
     * @param DotAccessor|null $conf Config using {@link DotAccessor}
     * @param SessionManager $session The {@link SessionManager} instance
     *
     * @throws Exception if secret or app id are not set in conf
     * @throws GuzzleException
     */
    public function __construct(array $requestBody, DotAccessor $conf, SessionManager $session)
    {
        $this->conf = $conf;
        if (empty($this->conf->get('api.microsoft_appid')) || empty(
            $this->conf->get(
                'api.microsoft_secret'
            )
            )) {
            throw new Exception(
                "Empty MICROSOFT appid or secret. Please, review your /conf/custom/api.php file"
            );
        }

        if (is_null($session)) {
            throw new Exception("Cannot have empty session");
        }

        $this->session = $session;

        $this->authToken = $this->getAuthToken();
        $this->setActivityFromRequest($requestBody);
        $this->setTargetFromRequest($requestBody);
        $this->setSenderFromRequest($requestBody);
        $this->setChannelFromRequest($requestBody);
    }

    /**
     * Define the current sender from the incoming request
     *
     * @param array $requestBody Request body array
     */
    protected function setSenderFromRequest(array $requestBody)
    {
        $sender = $this->session->get("teamsSender", ["id" => "", "name" => ""]);

        if (empty($sender['id']) && empty($sender['name'])) {
            $from = isset($requestBody['from']) ? $requestBody['from'] : false;
            $sender = ["id" => "", "name" => ""];

            if ($from) {
                $sender['id'] = isset($from['id']) ? $from['id'] : "";
                $sender['name'] = isset($from['name']) ? $from['name'] : "";
            }
        }

        $this->sender = $sender;
        $this->session->set("teamsSender", $this->sender);
    }

    /**
     * Establishes the Teams channel from an incoming Teams request
     *
     * @param array $requestBody Request body array
     */
    protected function setChannelFromRequest(array $requestBody)
    {
        $channel = $this->session->get("teamsChannel", false);

        if (!$channel) {
            $channel = isset($requestBody['conversation']['id']) ? $requestBody['conversation']['id'] : false;
            file_put_contents('/tmp/channel', $channel);
        }

        $this->channel = $channel;
        $this->session->set("teamsChannel", $this->channel);
    }

    /**
     * Define the current activity from the incoming request
     *
     * @param array $requestBody Request body array
     */
    protected function setActivityFromRequest(array $requestBody)
    {
        // get activity from session in case of Hyperchat
        $activity = $this->session->get(
            "teamsActivity",
            [
                "type" => "",
                "from" => "",
                "recipient" => "",
                "channelId" => "",
                "conversation" => ""
            ]
        );

        if (empty($activity['type']) && empty($activity['from']) && empty($activity['channelId'])
            && empty($activity['recipient']) && empty($activity['conversation'])) {
            // if empty get from request
            $activity['type'] = 'message';
            $activity['from'] = $requestBody['recipient'];
            $activity['recipient'] = $requestBody['from'];
            $activity['channelId'] = $requestBody['channelId'];
            $activity['conversation'] = $requestBody['conversation'];
        }

        $this->activity = $activity;
        $this->session->set("teamsActivity", $this->activity);
    }

    /**
     * Define the current target from the incoming request
     *
     * @param array $requestBody Request body array
     */
    protected function setTargetFromRequest(array $requestBody)
    {
        $target = $this->session->get("teamsTarget", ["base_url" => "", "endpoint" => ""]);

        if (empty($target['base_url']) && empty($target['endpoint'])) {
            $target = [];

            $target['base_url'] = $requestBody['serviceUrl'];
            $target['endpoint'] = $requestBody['conversation']['id'];
        }

        $this->targetEndpoint = $target;
        $this->session->set("teamsTarget", $this->targetEndpoint);
    }

    /**
     * Retrieves the user id from the external ID generated by the getExternalId method
     *
     * @param string $externalId
     *
     * @return mixed|null
     */
    public static function getIdFromExternalId(string $externalId)
    {
        $teamsInfo = explode('-', $externalId);
        if (array_shift($teamsInfo) == 'teams') {
            return end($teamsInfo);
        }
        return null;
    }

    /**
     * Returns properties of the sender object when the $key parameter is provided (and exists).
     * If no key is provided will return the whole object
     *
     * @param null|string $key
     *
     * @return null|mixed
     */
    public function getSender($key = null)
    {
        $sender = $this->sender;

        if ($key) {
            if (isset($sender[$key])) {
                return $sender[$key];
            }
            return null;
        } else {
            return $sender;
        }
    }

    /**
     * Returns the full name of the user (first + last name)
     *
     * @return string | null
     */
    public function getFullName(): ?string
    {
        if (!$this->getSender('name') && $this->getSender('id')) {
            $this->setSenderFromId($this->getSender('id'));
        }

        return $this->getSender('name');
    }

    /**
     * Return the current user email address if available
     *
     * @return string
     */
    public function getEmail(): ?string
    {
        if (!$this->getSender('email') && $this->getSender('id')) {
            $this->setSenderFromId($this->getSender('id'));
        }
        return $this->getSender('email');
    }

    /**
     * Get the user email from Graph API
     * @param string $senderId
     * @return string $email
     */
    public function getUserEmailFromGraph(string $senderId)
    {
        $email = "";
        $url = "{$this->targetEndpoint['base_url']}v3/conversations/{$this->targetEndpoint['endpoint']}/pagedmembers";
        $response = $this->request(
            'GET',
            $url,
        );
        if (method_exists($response, "getBody") && method_exists($response->getBody(), "getContents")) {
            $members = json_decode($response->getBody()->getContents());
            if (isset($members->members) && is_array($members->members)) {
                foreach ($members->members as $member) {
                    if (isset($member->userPrincipalName) && isset($member->id)) {
                        if ($member->id == $senderId) {
                            $email = $member->userPrincipalName;
                            break;
                        }
                    }
                }
            }
        }
        return $email;
    }

    /**
     * Generates the external id used by HyperChat to identify one user as external.
     * This external id will be used by HyperChat adapter to instance this client class from the external id
     *
     * @return string
     */
    public function getExternalId(): string
    {
        return 'teams-' . $this->channel . '-' . $this->getSender('id');
    }

    /**
     * This returns the external id
     *
     * @param array $request
     *
     * @return string|null
     */
    public static function buildExternalIdFromRequest(array $request): ?string
    {
        $user = isset($request['from']['id']) ? $request['from']['id'] : false;
        $channel = isset($request['conversation']['id']) ? $request['conversation']['id'] : false;
        if ($user && $channel) {
            return "teams-$channel-$user";
        }

        return null;
    }

    /**
     * Get the access token.
     *
     * @return array
     *
     * @throws GuzzleException
     */
    protected function getAuthToken(): array
    {
        if (!$this->authToken) {
            $response = (new Guzzle())->request(
                'POST',
                self::OAUTH_LOGIN_URL,
                [
                    'verify' => false,
                    'form_params' => [
                        'client_id' => $this->conf->get('api.microsoft_appid'),
                        'client_secret' => $this->conf->get('api.microsoft_secret'),
                        'grant_type' => 'client_credentials',
                        'scope' => self::OAUTH_SCOPE_URL,
                    ],
                    'header' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                ]
            );

            $this->authToken = json_decode($response->getBody(), true);
        }

        return $this->authToken;
    }

    /**
     * Send a request to the Bot Framework API.
     *
     * @param string $method
     * @param string $url
     * @param array $data
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    protected function request(string $method, string $url, array $data = []): ?ResponseInterface
    {
        $auth = $this->getAuthToken();
        file_put_contents('/tmp/debug', $auth['access_token']);
        $options = array_merge_recursive(
            $data,
            [
                'verify' => false,
                'headers' => [
                    'Authorization' => "{$auth['token_type']} {$auth['access_token']}",
                    'Content-Type' => 'application/json',
                ],
            ]
        );
        try {
            $client = new Guzzle();
            return $client->request($method, $url, $options);
        } catch (ClientException $exception) {
            error_log("Request error: " . $exception->getResponse()->getBody()->getContents());
            die();
        } catch (Exception $e) {
            error_log("Request error: " . $e->getMessage());
            die();
        }
    }

    /**
     * Generates a text message from a string and sends it to Teams
     *
     * @param string $message Simple text message
     *
     * @throws GuzzleException
     */
    public function sendTextMessage(string $message)
    {
        $this->sendMessage(['text' => $message]);
    }

    /**
     * Generate a message from a file and sends it to Teams
     *
     * @param array $attachment Attachment information
     *
     * @throws GuzzleException
     */
    public function sendAttachmentMessageFromHyperChat(array $attachment)
    {
        if (strpos($attachment['type'], "image") !== false) {
            // send as a simple image
            $this->sendMessage(
                [
                    "attachments" => [
                        [
                            "contentType" => $attachment['type'],
                            "contentUrl" => $attachment["contentBase64"],
                            "name" => $attachment["name"]
                        ]
                    ]
                ]
            );
        } else {
            // every other type of file
            $this->sendMessage(
                [
                    "body" => [
                        [
                            "type" => "TextBlock",
                            "text" => "[{$attachment['name']}]({$attachment['fullUrl']})"
                        ]
                    ]
                ]
            );
        }
    }

    /**
     * Send an outgoing message.
     *
     * @param array $message Teams message to send
     *
     * @return ResponseInterface|null
     *
     * @throws GuzzleException
     */
    public function send(array $message)
    {
        $outgoingActivity = $this->activity;
        $url = "{$this->targetEndpoint['base_url']}v3/conversations/{$this->targetEndpoint['endpoint']}/activities";

        // Build activity
        // If text provided, add it
        if (isset($message['text'])) {
            $outgoingActivity['text'] = $message['text'];
        } else {
            $outgoingActivity['text'] = '';
        }
        // If body provided, create an adaptive card
        if (isset($message['body']) && !empty($message['body'])) {
            $outgoingActivity['attachments'] = [];
            $outgoingActivity['attachments'][] = [
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    'version' => '1.0',
                    'body' => $message['body'],
                ],
            ];
            if (isset($message['actions'])) {
                $outgoingActivity['attachments'][0]['content']['actions'] = $message['actions'];
            }
        } // If attachment provided, create activity directly
        elseif (isset($message['attachments'])) {
            $outgoingActivity['attachments'] = $message['attachments'];
        } elseif (isset($message['type']) && $message['type'] === 'typing') {
            $outgoingActivity['type'] = 'typing';
        }

        if (trim($outgoingActivity['text']) !== '' || isset($outgoingActivity['attachments']) || $outgoingActivity['type'] === 'typing') {
            $response =  $this->request(
                'POST',
                $url,
                [
                    'json' => $outgoingActivity,
                ]
            );

            // send related if needed
            if(isset($message['related'])) {
                $relatedActivity = $this->activity;
                $relatedActivity['text'] = '';
                $relatedActivity['attachments'] = $message['related'];
                if (
                    isset($relatedActivity['attachments'][0]) && isset($relatedActivity['attachments'][0]['content']) &&
                    isset($relatedActivity['attachments'][0]['content']['title'])
                ) {
                    $relatedActivity['text'] = $relatedActivity['attachments'][0]['content']['title'];
                    unset($relatedActivity['attachments'][0]['content']['title']);
                }
                $this->request(
                    'POST',
                    $url,
                    [
                        'json' => $relatedActivity
                    ]
                );
            }

            return $response;
        } else {
            return true;
        }
    }

    /**
     * Establishes the Teams sender (user) directly with the provided ID
     *
     * @param string $senderID
     */
    public function setSenderFromId(string $senderID)
    {
        // TODO Get user from Graph API
//        $this->sender = $this->user($senderID);
        $this->sender['id'] = $senderID;
    }

    /**
     * Handles the hook challenge sent by Microsoft to ensure that we're the owners of the Teams app.
     * Requires the request body sent by the Teams app
     *
     * @param array $requestBody Request body array
     */
    public static function hookChallenge(array $requestBody)
    {
        if (isset($requestBody['challenge'])) {
            echo $requestBody['challenge'];
            die();
        }
    }

    /**
     * Sends a flag to Teams to display a notification alert as the bot is 'writing'
     * This method can be used to disable the notification if a 'false' parameter is received
     *
     * @param bool $show Show or hide value
     *
     * @return null
     */
    public function showBotTyping($show = true)
    {
        $message = ['type' => 'typing'];
        $this->send($message);
        return null;
    }

    /**
     *   Sends a message to Teams. Needs a message formatted with the Teams notation
     *
     * @param array $message
     *
     * @return ResponseInterface|null
     * @throws GuzzleException
     */
    public function sendMessage(array $message)
    {
        $this->showBotTyping(true);
        return $this->send($message);
    }

    /**
     * Converts an HTML-formatted text into markdown.
     *
     * @param string $text
     *
     * @return string
     */
    public function toMarkdown(string $text)
    {
        $content = str_replace(">\n", '>', $text);
        $content = str_replace("\n<", '<', $content);
        $content = str_replace("\t", '', $content);
        $content = strip_tags($text, '<br><strong><em><del><li><code><pre><a></a><p></p><ul></ul>');
        $content = str_replace("\n", '', $content);
        $content = str_replace(array('<br />', '<br>'), "\n", $content);
        $content = str_replace(array('<strong>', '</strong>'), array('*', '*'), $content);
        $content = str_replace(array('<p>', '</p>'), array('', "\n"), $content);
        $content = str_replace(array('<em>', '</em>'), array('_', '_'), $content);
        $content = str_replace(array('<del>', '</del>'), array('~', '~'), $content);
        $content = str_replace(array('<li>', '</li>'), array(' -', "\n"), $content);
        $content = str_replace(array('<ul>', '</ul>'), array("\n", "\n"), $content);
        $content = str_replace(array('<code>', '</code>'), array('`', '`'), $content);
        $content = str_replace(array('<pre>', '</pre>'), array('```', '```'), $content);
        preg_match_all('/<a href=\"(.*?)\">(.*?)<\/a>/i', $content, $res);
        for ($i = 0; $i < count($res[0]); $i++) {
            $content = str_replace($res[0][$i], '<' . $res[1][$i] . '|' . $res[2][$i] . '>', $content);
        }
        return $content;
    }
}
