<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2014 Leo Feyer
 *
 * @package
 * @author    Steffen Mohring
 * @license   LGPL
 * @copyright Steffen Mohring 2014
 */


/**
 * Table tl_search_index
 */
$GLOBALS['TL_DCA']['tl_search_index']['fields']['word_transliterated'] = array(
    'sql' => "varbinary(64) NOT NULL default ''"
);