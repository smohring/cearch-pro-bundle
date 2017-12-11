<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2014 Leo Feyer
 *
 * @package
 * @author    Steffen Mohring
 * @license   LGPL
 * @copyright Steffen Mohring 2017
 */

/**
 * Palettes
 */
$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'] .= ';{cearchpro_legend:hide},cearchpro_stopwords_de,cearchpro_stopwords_en';

/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_settings']['fields']['cearchpro_stopwords_de'] = $GLOBALS['TL_DCA']['tl_settings']['fields']['cearchpro_stopwords_en'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_settings']['cearchpro_stopwords_de'],
    'inputType'               => 'textarea',
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['cearchpro_stopwords_en'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_settings']['cearchpro_stopwords_en'],
    'inputType'               => 'textarea',
);