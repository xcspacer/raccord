<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Box names
    |--------------------------------------------------------------------------
    | Names of the boxes of a cabinet follow a grammar that depends on the network operator: the suffixes
    | (« -JD » for a straight joint plus the operator codes of the installation, comma separated in
    | AUDIT_BOX_SUFFIXES) are the only part that changes from one installation to another.
    */
    'box_suffixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUDIT_BOX_SUFFIXES', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Cable capacities offered in the optical mode
    |--------------------------------------------------------------------------
    */
    'capacities' => ['1FO', '6FO', '12FO', '24FO', '36FO', '48FO', '72FO', '96FO', '144FO', '288FO'],

    /*
    |--------------------------------------------------------------------------
    | Reading the cabinet's synoptic and map files
    |--------------------------------------------------------------------------
    | Words the documents use for what a box is fixed on, the lines that end the description of a box (besides a
    | « 12 FO » cable line), and how the map file names its folders and points. They follow the documents of the
    | installation: the operator-specific ones come from AUDIT_SYNOPTIC_STOP_PREFIXES (comma separated).
    */
    'synoptic' => [
        'support_words' => [
            'chamber' => ['chambre', 'chamber', 'camara', 'cámara', 'câmara'],
            'pole' => ['poteau', 'pole', 'poste'],
            'facade' => ['facade', 'façade', 'fachada'],
        ],
        'stop_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUDIT_SYNOPTIC_STOP_PREFIXES', ''))))),
    ],

    'kml' => [
        'box_folder' => 'BOITE',
        'cabinet_folder' => 'POSITION_PM',
        'cabinet_prefixes' => ['SRO_', 'SRO-'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation apps offered to reach a box
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'waze' => 'https://waze.com/ul?ll=:lat,:lng&navigate=yes',
        'maps' => 'https://www.google.com/maps/dir/?api=1&destination=:lat,:lng',
    ],

    /*
    |--------------------------------------------------------------------------
    | Control points created with every audit
    |--------------------------------------------------------------------------
    | Names come from the translations (audit.control_points.<key>) in the installation's default language, with
    | « :cabinet » replaced by the cabinet reference. The kind lets the rules recognise the cabinet itself and the
    | clean-up point (hidden in the optical mode while it is empty).
    */
    'control_points' => [
        ['key' => 'cabinet', 'kind' => 'cabinet', 'subfolders' => ['before', 'after']],
        ['key' => 'satellite_chamber', 'kind' => null, 'subfolders' => []],
        ['key' => 'cleanup', 'kind' => 'cleanup', 'subfolders' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fibre and tube colours (1 to 12) used by the recheck of a box
    |--------------------------------------------------------------------------
    | Written in the RECNOK workbook; « dark » colours are written with white text.
    */
    'recheck_palette' => [
        ['key' => 'red', 'hex' => 'FF0000', 'dark' => true],
        ['key' => 'blue', 'hex' => '0000FF', 'dark' => true],
        ['key' => 'green', 'hex' => '00FF00', 'dark' => false],
        ['key' => 'yellow', 'hex' => 'FFFF00', 'dark' => false],
        ['key' => 'violet', 'hex' => '800080', 'dark' => true],
        ['key' => 'white', 'hex' => 'FFFFFF', 'dark' => false],
        ['key' => 'orange', 'hex' => 'FF8000', 'dark' => false],
        ['key' => 'grey', 'hex' => '808080', 'dark' => true],
        ['key' => 'brown', 'hex' => '8B4513', 'dark' => true],
        ['key' => 'black', 'hex' => '000000', 'dark' => true],
        ['key' => 'turquoise', 'hex' => '00FFFF', 'dark' => false],
        ['key' => 'pink', 'hex' => 'FFC0CB', 'dark' => false],
    ],

];
