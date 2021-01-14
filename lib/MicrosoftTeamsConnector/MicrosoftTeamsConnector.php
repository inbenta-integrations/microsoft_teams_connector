<?php

namespace Inbenta\MicrosoftTeamsConnector;

use Exception;
use Inbenta\ChatbotConnector\{
    ChatbotAPI\ChatbotAPIClient,
    ChatbotConnector,
    Utils\SessionManager
};
use Inbenta\MicrosoftTeamsConnector\{
    ExternalAPI\MicrosoftTeamsAPIClient,
    ExternalDigester\MicrosoftTeamsDigester,
    HyperChatAPI\MicrosoftTeamsHyperChatClient
};
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Request;

class MicrosoftTeamsConnector extends ChatbotConnector
{

    /**
     * Request instance
     *
     * @var Request
     */
    protected $request;

    /**
     * Parsed request body array
     *
     * @var array
     */
    protected $parsedBody;

    /**
     * Constructor
     *
     * @param string $appPath Application path string
     *
     * @throws GuzzleException
     */
    public function __construct(string $appPath)
    {
        // Initialize and configure specific components for MicrosoftTeams
        try {
            parent::__construct($appPath);

            $this->request = Request::createFromGlobals();
            $this->parsedBody = json_decode($this->request->getContent(), true);
            $this->parsedBody = is_null($this->parsedBody) ? [] : $this->parsedBody;

            // Initialize base components
            $conversationConf = [
                'configuration' => $this->conf->get('conversation.default'),
                'userType' => $this->conf->get('conversation.user_type'),
                'environment' => $this->environment,
                'source' => $this->conf->get('conversation.source')
            ];

            $this->session = new SessionManager($this->getExternalIdFromRequest($this->parsedBody));
            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('teams', 'translations');

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled')) {
                $chatEventsHandler = new MicrosoftTeamsHyperChatClient(
                    $this->conf->get('chat.chat'),
                    $this->lang,
                    $this->session,
                    $this->conf,
                    $this->externalClient,
                    $this->parsedBody
                );
                $chatEventsHandler->handleChatEvent();
            }

            // Handle MicrosoftTeams verification challenge, if needed
            MicrosoftTeamsAPIClient::hookChallenge($this->parsedBody);

            // Instance application components
            // Instance MicrosoftTeams client
            $externalClient = new MicrosoftTeamsAPIClient(
                $this->parsedBody,
                $this->conf,
                $this->session
            );
            // Instance HyperchatClient for MicrosoftTeams
            $chatClient = new MicrosoftTeamsHyperChatClient(
                $this->conf->get('chat.chat'),
                $this->lang,
                $this->session,
                $this->conf,
                $externalClient,
                $this->parsedBody
            );
            // Instance MicrosoftTeams digester
            $externalDigester = new MicrosoftTeamsDigester(
                $this->lang,
                $this->conf->get('conversation.digester'),
                $this->session
            );

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            error_log($e->getMessage());
            die();
        }
    }

    /**
     * Handle a request (from external service or from Hyperchat).
     * Custom to easily take send Actionfield form answers (from dropdown, or input button).
     *
     * @throws Exception
     */
    public function handleRequest()
    {
        // if there is an action field, tweak answer format
        if (isset($this->parsedBody['value']) && isset($this->parsedBody['value']['ACTIONFIELD'])) {
            $message = [
                'type' => 'answer',
                'message' => $this->parsedBody['value']['ACTIONFIELD']
            ];
            // Store the last user text message to session
            $this->saveLastTextMessage($message);
            $botResponse = $this->sendMessageToBot($message);
            $this->sendMessagesToExternal($botResponse);
        } else {
            parent::handleRequest();
        }
    }

    /**
     * Return external id from request (Hyperchat of MicrosoftTeams)
     *
     * @param array $request
     *
     * @return string|string[]|null
     *
     * @throws Exception On the last try of obtaining the External ID
     */
    protected function getExternalIdFromRequest(array $request)
    {
        // Try to get user_id from a Microsoft Teams message request
        $externalId = MicrosoftTeamsAPIClient::buildExternalIdFromRequest($request);
        if (is_null($externalId)) {
            // Try to get user_id from a Hyperchat event request
            $externalId = MicrosoftTeamsHyperChatClient::buildExternalIdFromRequest(
                $request,
                $this->conf->get('chat.chat')
            );
        }

        if (empty($externalId)) {
            $api_key = $this->conf->get('api.key');
            if (isset($request['challenge'])) {
                // Create a temporary session_id from a Teams webhook linking request
                $externalId = "teams-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
            } elseif (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
                // Create a temporary session_id from a HyperChat webhook linking request
                $externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
            } else {
                throw new Exception("Invalid request");
            }
        }
        // Remove illegal characters
        $externalId = preg_replace("/[^a-zA-Z0-9]/", "", $externalId);
        return $externalId;
    }

    /**
     * @inheritDoc
     */
    protected function sendEventToBot($event)
    {
        $bot_tracking_events = ['rate', 'click'];
        if (!in_array($event['type'], $bot_tracking_events)) {
            error_log(
                'ERROR ! event ' . $event['type'] . ' not in whitelist ' . implode(
                    '|',
                    $bot_tracking_events
                )
            );
            die();
        }

        $this->botClient->trackEvent($event);
        switch ($event['type']) {
            case 'rate':
                $askingRatingComment = $this->session->has(
                        'askingRatingComment'
                    ) && $this->session->get(
                        'askingRatingComment'
                    ) != false;
                $willEscalate = $this->shouldEscalateFromNegativeRating() && $this->checkAgents();
                if ($askingRatingComment && !$willEscalate) {
                    // Ask for a comment on a content-rating
                    $response = $this->buildTextMessage(
                        $this->lang->translate('ask_rating_comment')
                    );
                } else {
                    // Forget we were asking for a rating comment
                    $this->session->set('askingRatingComment', false);
                    // Send 'Thanks' message after rating
                    $response = $this->buildTextMessage($this->lang->translate('thanks'));
                }

                break;
        }

        return $response;
    }

    /**
     * Custom to disable ratings for new flag
     * Check if a bot response should display content-ratings
     *
     * @param object $botResponse
     *
     * @return false
     */
    protected function checkContentRatings($botResponse)
    {
        $ratingConf = $this->conf->get('conversation.content_ratings');
        if (!$ratingConf['enabled']) {
            return false;
        }

        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }

        // Check messages are answer and have a rate-code
        $rateCode = false;
        $isBlacklistedFlag = false;
        foreach ($messages as $msg) {
            $isAnswer = isset($msg->type) && $msg->type == 'answer';
            // list flags for which we do not need to send ratings
            $blacklistedFlag = [
                'escalate',
                'no-rating',
                'follow-up-question',
                'end-form'
            ];
            if (isset($msg->flags)) {
                foreach ($msg->flags as $flag) {
                    if (in_array($flag, $blacklistedFlag)) {
                        $isBlacklistedFlag = true;
                        break;
                    }
                }
            }
            $hasRatingCode = isset($msg->parameters) &&
                isset($msg->parameters->contents) &&
                isset($msg->parameters->contents->trackingCode) &&
                isset($msg->parameters->contents->trackingCode->rateCode);

            if ($isAnswer && $hasRatingCode && !$isBlacklistedFlag) {
                $rateCode = $msg->parameters->contents->trackingCode->rateCode;
            }
        }
        return $rateCode;
    }

}
