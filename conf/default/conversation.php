<?php

//Inbenta Chatbot configuration
return array(
    "default" => array(
        "answers" => array(
            "sideBubbleAttributes"  => array(),
            "answerAttributes"      => array(
                "ANSWER_TEXT",
            ),
            "maxOptions"            => 3,
            "maxRelatedContents"    => 2,
            'skipLastCheckQuestion' => true
        ),
        "forms" => array(
            "allowUserToAbandonForm"    => true,
            "errorRetries"              => 2
        ),
        "lang"  => "en"
    ),
    "source" => "ms_teams",
    "user_type" => 0,
    "content_ratings" => array(        // Remember that these ratings need to be created in your instance
        "enabled" => false,
        "ratings" => array(
            array(
                'id' => 1,
                'label' => 'yes',
                'comment' => false,
                'isNegative' => false
            ),
            array(
                'id' => 2,
                'label' => 'no',
                'comment' => true,     // Whether clicking this option should ask for a comment
                'isNegative' => true
            )
        )
    ),
    "digester" => array(
        "button_title" => "",             // Provide the attribute that contains the custom content-title to be displayed in multiple options
        "icon_multi_options" => "https://img.icons8.com/windows/32/circled-chevron-right.png" //Icon to show with the multi options
    ),
);
