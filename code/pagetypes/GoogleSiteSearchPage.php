<?php

/**
 */
class GoogleSiteSearchPage extends Page
{
    private static $db = array(
        'GoogleKey' => 'Varchar(200)',
        'GoogleCX' => 'Varchar(200)',
        'GoogleDomain' => 'Varchar(255)',
    );

    private static $casting = array(
        'Start' => 'Int',
        'ResultCount' => 'Int',
    );

    private static $hourly_limit = 50;
    private static $daily_limit = 100;

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->addFieldsToTab('Root.Main', array(
            new TextField('GoogleKey', 'Google Custom Search Key (sign up at <a href="https://www.google.com/cse/sitesearch/create" target="_blank">google.com/cse</a>)'),
            new TextField('GoogleCX', 'Google Custom Search CX'),
            new TextField('GoogleDomain', 'Domain to search results for (must be public, i.e use live URL for testing)'),
        ));

        return $fields;
    }

    public function requireDefaultRecords()
    {
        if ($this->config()->get('create_default_search_page')) {
            if (self::get()->count() < 1) {
                $search = new self();
                $search->Title = 'Search results';
                $search->MenuTitle = 'Search';
                $search->ShowInMenus = 0;
                $search->ShowInSearch = 0;
                $search->GoogleKey = $this->config()->get('cse_key');
                $search->GoogleCX = $this->config()->get('cse_cx');
                $search->URLSegment = 'search';
                $search->write();

                $search->doPublish('Stage', 'Live');
            }
        }
    }

    public function MetaTags($includeTitle = true)
    {
        $tags = parent::MetaTags($includeTitle);
        $tags .= '<meta name="robots" content="noindex">';

        return $tags;
    }

    /**
     * @return string
     */
    public function getCseKey()
    {
        if ($this->GoogleKey) {
            return $this->GoogleKey;
        }

        return $this->config()->get('cse_key');
    }

    /**
     * @return string
     */
    public function getCseCx()
    {
        if ($this->GoogleKey) {
            return $this->GoogleCX;
        }

        return $this->config()->get('cse_cx');
    }
}

/**
 */
class GoogleSiteSearchPage_Controller extends Page_Controller
{
    public function init()
    {
        parent::init();
        if ($this->Query()) {
            $results = $this->getSearchAPIResponse($this->Query(), $this->Refinement(), $this->Start());
            if ($results) {
                $this->SearchResultsResponse = $results;
            } else {
                $this->SearchResultsError = true;
            }
        }
    }

    public function Start()
    {
        $start = (int) $this->request->getVar('start');

        return ($start > 0) ? $this->request->getVar('start') : 1;
    }

    public function Query()
    {
        return $this->request->getVar('q');
    }

    public function Refinement()
    {
        return $this->request->getVar('refinement');
    }

    public function ResultCount()
    {
        if ($this->SearchResultsResponse) {
            return $this->SearchResultsResponse['searchInformation']['totalResults'];
        }
    }

    public function Results()
    {
        // Caution - unescaped values sent to template
        if ($this->SearchResultsResponse && isset($this->SearchResultsResponse['items'])) {
            return  ArrayList::create($this->SearchResultsResponse['items']);
        }

        return ArrayList::create();
    }

    public function Refinements()
    {
        $list = ArrayList::create();
        if ($this->SearchResultsResponse && isset($this->SearchResultsResponse['context']['facets'])) {
            $refinements = array();
            // Flatten response
            foreach ($this->SearchResultsResponse['context']['facets'] as $f) {
                $refinements = array_merge($refinements, $f);
            }
            // Set active marker and link URL
            foreach ($refinements as &$r) {
                $r['active'] = ($r['label'] == $this->Refinement());
                $r['link'] = $this->Link('?q='.urlencode($this->Query()).'&refinement='.$r['label']);
                $list->push(new ArrayData($r));
            }
        }

        return $list;
    }

    public function getPageLink($page)
    {
        if ($this->SearchResultsResponse && isset($this->SearchResultsResponse['queries'][$page][0]['startIndex'])) {
            $linkSuffix = '?q='.urlencode($this->Query()).'&start='
                .$this->SearchResultsResponse['queries'][$page][0]['startIndex'];
            $refinement = $this->Refinement();
            if ($refinement) {
                $linkSuffix .= '&refinement='.urlencode($refinement);
            }

            return $this->Link($linkSuffix);
        }
    }

    public function NextPageLink()
    {
        return $this->getPageLink('nextPage');
    }

    public function PreviousPageLink()
    {
        return $this->getPageLink('previousPage');
    }

    public function getClientIP()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    public function rateLimitExceeded() {

        $ip = $this->getClientIP();

        // Hourly rate check
        $date = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $countLastHour = GoogleSiteSearchRequest::get()->filter(array(
            'Created:GreaterThan' => $date,
            'IPAddress' => $ip
        ))->count();
        if ($countLastHour > GoogleSiteSearchPage::config()->get('hourly_limit')) return true;

        // Daily rate check
        $date = date('Y-m-d H:i:s', strtotime('-1 day'));
        $countLastDay = GoogleSiteSearchRequest::get()->filter(array(
            'Created:GreaterThan' => $date,
            'IPAddress' => $ip
        ))->count();
        if ($countLastDay > GoogleSiteSearchPage::config()->get('daily_limit')) return true;
    }

    /**
     * Retrieve cached search results or execute search API request.
     *
     * @return array|false
     */
    public function getSearchAPIResponse($query, $refinement, $start)
    {
        // Fetch from cache if possible
        $cache = SS_Cache::factory('search');
        $cacheKey = bin2hex(http_build_query(func_get_args()));
        if ($json = $cache->load($cacheKey)) {
            return json_decode($json, true);
        }

        // Deny request if rate limited
        if ($this->rateLimitExceeded()) {
            return $this->httpError(429, "Search API rate limit exceeded. Please try again later.");
        }

        // Build request
        $key = urlencode($this->getCseKey());
        $cx = urlencode($this->getCseCx());
        $query = urlencode($query);
        $start = urlencode($start);
        if ($refinement) {
            $query .= urlencode(" more:$refinement");
        }
        $url = "https://www.googleapis.com/customsearch/v1?key=$key&cx=$cx&q=$query&start=$start";
        $opts = array('http' => array(
                'timeout' => 5, // 5 seconds for a google request is ages.
                'ignore_errors' => true,
            ),
        );
        $context = stream_context_create($opts);

        // Log request
        $log = GoogleSiteSearchRequest::create();
        $log->IPAddress = $this->getClientIP();
        $log->Query = $url;
        $log->write();

        // Contact API
        $json = file_get_contents($url, false, $context);
        $result = json_decode($json, true);

        // Catch errors
        if ($json === false) {
            user_error('Google API request failed', E_USER_WARNING);

            return false;
        }
        if (!$result) {
            user_error('Could not decode JSON', E_USER_WARNING);

            return false;
        }
        if (isset($result['error'])) {
            user_error("Google API returned an error. Code: {$result['error']['code']}, Message: {$result['error']['message']}", E_USER_WARNING);

            return false;
        }

        // Cache JSON result if successful
        $cache->save($json, $cacheKey);

        return $result;
    }
}
