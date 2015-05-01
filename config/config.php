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
 * Frontend modules
 */
//$GLOBALS['FE_MOD']['isotope']['iso_upsratesandservice'] = 'ModuleUPSRatesAndService';
//$GLOBALS['FE_MOD']['isotope']['iso_upstracking'] = 'ModuleUPSTracking';


/**
 * Shipping methods
 */
\Isotope\Model\Shipping::registerModelType('ups', 'Rhyme\Model\Shipping\UPS');
