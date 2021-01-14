<?php

namespace Inbenta\MicrosoftTeamsConnector\ExternalDigester;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use \Exception;
use Inbenta\ChatbotConnector\Utils\LanguageManager;
use Inbenta\MicrosoftTeamsConnector\Utils\HTMLTablesToImage;

class MicrosoftTeamsDigester extends DigesterInterface
{

    protected $conf;
    protected $channel;
    protected $session;

    /**
     * @var LanguageManager
     */
    protected $langManager;

    protected $externalMessageTypes = [
        'button',
        'quickReply',
        'payload',
        'attachment',
        'messageReaction',
        'cardItem',
        'text',
    ];

    protected $apiMessageTypes = [
        'actionfield',
        'answer',
        'polarQuestion',
        'multipleChoiceQuestion',
        'extendedContentsAnswer',
    ];

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'MsTeams';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     * Returns the name of the channel
     *
     * @return string
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * @inheritDoc
     */
    public static function checkRequest($request)
    {
        $request = json_decode($request);

        $isPage = isset($request->object) && $request->object == "page";
        $isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
        if ($isPage && $isMessaging && count((array)$request->entry[0]->messaging)) {
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function digestToApi($request)
    {
        $request = json_decode($request);

        if (is_null($request) || !isset($request->serviceUrl) && (!isset($request->text) || !isset($request->value))) {
            return [];
        }

        $output = [];
        $msgType = $this->checkExternalMessageType($request);

        $digester = 'digestFromMsTeams' . ucfirst($msgType);

        // Check if there are more than one responses from one incoming message
        $digestedMessage = $this->$digester($request);

        // Handle multiple messages
        if (isset($digestedMessage['multiple_output'])) {
            foreach ($digestedMessage['multiple_output'] as $message) {
                $output[] = $message;
            }
        } else {
            $output[] = $digestedMessage;
        }
        return $output;
    }

    /**
     * @inheritDoc
     *
     * @throws Exception
     */
    public function digestFromApi($request, $lastUserQuestion)
    {
        // Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = ['answers' => $request];
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            // format message to add related if exist
            $msg->related = (isset($msg->parameters->contents) && isset($msg->parameters->contents->related)) ? $msg->parameters->contents->related : false;
            $msg->title = isset($msg->parameters->contents->title) ? $msg->parameters->contents->title : "";
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

            // Check if there are more than one responses from one incoming message
            if (isset($digestedMessage['multiple_output'])) {
                $output[]['body'] = [];
                foreach ($digestedMessage['multiple_output'] as $message) {
                    $output[]['body'][] = $message['body'];
                }
            } else {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     * Generate a base64 image from an HTML Table element
     *
     * @param DOMNode|DOMElement $element
     *
     * @return string base64 file encoded
     */
    protected function generateTableImage(DOMNode $element): string
    {
        $image = HTMLTablesToImage::createImageFromTable($element);
        return HTMLTablesToImage::imageResourceToJpgBase64($image);
    }

    /**
     * Classifies the external message into one of the defined $externalMessageTypes
     *
     * @param $message
     *
     * @return string
     */
    protected function checkExternalMessageType($message)
    {
        foreach ($this->externalMessageTypes as $type) {
            $checker = 'isMsTeams' . ucfirst($type);
            if ($this->$checker($message)) {
                return $type;
            }
        }
        return '';
    }

    /**
     * Classifies the API message into one of the defined apiMessageTypes
     * Can be : 'answer', 'polarQuestion', 'multipleChoiceQuestion' or 'extendedContentsAnswer'
     *
     * @param $message
     *
     * @return string|null
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    //region MS Teams Message Type Checkers

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsText($message)
    {
        return ($message->type === "message" && isset($message->text));
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsCardItem($message)
    {
        return isset($message->value) && isset($message->value->action);
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsButton($message)
    {
        return isset($message->type) && ($message->type === 'button');
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsPayload($message)
    {
        return isset($message->channelData)
            && isset($message->channelData->postBack)
            && $message->channelData->postBack === true;
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsQuickReply($message)
    {
        return isset($message->value) && isset($message->value->option);
    }

    /**
     * @param $message
     *
     * @return false
     */
    protected function isMsTeamsAttachment($message)
    {
        return false;
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isMsTeamsMessageReaction($message)
    {
        return isset($message->type) && ($message->type === 'messageReaction');
    }

    //endregion

    //region API Message Type Checkers

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isApiActionField($message)
    {
        return $message->type == 'answer' && isset($message->actionField) && !empty($message->actionField);
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    /**
     * @param $message
     *
     * @return bool
     */
    protected function hasTextMessage($message)
    {
        return isset($message->message) && is_string($message->message);
    }

    //endregion

    //region MS Teams Message Digesters

    /**
     * @param $message
     */
    protected function digestFromMsTeams($message)
    {
        // Called if message type not found
        return [
            'message' => 'x'
        ];
    }

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromMsTeamsCardItem($message)
    {
        return [
            'message' => '',
            'value' => $message->value,
        ];
    }


    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromMsTeamsText($message)
    {
        $result = [];

        if (isset($message->value)) {
            if (isset($message->value->extendedContentAnswer)) {
                $result['extendedContentAnswer'] = $message->value->extendedContentAnswer;
            }

            if (isset($message->value->askRatingComment)) {
                $result['askRatingComment'] = $message->value->askRatingComment;
            }

            if (isset($message->value->isNegativeRating)) {
                $result['isNegativeRating'] = $message->value->isNegativeRating;
            }

            if (isset($message->value->ratingData)) {
                if (isset($message->value->ratingData->type) && isset($message->value->ratingData->data)) {
                    $result['ratingData'] = (array)$message->value->ratingData;
                    $result['ratingData']['data'] = (array)$message->value->ratingData->data;
                }
            }

            if (isset($message->value->escalateOption)) {
                $result['escalateOption'] = $message->value->escalateOption;
            }

            if (isset($message->value->option)) {
                $result[] = [
                    'option' => $message->value->option,
                    'message' => '',
                ];
            }
        }

        // do not set a message for rating event
        if (!isset($message->value->ratingData) && !isset($message->value->escalateOption)) {
            $result = [
                'message' => $message->text,
            ];
        }

        return $result;
    }

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromMsTeamsQuickReply($message)
    {
        return [
            "message" => "",
            "option" => $message->value->option
        ];
    }

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromMsTeamsButton($message)
    {
        return [
            'message' => '',
            'option' => $message->value->option,
        ];
    }

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromMsTeamsPayload($message)
    {
        return [
            'message' => '',
            'option' => (array)$message->value,
        ];
    }

    /**
     * @param $message
     *
     * @return array[]
     */
    protected function digestFromMsTeamsAttachment($message)
    {
        $attachments = [];
        foreach ($message->message->attachments as $attachment) {
            $attachments[] = ['message' => $attachment->payload->url];
        }
        return ["multiple_output" => $attachments];
    }

    /**
     * @param $message
     *
     * @return string[]
     */
    protected function digestFromMsTeamsMessageReaction($message)
    {
        $reaction = ((array)$message->reactionsAdded)[0];
        $reactionType = $reaction->type;
        $positiveReaction = [
            'like',
            'heart',
            'laugh',
        ];
        if (in_array($reactionType, $positiveReaction)) {
            return [
                'message' => $this->langManager->translate('thanks')
            ];
        }
        die();
    }
    //endregion

    //region Chatbot API Message Digesters

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromApiAnswer($message)
    {
        $output = [];
        if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "") {
            $message->message .= "\n" . $message->attributes->SIDEBUBBLE_TEXT;
        }

        // display simple text item if message do not contains HTML
        if (!$this->isHtml($message->message)) {
            $output['text'] = $message->message;
        } else {
            $body = [];
            // parse message HTML
            $nodesBlocks = $this->createNodesBlocks($message->message);

            // iterate each node
            foreach ($nodesBlocks as $nodeBlock) {
                // node are returned as array when images have been found
                if (is_array($nodeBlock) && isset($nodeBlock['src'])) {
                    // nbodeblock for media
                    if (isset($nodeBlock['mimeType'])) {
                        $body[] = [
                            "type" => "Media",
                            "poster" => "https://adaptivecards.io/content/poster-video.png",
                            "sources" => [
                                [
                                    "mimeType" => $nodeBlock['mimeType'],
                                    "url" => $nodeBlock['src']
                                ]
                            ]
                        ];
                    } else {
                        // image
                        $body[] = [
                            "type" => "Image",
                            "altText" => !empty($nodeBlock['alt']) ? $nodeBlock['alt'] : 'image',
                            "url" => $nodeBlock['src']
                        ];
                    }
                } else {
                    // transform HTML Text node to Markdown
                    $text = $this->toMarkdown($nodeBlock);
                    if (!empty($text)) {
                        $body[] = self::buildAdaptiveCardTextBlock($text);
                    }
                }
            }
            $countOnlyText = 0;
            $onlyText = [];
            foreach ($body as $element) {
                if ($element["type"] == "TextBlock") {
                    $countOnlyText++;
                    $onlyText[] = $element["text"];
                }
            }
            if ($countOnlyText === count($body)) {
                $output['text'] = implode("<br><br>", $onlyText);
            } else {
                $output['body'] = $body;
            }
        }

        // add related if needed
        if ($message->related !== false) {
            $output['related'] = $this->getRelatedBlocks($message->related);
        }
        
        return $output;
    }

    /**
     * @param $message
     *
     * @return mixed
     */
    protected function digestFromApiActionfield($message)
    {
        $output['text'] = $message->message;
        if (isset($message->actionField) && isset($message->actionField->listValues)) {
            switch ($message->actionField->listValues->displayType) {
                case 'dropdown':
                    $options = $message->actionField->listValues->values;
                    $output['text'] = $message->message;
                    $output['body'] = [self::buildAdaptiveCardChoiceSet($options, 'ACTIONFIELD')];
                    $output['actions'] = [
                        [
                            "type" => "Action.Submit",
                            "title" => $this->langManager->translate('validate'),
                            "data" => [
                                "action" => "ACTIONFIELD"
                            ]
                        ]
                    ];
                    break;
                case 'buttons':
                    $values = $message->actionField->listValues->values;
                    $output['attachments'] = count($values) ? [
                        [
                            'contentType' => 'application/vnd.microsoft.card.thumbnail',
                            'content' => [
                                'text' => $message->message,
                                'wrap' => true,
                                'buttons' => array_map(
                                    function ($value) {
                                        return [
                                            'type' => 'postBack',
                                            'title' => $value->label[0],
                                            'value' => json_encode(
                                                [
                                                    "ACTIONFIELD" => $value->option,
                                                ]
                                            )
                                        ];
                                    },
                                    $values
                                ),
                            ],
                        ],
                    ] : [];
                    break;
            }
        }
        if (isset($message->actionField) && isset($message->actionField->fieldType)) {
            switch ($message->actionField->fieldType) {
                case 'datePicker':
                    $output['text'] .= ' (' . $this->langManager->translate('date_format') . ')';
                    break;
            }
        }
        return $output;
    }

    /**
     * @param $message
     * @param $lastUserQuestion
     *
     * @return array
     */
    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
    {
        $output = [];
        $buttonTitleSetting = $this->getButtonTitleAttribute();
        $options = $message->options;

        $output['text'] = $message->message;
        $output['attachments'] = count($options) ? [
            [
                'contentType' => 'application/vnd.microsoft.teams.card.list',
                'content' => [
                    "items" =>
                    array_map(
                        function ($option) use ($buttonTitleSetting) {
                            return [
                                "type" => "resultItem",
                                "icon" => $this->conf['icon_multi_options'],
                                "id" => $option->value,
                                "title" => isset($option->attributes->$buttonTitleSetting)
                                    ? $option->attributes->$buttonTitleSetting
                                    : $option->label,
                                "tap" => [
                                    "type" => "postBack",
                                    "displayText" => isset($option->attributes->$buttonTitleSetting)
                                        ? $option->attributes->$buttonTitleSetting
                                        : $option->label,
                                    "value" => json_encode(['option' => $option->value]),
                                ]
                            ];
                        },
                        $options,
                        array_keys($options)
                    ),
                ],
            ]
        ] : [];

        return $output;
    }

    /**
     * @param $message
     * @param $lastUserQuestion
     *
     * @return array
     */
    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        $output = [];
        $output['text'] = '';
        $output['attachments'] = [
            [
                'contentType' => 'application/vnd.microsoft.card.hero',
                'content' => [
                    'text' => $message->message,
                    'wrap' => true,
                    'buttons' => array_map(
                        function ($option) {
                            return [
                                'type' => 'postBack',
                                'title' => $this->langManager->translate($option->label),
                                'value' => $option->value,
                            ];
                        },
                        $message->options
                    ),
                ],
            ],
        ];
        return $output;
    }

    /**
     * @param $message
     *
     * @return array
     */
    protected function digestFromApiExtendedContentsAnswer($message): array
    {
        $buttonTitleSetting = $this->getButtonTitleAttribute();
        $buttons = [];

        $message->subAnswers = array_slice($message->subAnswers, 0, 3);
        $this->session->set('federatedSubanswers', $message->subAnswers);

        foreach ($message->subAnswers as $index => $option) {
            $buttons[] = [
                'type' => 'postBack',
                'title' => isset($option->attributes->$buttonTitleSetting)
                    ? $option->attributes->$buttonTitleSetting
                    : $option->parameters->contents->title,
                'value' => [
                    'extendedContentAnswer' => $index,
                ],
            ];
        }

        $output['text'] = $message->message;
        $output['attachments'] = [
            [
                'contentType' => 'application/vnd.microsoft.card.thumbnail',
                'content' => [
                    'buttons' => $buttons,
                ],
            ],
        ];

        return $output;
    }

    //endregion

    //region Other Message API Message Formatting

    /**
     * @inheritDoc
     */
    public function buildEscalationMessage()
    {
        $output = ["text" => ""];
        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true,
            ],
            [
                "label" => 'no',
                "escalate" => false,
            ],
        ];

        $output['attachments'] = count($escalateOptions) ? [
            [
                'contentType' => 'application/vnd.microsoft.card.thumbnail',
                'content' => [
                    'text' => $this->langManager->translate('ask-to-escalate'),
                    'wrap' => true,
                    'buttons' => array_map(
                        function ($option) {
                            return [
                                'type' => 'postBack',
                                'title' => $this->langManager->translate($option['label']),
                                'value' => json_encode(
                                    [
                                        "escalateOption" => $option['escalate']
                                    ]
                                ),
                            ];
                        },
                        $escalateOptions
                    ),
                ],
            ],
        ] : [];

        return $output;
    }

    /**
     * @param array $ratingOptions define in conf > conversation
     * @param string $rateCode rateCode returned by inbenta API
     *
     * @return array
     */
    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        $output = [];
        $output['text'] = '';
        $output['attachments'] = count($ratingOptions) ? [
            [
                'contentType' => 'application/vnd.microsoft.card.thumbnail',
                'content' => [
                    'text' => $this->langManager->translate('rate-content-intro'),
                    'wrap' => true,
                    'buttons' => array_map(
                        function ($option) use ($rateCode) {
                            return [
                                'type' => 'postBack',
                                'title' => $this->langManager->translate($option['label']),
                                'value' => json_encode(
                                    [
                                        'askRatingComment' => isset($option['comment']) && $option['comment'],
                                        'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
                                        'ratingData' => [
                                            'type' => 'rate',
                                            'data' => [
                                                'type' => 'rate',
                                                'code' => $rateCode,
                                                'value' => $option['id'],
                                                'comment' => null,
                                            ],
                                        ],
                                    ]
                                ),
                            ];
                        },
                        $ratingOptions
                    ),
                ],
            ],
        ] : [];

        return $output;
    }

    /**
     * This transform an Inbenta API related into MS Team Block Kit response
     *
     * @param object $related
     *
     * @return array MS Teams Response
     */
    protected function getRelatedBlocks($related): array
    {
        $relatedItems = array_map(
            function ($content) {
                return [
                    "type" => "resultItem",
                    "icon" => $this->conf['icon_multi_options'],
                    "id" => $content->id,
                    "title" => $content->title,
                    "tap" => [
                        "type" => "postBack",
                        "displayText" => $content->title,
                        "value" => json_encode(['option' => $content->id]),
                    ],

                ];
            },
            $related->relatedContents
        );

        return isset($related->relatedContents) && count($related->relatedContents) > 0 ? [
            [
                'contentType' => 'application/vnd.microsoft.teams.card.list',
                'content' => [
                    'title' => $related->relatedTitle,
                    "items" => $relatedItems
                ],
            ]
        ] : [];
    }

    //endregion

    //region MS Teams Message Builder Helpers

    /**
     * Build the Adaptive Card response to send to the Microsoft API Send
     *
     * @param array $body An array of block
     * @param ?string $text A simple text
     * @param array $options An array of options elements
     *
     * @return array
     */
    public static function buildAdaptiveCard(
        array $body = [],
        ?string $text = null,
        array $options = []
    ): array {
        $output = [];

        if (!empty($body)) {
            $output['body'] = $body;
        }

        if (is_string($text)) {
            $output['text'] = $text;
        }

        if (!empty($options)) {
            $output['options'] = $options;
        }

        return $output;
    }

    /**
     * Build an Adaptive Card Text Block element
     *
     * @param string $text Text to display
     * @param ?string $id Given TextBlock identifier
     * @param ?string $dataContext Additional context passed
     *
     * @return array
     */
    public static function buildAdaptiveCardTextBlock(
        string $text,
        ?string $id = null,
        ?string $dataContext = null
    ): array {
        $textBlock = [
            "type" => "TextBlock",
            "text" => $text,
            'wrap' => true
        ];

        if (is_string($id)) {
            $textBlock['id'] = $id;
        }

        if (is_string($dataContext)) {
            $textBlock['$data'] = $dataContext;
        }

        return $textBlock;
    }

    /**
     * Build an Adaptive Card input choiceSet
     *
     * @param array $options Additional context passed
     * @param string $id Given TextBlock identifier
     *
     * @return array
     */
    public static function buildAdaptiveCardChoiceSet(
        array $options = [],
        string $id = ""
    ): array {
        return [
            "type" => "Input.ChoiceSet",
            'wrap' => true,
            'id' => $id,
            'isMultiSelect' => false,
            "value" => "1",
            'choices' => array_map(
                function ($option) {
                    return [
                        "title" => $option->label[0],
                        "value" => $option->option
                    ];
                },
                $options
            ),
        ];
    }

    /**
     * Build an Adaptive Card Rich Text Block element
     *
     * @param string[] $text An array of text to display
     * @param ?string $id Given TextBlock identifier
     * @param ?string $dataContext Additional context passed
     *
     * @return array
     */
    public static function buildAdaptiveCardRichTextBlock(
        array $text,
        ?string $id = null,
        ?string $dataContext = null
    ): array {
        $textBlock = [
            "type" => "RichTextBlock",
            'wrap' => true,
            "inlines" => []
        ];

        foreach ($text as $str) {
            if (is_string($str) && !empty($str)) {
                $textBlock['inlines'][] = [
                    "type" => "TextRun",
                    'wrap' => true,
                    "text" => $str
                ];
            }
        }

        if (is_string($id)) {
            $textBlock['id'] = $id;
        }

        if (is_string($dataContext)) {
            $textBlock['$data'] = $dataContext;
        }

        return $textBlock;
    }

    //endregion

    //region Misc Methods

    /**
     * @param $message
     *
     * @return false
     */
    protected function handleMessageWithImages($message)
    {
        // Nor used in this connector
        return false;
    }

    /**
     * Get the button title attribute from the configuration
     *
     * @return string - Title Attribute
     */
    protected function getButtonTitleAttribute(): string
    {
        return (isset($this->conf['button_title']) && $this->conf['button_title'] !== '')
            ? $this->conf['button_title']
            : '';
    }

    /**
     * Check if there are html tags in string
     *
     * @param $message
     *
     * @return bool
     */
    protected function isHtml($message)
    {
        return $message != strip_tags($message);
    }

    /**
     * @inheritDoc
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

        $buttons = [];
        foreach ($urlButton as $button) {
            $buttons[] = [
                "type" => "web_url",
                "url" => $button->$buttonURLProp,
                "title" => $button->$buttonTitleProp,
                "webview_height_ratio" => "full",
            ];
        }

        return [
            "attachment" => [
                "type" => "template",
                "payload" => [
                    "template_type" => "button",
                    "text" => strip_tags($message->message),
                    "buttons" => $buttons,
                ],
            ],
        ];
    }

    /**
     * Converts an HTML-formatted text into Markdown format.
     *
     * @param string $text - Text to transform into Markdown
     *
     * @return string
     */
    public function toMarkdown(string $text): string
    {
        $content = str_replace(">\n", '>', $text);
        $content = str_replace("\n<", '<', $content);
        $content = str_replace("\t", '', $content);
        $content = strip_tags(
            $content,
            '<br><strong><em><del><li><code><pre><a><p><ul><i><s><h1><h2><h3><h4><b>'
        );

        $content = str_replace("\n", "", $content);
        $content = str_replace(['<br />', '<br>'], "\n", $content);
        $content = str_replace(
            [
                '<strong>',
                '</strong>',
                '<b>',
                '</b>',
                '<h1>',
                '</h1>',
                '<h2>',
                '</h2>',
                '<h3>',
                '</h3>',
                '<h4>',
                '</h4>',
            ],
            ['**', '**'],
            $content
        );

        $content = str_replace(['<p>', '</p>'], ['', "\r"], $content);
        $content = str_replace(['<em>', '</em>', '<i>', '</i>'], ['_', '_', '_', '_'], $content);
        $content = str_replace(['<del>', '</del>', '<s>', '</s>'], ['~', '~', '~', '~'], $content);
        $content = str_replace(['<li>', '</li>'], ['* ', "\n"], $content);
        $content = str_replace(['<ul>', '</ul>'], ["\r", "\r"], $content);
        $content = str_replace(['<code>', '</code>'], ['`', '`'], $content);
        $content = str_replace(['<pre>', '</pre>'], ['```', '```'], $content);
        preg_match_all(
            '/<a[^>]*?href=(\?|"([^"]*?)"|\'([^\']*?)\').*?>(.*?)<\/a>/si',
            $content,
            $res
        );
        for ($i = 0; $i < count($res[0]); $i++) {
            $content = str_replace(
                $res[0][$i],
                '[' . $res[4][$i] . '](' . $res[2][$i] . ')',
                $content
            );
        }

        return html_entity_decode($content);
    }

    //endregion

    //region DOM Helpers

    /**
     * Create a Node from HTML input
     *
     * @param string $html HTML String
     * @param array $defaultsNodesBlocks Default nodes
     *
     * @return string[]
     */
    public function createNodesBlocks(string $html, $defaultsNodesBlocks = [])
    {
        $nodesBlocks = $defaultsNodesBlocks;

        try {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();

            /** @var DOMNode $body */
            $body = $dom->getElementsByTagName('body')[0];

            foreach ($body->childNodes as $childNode) {
                /** @type DOMNode $childNode */
                if ($this->domElementHasImage($childNode)) {
                    $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMImages($childNode));
                } elseif ($this->domElementHasTable($childNode)) {
                    $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMTable($childNode));
                }
                if (strpos($this->getElementHTML($childNode), '<iframe') !== false) {
                    $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMIframe($childNode));
                } else {
                    $nodesBlocks[] = $this->getElementHTML($childNode);
                }
            }

            return $nodesBlocks;
        } catch (Exception $e) {
            error_log($e->getMessage());
            return [];
        }
    }

    /**
     * This check {@link DOMNode::$childNodes} and search for images, then return an array
     * containing the `alt` and `src` attributes or the {@link DOMNode} HTML if not an image.
     *
     * @param DOMNode $element Given {@link DOMNode} element
     *
     * @return array
     */
    public function handleDOMTable(DOMNode $element): array
    {
        $elements = [];

        // process if table is the parent node
        if ($element->nodeName === 'table') {
            $base64 = $this->generateTableImage($element);
            $elements[] = [
                'alt' => 'table data',
                'src' => "data:image/png;base64,$base64",
            ];
        } else {
            // else process table in childNodes
            foreach ($element->childNodes as $key => $childNode) {
                /** @type DOMNode $childNode */

                if ($childNode->nodeName === 'table') {
                    $base64 = $this->generateTableImage($element);
                    $elements[] = [
                        'alt' => 'table data',
                        'src' => "data:image/png;base64,$base64",
                    ];
                } else {
                    $elements[] = $this->getElementHTML($childNode);
                }
            }
        }

        return $elements;
    }

    /**
     * Check if the current {@link DOMNode} children has an image node
     *
     * @param DOMNode|DOMElement $element
     *
     * @return bool
     */
    public function domElementHasTable($element): bool
    {
        if (!$element instanceof DOMText) {
            $tables = $element->getElementsByTagName('table');
            return $tables->length > 0 || $element->nodeName === 'table';
        }
        return false;
    }

    /**
     * Handle Image DOMNode
     *
     * @param DOMNode|DOMElement $element
     *
     * @return array
     */
    public function handleDOMImages($element): array
    {
        $elements = [];

        /** @type DOMNode|DOMElement $childNode */
        foreach ($element->childNodes as $childNode) {
            if (!$childNode instanceof DOMText) {
                if ($childNode->nodeName === 'img') {
                    $elements[] = [
                        'alt' => $childNode->getAttribute('alt'),
                        'src' => $childNode->getAttribute('src')
                    ];
                } else {
                    $elements[] = $this->getElementHTML($childNode);
                }
            }
        }

        return $elements;
    }

    /**
     * Check if the current element children has an image node
     *
     * @param DOMNode|DOMElement $element
     *
     * @return bool
     */
    public function domElementHasImage($element)
    {
        if (!$element instanceof DOMText) {
            $images = $element->getElementsByTagName('img');
            return $images->length > 0;
        }
        return false;
    }

    /**
     * @param DOMNode $element
     *
     * @return string
     */
    public function getElementHTML($element)
    {
        $tmp = new \DOMDocument();
        $tmp->appendChild($tmp->importNode($element, true));
        return $tmp->saveHTML();
    }


    /**
     * This check {@link DOMNode::$childNodes} and search for iframe, then return an array
     * containing the link of the src of the iframe
     */
    public function handleDOMIframe(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            /** @type DOMNode $childNode */
            if ($childNode->nodeName === 'iframe') {
                $source = $childNode->getAttribute('src');
                if ($source) {
                    $elements[] = '<a href="' . $source . '">' . $source . '</a>';
                } else {
                    $elements[] = $this->getElementHTML($childNode);
                }
            } else {
                $elements[] = $this->getElementHTML($childNode);
            }
        }
        return $elements;
    }

    //endregion
}
