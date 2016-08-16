<?php
/**
 * Allow logging search requests for rate limiting.
 *
 */
class GoogleSiteSearchRequest extends DataObject
{
    private static $db = array(
        'IPAddress' => 'Varchar(45)',
        'Query' => 'Varchar(255)'
    );
}
