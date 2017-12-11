<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao;

use Patchwork\Utf8;


/**
 * Creates and queries the search index
 *
 * The class takes the HTML markup of a page, exctracts the content and writes
 * it to the database (search index). It also provides a method to query the
 * seach index, returning the matching entries.
 *
 * Usage:
 *
 *     Search::indexPage($objPage->row());
 *     $result = Search::searchFor('keyword');
 *
 *     while ($result->next())
 *     {
 *         echo $result->url;
 *     }
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Search
{

    /**
     * Object instance (Singleton)
     * @var Search
     */
    protected static $objInstance;


    /**
     * Index a page
     *
     * @param array $arrData The data array
     *
     * @return boolean True if a new record was created
     */
    public static function indexPage($arrData)
    {
        $objDatabase = \Database::getInstance();

        $arrSet['tstamp'] = time();
        $arrSet['url'] = $arrData['url'];
        $arrSet['title'] = $arrData['title'];
        $arrSet['protected'] = $arrData['protected'];
        $arrSet['filesize'] = $arrData['filesize'];
        $arrSet['groups'] = $arrData['groups'];
        $arrSet['pid'] = $arrData['pid'];
        $arrSet['language'] = $arrData['language'];

        // Get the file size from the raw content
        if (!$arrSet['filesize'])
        {
            $arrSet['filesize'] = number_format((strlen($arrData['content']) / 1024 ), 2, '.', '');
        }

        // Replace special characters
        $strContent = str_replace(array("\n", "\r", "\t", '&#160;', '&nbsp;', '&shy;'), array(' ', ' ', ' ', ' ', ' ', ''), $arrData['content']);

        // Strip script tags
        while (($intStart = strpos($strContent, '<script')) !== false)
        {
            if (($intEnd = strpos($strContent, '</script>', $intStart)) !== false)
            {
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
            }
            else
            {
                break; // see #5119
            }
        }

        // Strip style tags
        while (($intStart = strpos($strContent, '<style')) !== false)
        {
            if (($intEnd = strpos($strContent, '</style>', $intStart)) !== false)
            {
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 8);
            }
            else
            {
                break; // see #5119
            }
        }

        // Strip non-indexable areas
        while (($intStart = strpos($strContent, '<!-- indexer::stop -->')) !== false)
        {
            if (($intEnd = strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false)
            {
                $intCurrent = $intStart;

                // Handle nested tags
                while (($intNested = strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd)
                {
                    if (($intNewEnd = strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false)
                    {
                        $intEnd = $intNewEnd;
                        $intCurrent = $intNested;
                    }
                    else
                    {
                        break; // see #5119
                    }
                }

                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 26);
            }
            else
            {
                break; // see #5119
            }
        }

        // HOOK: add custom logic
        if (isset($GLOBALS['TL_HOOKS']['indexPage']) && is_array($GLOBALS['TL_HOOKS']['indexPage']))
        {
            foreach ($GLOBALS['TL_HOOKS']['indexPage'] as $callback)
            {
                \System::importStatic($callback[0])->{$callback[1]}($strContent, $arrData, $arrSet);
            }
        }

        // Free the memory
        unset($arrData['content']);

        $arrMatches = array();
        preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE);
        $intOffset = strlen($arrMatches[0][0]) + $arrMatches[0][1];

        // Split page in head and body section
        $strHead = substr($strContent, 0, $intOffset);
        $strBody = substr($strContent, $intOffset);

        unset($strContent);

        $tags = array();

        // Get the description
        if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
        {
            $arrData['description'] = trim(preg_replace('/ +/', ' ', \StringUtil::decodeEntities($tags[1])));
        }

        // CearchPro change: Get OpenGraph Image
        if (preg_match('/<meta property="og:image" content="([^"]*)"[^>]*>/i', $strHead, $tags))
        {
            $arrData['image'] = $tags[1];
        }

        // Get the keywords
        if (preg_match('/<meta[^>]+name="keywords"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
        {
            $arrData['keywords'] = trim(preg_replace('/ +/', ' ', \StringUtil::decodeEntities($tags[1])));
        }

        // Read the title and alt attributes
        if (preg_match_all('/<* (title|alt)="([^"]*)"[^>]*>/i', $strBody, $tags))
        {
            $arrData['keywords'] .= ' ' . implode(', ', array_unique($tags[2]));
        }

        // Add a whitespace character before line-breaks and between consecutive tags (see #5363)
        $strBody = str_ireplace(array('<br', '><'), array(' <br', '> <'), $strBody);
        $strBody = strip_tags($strBody);

        // Put everything together
        $arrSet['text'] = $arrData['title'] . ' ' . $arrData['description'] . ' ' . $strBody . ' ' . $arrData['keywords'];
        $arrSet['text'] = trim(preg_replace('/ +/', ' ', \StringUtil::decodeEntities($arrSet['text'])));

        // CearchPro change: Add OpenGraph Image to Search index
        $arrSet['imageUrl'] = $arrData['image'];
        $arrSet['tstamp'] = time();

        // Calculate the checksum
        $arrSet['checksum'] = md5($arrSet['text']);

        $objIndex = $objDatabase->prepare("SELECT id, url FROM tl_search WHERE checksum=? AND pid=?")
            ->limit(1)
            ->execute($arrSet['checksum'], $arrSet['pid']);

        // Update the URL if the new URL is shorter or the current URL is not canonical
        if ($objIndex->numRows && $objIndex->url != $arrSet['url'])
        {
            if (strpos($arrSet['url'], '?') === false && strpos($objIndex->url, '?') !== false)
            {
                // The new URL is more canonical (no query string)
                $objDatabase->prepare("DELETE FROM tl_search WHERE id=?")
                    ->execute($objIndex->id);

                $objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
                    ->execute($objIndex->id);
            }
            elseif (substr_count($arrSet['url'], '/') > substr_count($objIndex->url, '/') || strpos($arrSet['url'], '?') !== false && strpos($objIndex->url, '?') === false || strlen($arrSet['url']) > strlen($objIndex->url))
            {
                // The current URL is more canonical (shorter and/or less fragments)
                $arrSet['url'] = $objIndex->url;
            }
            else
            {
                // The same page has been indexed under a different URL already (see #8460)
                return false;
            }
        }

        $objIndex = $objDatabase->prepare("SELECT id FROM tl_search WHERE url=? AND pid=?")
            ->limit(1)
            ->execute($arrSet['url'], $arrSet['pid']);

        // Add the page to the tl_search table
        if ($objIndex->numRows)
        {
            $objDatabase->prepare("UPDATE tl_search %s WHERE id=?")
                ->set($arrSet)
                ->execute($objIndex->id);

            $intInsertId = $objIndex->id;
        }
        else
        {
            $objInsertStmt = $objDatabase->prepare("INSERT INTO tl_search %s")
                ->set($arrSet)
                ->execute();

            $intInsertId = $objInsertStmt->insertId;
        }

        // Remove quotes
        $strText = str_replace(array('´', '`'), "'", $arrSet['text']);

        unset($arrSet);

        // Remove special characters
        $strText = preg_replace(array('/- /', '/ -/', "/' /", "/ '/", '/\. /', '/\.$/', '/: /', '/:$/', '/, /', '/,$/', '/[^\w\'.:,+-]/u'), ' ', $strText);

        // Split words
        $arrWords = preg_split('/ +/', Utf8::strtolower($strText));
        $arrIndex = array();

        // Index words
        foreach ($arrWords as $strWord)
        {
            // Strip a leading plus (see #4497)
            if (strncmp($strWord, '+', 1) === 0)
            {
                $strWord = substr($strWord, 1);
            }

            $strWord = trim($strWord);

            if (!strlen($strWord) || preg_match('/^[\.:,\'_-]+$/', $strWord))
            {
                continue;
            }

            if (preg_match('/^[\':,]/', $strWord))
            {
                $strWord = substr($strWord, 1);
            }

            if (preg_match('/[\':,.]$/', $strWord))
            {
                $strWord = substr($strWord, 0, -1);
            }

            // CearchPro change: Skip Word if on Stoplist
            if (self::getExclude($strWord)) continue;

            if (isset($arrIndex[$strWord]))
            {
                $arrIndex[$strWord]++;
                continue;
            }

            $arrIndex[$strWord] = 1;
        }

        // Remove existing index
        $objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
            ->execute($intInsertId);

        unset($arrSet);

        // Create new index
        $arrKeys = array();
        $arrValues = array();

        // CearchPro change: Add Word in transliterated version
        foreach ($arrIndex as $k => $v) {
            $arrKeys[] = "(?, ?, ?, ?, ?)";
            $arrValues[] = $intInsertId;
            $arrValues[] = $k;
            $arrValues[] = \Transliterate::trans($k);
            $arrValues[] = $v;
            $arrValues[] = $arrData['language'];
        }

        // CearchPro change: Search by Transliterated Version
        $objDatabase->prepare("INSERT INTO tl_search_index (pid, word, word_transliterated, relevance, language) VALUES " . implode(", ", $arrKeys))
            ->execute($arrValues);

        return true;
    }


    /**
     * Search the index and return the result object
     *
     * @param string  $strKeywords The keyword string
     * @param boolean $blnOrSearch If true, the result can contain any keyword
     * @param array   $arrPid      An optional array of page IDs to limit the result to
     * @param integer $intRows     An optional maximum number of result rows
     * @param integer $intOffset   An optional result offset
     * @param boolean $blnFuzzy    If true, the search will be fuzzy
     *
     * @return Database\Result The database result object
     *
     * @throws \Exception If the cleaned keyword string is empty
     */

    // CearchPro change: Add Parameters for Levensthein
    public static function searchFor($strKeywords, $blnOrSearch = false, $arrPid = array(), $intRows = 0, $intOffset = 0, $blnFuzzy = false, $levensthein = false, $maxDist = 2)
    {
        // Clean the keywords
        $strKeywords = Utf8::strtolower($strKeywords);
        $strKeywords = \StringUtil::decodeEntities($strKeywords);
        $strKeywords = preg_replace(array('/\. /', '/\.$/', '/: /', '/:$/', '/, /', '/,$/', '/[^\w\' *+".:,-]/u'), ' ', $strKeywords);

        // Check keyword string
        if (!strlen($strKeywords))
        {
            throw new \Exception('Empty keyword string');
        }

        // Split keywords
        $arrChunks = array();
        preg_match_all('/"[^"]+"|[\+\-]?[^ ]+\*?/', $strKeywords, $arrChunks);

        // CearchPro change: Use own implementation for Levenshtein
        if ($levensthein) {
            return self::levenshtein($arrChunks, $arrPid, $intRows, $intOffset, $maxDist);
        }

        $arrPhrases = array();
        $arrKeywords = array();
        $arrWildcards = array();
        $arrIncluded = array();
        $arrExcluded = array();

        foreach ($arrChunks[0] as $strKeyword)
        {
            if (substr($strKeyword, -1) == '*' && strlen($strKeyword) > 1)
            {
                $arrWildcards[] = str_replace('*', '%', $strKeyword);
                continue;
            }

            switch (substr($strKeyword, 0, 1))
            {
                // Phrases
                case '"':
                    if ($strKeyword = trim(substr($strKeyword, 1, -1)))
                    {
                        $arrPhrases[] = '[[:<:]]' . str_replace(array(' ', '*'), array('[^[:alnum:]]+', ''), $strKeyword) . '[[:>:]]';
                    }
                    break;

                // Included keywords
                case '+':
                    if ($strKeyword = trim(substr($strKeyword, 1)))
                    {
                        $arrIncluded[] = $strKeyword;
                    }
                    break;

                // Excluded keywords
                case '-':
                    if ($strKeyword = trim(substr($strKeyword, 1)))
                    {
                        $arrExcluded[] = $strKeyword;
                    }
                    break;

                // Wildcards
                case '*':
                    if (strlen($strKeyword) > 1)
                    {
                        $arrWildcards[] = str_replace('*', '%', $strKeyword);
                    }
                    break;

                // Normal keywords
                default:
                    $arrKeywords[] = $strKeyword;
                    break;
            }
        }

        // Fuzzy search
        if ($blnFuzzy)
        {
            foreach ($arrKeywords as $strKeyword)
            {
                $arrWildcards[] = '%' . $strKeyword . '%';
            }

            $arrKeywords = array();
        }

        // Count keywords
        $intPhrases = count($arrPhrases);
        $intWildcards = count($arrWildcards);
        $intIncluded = count($arrIncluded);
        $intExcluded = count($arrExcluded);

        $intKeywords = 0;
        $arrValues = array();

        // Remember found words so we can highlight them later
        $strQuery = "SELECT * FROM (SELECT tl_search_index.pid AS sid, GROUP_CONCAT(word) AS matches";

        // Get the number of wildcard matches
        if (!$blnOrSearch && $intWildcards)
        {
            $strQuery .= ", (SELECT COUNT(*) FROM tl_search_index WHERE (" . implode(' OR ', array_fill(0, $intWildcards, 'word LIKE ?')) . ") AND pid=sid) AS wildcards";
            $arrValues = array_merge($arrValues, $arrWildcards);
        }

        // Count the number of matches
        $strQuery .= ", COUNT(*) AS count";

        // Get the relevance
        $strQuery .= ", SUM(relevance) AS relevance";

        // Prepare keywords array
        $arrAllKeywords = array();

        // Get keywords
        if (!empty($arrKeywords))
        {
            $arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrKeywords), 'word=?'));
            $arrValues = array_merge($arrValues, $arrKeywords);
            $intKeywords += count($arrKeywords);
        }

        // Get included keywords
        if ($intIncluded)
        {
            $arrAllKeywords[] = implode(' OR ', array_fill(0, $intIncluded, 'word=?'));
            $arrValues = array_merge($arrValues, $arrIncluded);
            $intKeywords += $intIncluded;
        }

        // Get keywords from phrases
        if ($intPhrases)
        {
            foreach ($arrPhrases as $strPhrase)
            {
                $arrWords = explode('[^[:alnum:]]+', Utf8::substr($strPhrase, 7, -7));
                $arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrWords), 'word=?'));
                $arrValues = array_merge($arrValues, $arrWords);
                $intKeywords += count($arrWords);
            }
        }

        // Get wildcards
        if ($intWildcards)
        {
            $arrAllKeywords[] = implode(' OR ', array_fill(0, $intWildcards, 'word LIKE ?'));
            $arrValues = array_merge($arrValues, $arrWildcards);
        }

        $strQuery .= " FROM tl_search_index WHERE (" . implode(' OR ', $arrAllKeywords) . ")";

        // Get phrases
        if ($intPhrases)
        {
            $strQuery .= " AND (" . implode(($blnOrSearch ? ' OR ' : ' AND '), array_fill(0, $intPhrases, 'tl_search_index.pid IN(SELECT id FROM tl_search WHERE text REGEXP ?)')) . ")";
            $arrValues = array_merge($arrValues, $arrPhrases);
        }

        // Include keywords
        if ($intIncluded)
        {
            $strQuery .= " AND tl_search_index.pid IN(SELECT pid FROM tl_search_index WHERE " . implode(' OR ', array_fill(0, $intIncluded, 'word=?')) . ")";
            $arrValues = array_merge($arrValues, $arrIncluded);
        }

        // Exclude keywords
        if ($intExcluded)
        {
            $strQuery .= " AND tl_search_index.pid NOT IN(SELECT pid FROM tl_search_index WHERE " . implode(' OR ', array_fill(0, $intExcluded, 'word=?')) . ")";
            $arrValues = array_merge($arrValues, $arrExcluded);
        }

        // Limit results to a particular set of pages
        if (!empty($arrPid) && is_array($arrPid))
        {
            $strQuery .= " AND tl_search_index.pid IN(SELECT id FROM tl_search WHERE pid IN(" . implode(',', array_map('intval', $arrPid)) . "))";
        }

        $strQuery .= " GROUP BY tl_search_index.pid";

        // Sort by relevance
        $strQuery .= " ORDER BY relevance DESC) matches LEFT JOIN tl_search ON(matches.sid=tl_search.id)";

        // Make sure to find all words
        if (!$blnOrSearch)
        {
            // Number of keywords without wildcards
            $strQuery .= " WHERE matches.count >= " . $intKeywords;

            // Dynamically add the number of wildcard matches
            if ($intWildcards)
            {
                $strQuery .= " + IF(matches.wildcards>" . $intWildcards . ", matches.wildcards, " . $intWildcards . ")";
            }
        }

        // Return result
        $objResultStmt = \Database::getInstance()->prepare($strQuery);

        if ($intRows > 0)
        {
            $objResultStmt->limit($intRows, $intOffset);
        }

        // CearchPro change: Change return format
        return array('exact' => $objResultStmt->execute($arrValues));
    }


    /**
     * Remove an entry from the search index
     *
     * @param string $strUrl The URL to be removed
     */
    public static function removeEntry($strUrl)
    {
        $objDatabase = \Database::getInstance();

        $objResult = $objDatabase->prepare("SELECT id FROM tl_search WHERE url=?")
            ->execute($strUrl);

        while ($objResult->next())
        {
            $objDatabase->prepare("DELETE FROM tl_search WHERE id=?")
                ->execute($objResult->id);

            $objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
                ->execute($objResult->id);
        }
    }

    // CearchPro change: Use own implementation for Levenshtein
    public static function levenshtein($arrChunks, $arrPid, $intRows, $intOffset, $maxDist)
    {
        $exactresults = array();
        $moreresults = array();

        self::searchIndex($arrChunks[0], $maxDist, $exactresults, $moreresults);

        //If no 100% Results found, Return only More Results otherwise continue
        if (!empty($exactresults)) {

            $arrKeywords = $exactresults;
        } else {

            return array('more' => $moreresults);
        }

        // Remember found words so we can highlight them later
        $strQuery = "SELECT tl_search_index.pid AS sid, GROUP_CONCAT(word) AS matches";

        // Count the number of matches
        $strQuery .= ", COUNT(*) AS count";

        // Get the relevance
        $strQuery .= ", SUM(relevance) AS relevance";

        // Get meta information from tl_search
        $strQuery .= ", tl_search.*"; // see #4506

        $arrAllKeywords = array();
        $intKeywords = 0;
        $arrValues = array();

        // Get keywords
        if (!empty($arrKeywords))
        {
            $arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrKeywords), 'word_transliterated=?'));
            $arrValues = array_merge($arrValues, $arrKeywords);
            $intKeywords += count($arrKeywords);
        }

        $strQuery .= " FROM tl_search_index LEFT JOIN tl_search ON(tl_search_index.pid=tl_search.id) WHERE (" . implode(' OR ', $arrAllKeywords) . ")";

        // Limit results to a particular set of pages
        if (!empty($arrPid) && is_array($arrPid))
        {
            $strQuery .= " AND tl_search_index.pid IN(SELECT id FROM tl_search WHERE pid IN(" . implode(',', array_map('intval', $arrPid)) . "))";
        }

        $strQuery .= " GROUP BY tl_search_index.pid";

        // Sort by relevance
        $strQuery .= " ORDER BY relevance DESC";

        // Return result
        $objResultStmt = \Database::getInstance()->prepare($strQuery);

        if ($intRows > 0) {
            $objResultStmt->limit($intRows, $intOffset);
        }

        return array('exact' => $objResultStmt->execute($arrValues), 'more' => $moreresults);
    }

    public static function searchIndex($arrChunks, $maxDist, &$exactresults, &$moreresults)
    {

        $minResLen = 2;

        foreach ($arrChunks as $chunk) {

            $transChunk = \Transliterate::trans($chunk);
            $wordlength = strlen($transChunk);
            $minLen = $wordlength - $maxDist;
            $maxLen = $wordlength + $maxDist;

            $db = \Database::getInstance();
            $res = $db->query('SELECT DISTINCT word_transliterated, word FROM tl_search_index WHERE length(word_transliterated) BETWEEN ' . $minLen . ' AND ' . $maxLen);

            while ($word = $res->fetchRow()) {

                $lev = levenshtein($transChunk, $word[0]);

                if ($lev <= $maxDist) {
                    if ($lev == 0)
                        $exactresults[] = $word[0];
                    else {

                        //Clean More Results
                        //Result Length to short
                        if (strlen($word[0]) <= $minResLen) continue;

                        //Result has different Type (Prevent matches like XX = 01)
                        if (is_numeric($word[0]) && !is_numeric($transChunk)) continue;

                        $moreresults[$lev][] = array('trans' => $word[0], 'org' => $word[1]);
                    }
                }
            }
        }

        // Sort More-Results by lowest Levenshtein difference
        ksort($moreresults);
    }

    // CearchPro change: Match Keyword with Exclude List
    private static function getExclude($keyword = null)
    {
        if (is_null($keyword)) return false;

        foreach ([Config::get('cearchpro_stopwords_de'), Config::get('cearchpro_stopwords_en')] as $stopword) {

            if (strpos($stopword, $keyword) !== false){
                return true;
            }
        }
        return false;
    }

    private static function decodeEntities($entity){

        if (version_compare(VERSION .'.'.BUILD, '3.5.1', '<')) {
            return \String::decodeEntities($entity);
        }

        return \StringUtil::decodeEntities($entity);
    }

    /**
     * Prevent cloning of the object (Singleton)
     *
     * @deprecated Deprecated since Contao 4.0, to be removed in Contao 5.0.
     *             The Search class is now static.
     */
    final public function __clone() {}


    /**
     * Return the object instance (Singleton)
     *
     * @return Search The object instance
     *
     * @deprecated Deprecated since Contao 4.0, to be removed in Contao 5.0.
     *             The Search class is now static.
     */
    public static function getInstance()
    {
        @trigger_error('Using Search::getInstance() has been deprecated and will no longer work in Contao 5.0. The Search class is now static.', E_USER_DEPRECATED);

        if (static::$objInstance === null)
        {
            static::$objInstance = new static();
        }

        return static::$objInstance;
    }
}
