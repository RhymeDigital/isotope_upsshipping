<?php

/**
 * UPS Integration for Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2015 Rhyme Digital, LLC.
 *
 * @author		Blair Winans <blair@rhyme.digital>
 * @author		Adam Fisher <adam@rhyme.digital>
 * @link		http://rhyme.digital
 * @license		http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * Palettes
 */
 $GLOBALS['TL_DCA']['tl_iso_address']['palettes']['default'] = str_replace('country','country,address_classification', $GLOBALS['TL_DCA']['tl_iso_address']['palettes']['default']);


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_iso_address']['fields']['address_classification'] = array
(
	'label'					=> &$GLOBALS['TL_LANG']['tl_iso_address']['address_classification'],
	'exclude'				=> true,
	'filter'				=> true,
	'sorting'				=> true,
	'default'				=> 'residential',
	'inputType'				=> 'select',
	'options'				=> array('residential', 'business', 'unknown'),
	'reference'				=> &$GLOBALS['TL_LANG']['tl_iso_address'],
	'eval'					=> array('mandatory'=>true, 'feEditable'=>true, 'feGroup'=>'address', 'tl_class'=>'w50'),
	'sql'                   => "varchar(64) NOT NULL default ''"
);