<?php

return [
    'requireGpsOnUpload' => false,
    'requireGpsTemplates' => ['image', 'story', 'article'],
    'autoSyncOnPageUpdate' => false,
    'autoSyncOnFileUpdate' => false,
    'titleImageFields' => ['titelbild', 'Titelbild', 'heroImage'],
    'titleImageFallback' => 'first-image',
    'pageMirrorMap' => [
        'Latitude' => 'latitude',
        'Longitude' => 'longitude',
        'OSMLink' => 'osmlink',
        'LocationName' => 'locationname',
        'Strasse' => 'strasse',
        'Hausnummer' => 'hausnummer',
    ],
    'storyImport' => [
        'enabled' => true,
        'template' => 'story',
        'locationFields' => ['LocationName', 'locationname'],
        'dateFields' => ['Datum', 'datum'],
        'unknownLocationLabel' => 'Unbekannter Ort',
    ],
    'license' => [
        'value' => 'CC BY-SA 4.0',
        'storyTemplates' => ['story'],
        'sourcePlatformField' => 'Ursprungsplattform',
        'pixelfedField' => 'PixelfedLink',
        'pixelfedPlatformLabels' => ['Pixelfed'],
    ],
];
