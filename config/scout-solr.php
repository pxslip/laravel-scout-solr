<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Solr Paginate Size
    |--------------------------------------------------------------------------
    |
    | This value will be used as the size for the pagination of
    | search results.
    |
    */

    'paginate_size' => 25,

    /*
     * Whether or not solr is enabled
     */
    'enabled' => env('SOLR_IMPORT', false),

    /*
    |--------------------------------------------------------------------------
    | Solr Endpoint
    |--------------------------------------------------------------------------
    |
    | These values make up the endpoint of a given solr instance
    | The endpoint name should match the searchableAs value in a model
    |
    |
     */
    'endpoints' => [
        'endpoint_name' => [
            'host' => env('SOLR_HOST', 'localhost'),
            'port' => env('SOLR_PORT', 8983),
            'path' => env('SOLR_PATH', 'solr'),
            'core' => env('SOLR_CORE', 'core'),
        ],
    ],
];
