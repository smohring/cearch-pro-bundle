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


$GLOBALS['TL_DCA']['tl_module']['palettes']['search'] = str_replace ('{config_legend}','{cearchpro_legend},levensthein, maxDist;{config_legend}', $GLOBALS['TL_DCA']['tl_module']['palettes']['search']);
$GLOBALS['TL_DCA']['tl_module']['palettes']['search'] = str_replace ('{reference_legend:hide},rootPage;','{reference_legend:hide},rootPages;', $GLOBALS['TL_DCA']['tl_module']['palettes']['search']);

$GLOBALS['TL_DCA']['tl_module']['fields']['levensthein'] = array(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['levensthein'],
	'exclude'                 => true,
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'w50 clr'),
	'sql'                     => "char(1) NOT NULL default ''"
);
$GLOBALS['TL_DCA']['tl_module']['fields']['maxDist'] = array(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['maxDist'],
	'exclude'                 => true,
	'inputType'               => 'select',
	'options'                 => array(0,1,2,3,4,5,6,7,8,9,10),
	'eval'                    => array('tl_class'=>'w50'),
	'sql'                     => "int(10) unsigned NOT NULL default '2'",
);
$GLOBALS['TL_DCA']['tl_module']['fields']['rootPages'] = array(
    'label'                   => &$GLOBALS['TL_LANG']['tl_module']['rootPage'],
    'exclude'                 => true,
    'inputType'               => 'pageTree',
    'foreignKey'              => 'tl_page.title',
    'eval'                    => array('mandatory'=>false, 'multiple'=>true, 'fieldType'=>'checkbox', 'tl_class'=>'clr'),
    'sql'                     => "blob NULL",
    'relation'                => array('type'=>'hasMany', 'load'=>'lazy')
);
