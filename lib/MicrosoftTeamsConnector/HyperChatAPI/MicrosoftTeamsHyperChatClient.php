<?php

namespace Inbenta\MicrosoftTeamsConnector\HyperChatAPI;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\ChatbotConnector\Utils\DotAccessor;
use Inbenta\ChatbotConnector\Utils\LanguageManager;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\MicrosoftTeamsConnector\ExternalAPI\MicrosoftTeamsAPIClient;

class MicrosoftTeamsHyperChatClient extends HyperChatClient
{
    /**
     * SessionManager instance
     *
     * @var SessionManager
     */
    protected $session;

    /**
     * Request body array
     *
     * @var array
     */
    protected $requestBody;

    /**
     * MicrosoftTeamsHyperChatClient constructor.
     *
     * @param array $config
     * @param LanguageManager $lang
     * @param SessionManager $session
     * @param DotAccessor $appConf
     * @param ?object $externalClient
     * @param array $requestBody
     *
     * @throws GuzzleException
     */
    public function __construct(
        array $config,
        LanguageManager $lang,
        SessionManager $session,
        DotAccessor $appConf,
        $externalClient,
        array $requestBody
    ) {
        if ($config['enabled']) {
            $this->session = $session;
            $this->requestBody = $requestBody;
            //If external client hasn't been initialized, make a new instance
            if (is_null($externalClient)) {
                // Check if Hyperchat event data is present
                if (!isset($requestBody['trigger'])) {
                    return;
                }

                //Obtain user external id from the chat event
                $externalId = self::getExternalIdFromEvent($config, $requestBody);
                if (is_null($externalId)) {
                    return;
                }

                //Instance External Client
                $externalClient = $this->instanceExternalClient($externalId, $appConf);
            }
            parent::__construct($config, $lang, $this->session, $appConf, $externalClient);
        }
    }

    /**
     * Instances an external client
     *
     * @param $externalId
     * @param $appConf
     *
     * @return ?MicrosoftTeamsAPIClient
     *
     * @throws Exception
     * @throws GuzzleException
     */
    protected function instanceExternalClient($externalId, $appConf): ?MicrosoftTeamsAPIClient
    {
        $externalId = MicrosoftTeamsAPIClient::getIdFromExternalId($externalId);
        if (is_null($externalId)) {
            return null;
        }
        $externalClient = new MicrosoftTeamsAPIClient($this->requestBody, $appConf, $this->session);
        $externalClient->setSenderFromId($externalId);
        return $externalClient;
    }

    /**
     * Build external ID used in session from the given request body array
     *
     * @param array $requestBody Request body array
     * @param array $config Config array
     *
     * @return ?string
     */
    public static function buildExternalIdFromRequest(array $requestBody, array $config): ?string
    {
        $externalId = null;
        if (isset($requestBody['trigger'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $requestBody);
        }
        return $externalId;
    }
}
