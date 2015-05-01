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

namespace Rhyme\Model\Shipping;

use Contao\Cache;
use Contao\Model;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Interfaces\IsotopeShipping;
use Isotope\Isotope;
use Isotope\Model\Shipping as Iso_Shipping;
use UPS\Rate;
use stdClass;
use Haste\Units\Mass\Scale;
use Haste\Units\Mass\Weighable;
use Haste\Units\Mass\WeightAggregate;

/**
 * Class UPS
 *
 * @copyright  HB Agency 2009-2012
 * @author     Blair Winans <bwinans@hbagency.com>
 * @author     Adam Fisher <afisher@hbagency.com>
 */
class UPS extends Iso_Shipping implements IsotopeShipping
{

    /**
     * Return calculated price for this shipping method
     * @param IsotopeProductCollection
     * @return float
     */
    public function getPrice(IsotopeProductCollection $objCollection = null)
    {
        if (null === $objCollection) {
            $objCollection = Isotope::getCart();
        }
        
        $strPrice = $this->arrData['price'];

		if ($this->isPercentage())
		{
			$fltSurcharge = (float) substr($strPrice, 0, -1);
			$fltPrice = $objCollection->subTotal / 100 * $fltSurcharge;
		}
		else
		{
			$fltPrice = (float) $strPrice;
		}
		
		//Make Call to UPS API to retrieve pricing
		$fltPrice += $this->getLiveRateQuote($objCollection);
        
        return Isotope::calculatePrice($fltPrice, $this, 'ups', $this->arrData['tax_class']);
    }
    
    
    /**
     * Return calculated price for this shipping method
     * @param IsotopeProductCollection
     * @return float
     */
    protected function getLiveRateQuote(IsotopeProductCollection $objCollection)
    {
        $fltPrice = 0.00;
    
        //get a hash for the cache
        $strHash = static::makeHash($objCollection);
    
        if(!Cache::has($strHash)) {
    
            //Build shipment
            $Shipment = $this->buildShipmentFromCollection($objCollection);
            
            //Get Iso Config
            $Config = Isotope::getConfig();
            
            //UPS Rate Object
            $UPS = new Rate( $Config->UpsAccessKey,
                             $Config->UpsUsername, 
                             $Config->UpsPassword, 
                             ($Config->UpsMode == 'test' ? true : false));
                             
            
            try{
                $objResponse = $UPS->getRate($Shipment);
                
                $fltPrice = (float) $objResponse->RatedShipment->TotalCharges->MonetaryValue;
            } catch (\Exception $e){
                //@!TODO post error message
            }
            
            Cache::set($strHash, $fltPrice);
        }
        
        return Cache::get($strHash);
    }
    
    /**
     * Build a shipment from an IsotopeCollection
     * @param IsotopeProductCollection
     * @return stdClass
     */
    protected function buildShipmentFromCollection(IsotopeProductCollection $objCollection)
    {
        //Get the Iso Config
        $Config = Isotope::getConfig();
    
        //Create the shipment
        $Shipment = new stdClass();
        
        //Apply the service information
        $Service = new stdClass();
        $Service->Code          = $this->ups_enabledService;
        $Service->Description   = $GLOBALS['TL_LANG']['tl_iso_shipping']['ups_service'][$this->ups_enabledService];
        $Shipment->Service = $Service;
        
        //Build Shipper information
        $Shipper = new stdClass();
        $Shipper->ShipperNumber = $Config->UpsAccountNumber;
        
        //ShipFrom Address
        $ShipFromAddress = static::buildAddress($Config);
        
        //Assign to Shipper
        $Shipper->Address = $ShipFromAddress;
        $Shipment->Shipper = $Shipper;
        
        //ShipFrom Object
        $ShipFrom = new stdClass();
        $ShipFrom->Address = $ShipFromAddress;
        $ShipFrom->Company = $Config->company;
        $Shipment->ShipFrom = $ShipFrom;
        
        //ShipTo Address
        $objShippingAddress = $objCollection->getShippingAddress();
        $ShipToAddress = static::buildAddress($objShippingAddress);
        
        //ShipTo Object
        $ShipTo = new stdClass();
        $ShipTo->Address = $ShipToAddress;
        $ShipTo->AttentionName = $objShippingAddress->firstname . ' ' . $objShippingAddress->lastname;
        $Shipment->ShipTo = $ShipTo;
        $Shipment->Package = array(static::buildPackage($objCollection));
        
        return $Shipment;
    }
    
    /**
     * Build a UPS Cpmpatible Address Object from a Model
     * @param Contao\Model
     * @return stdClass
     */
    protected static function buildAddress(Model $objModel)
    {
        $Address = new stdClass();
        $arrSubdivision = explode('-', $objModel->subdivision);
        $Address = new stdClass();
        $Address->AddressLine1          = $objModel->street_1;
        $Address->AddressLine2          = $objModel->street_2;
        $Address->AddressLine3          = $objModel->street_3;
        $Address->City                  = $objModel->city;
        $Address->StateProvinceCode     = strtoupper($arrSubdivision[1]);
        $Address->PostalCode            = $objModel->postal;
        $Address->CountryCode           = strtoupper($arrSubdivision[0]);
        
        return $Address;
    }
    
    
    /**
     * Build a UPS Cpmpatible Package Object
     * @param IsotopeProductCollection
     * @return stdClass
     */
    protected static function buildPackage(IsotopeProductCollection $objCollection)
    {
        $Package = new stdClass();
        $strWeight = strval($objCollection->addToScale()->amountIn('kg'));
        
        //Packaging Type
        $PackagingType = new stdClass();
        $PackagingType->Code = '02'; //Box for now
        $PackagingType->Description = '';
        $Package->PackagingType = $PackagingType;
        
        //Package Dimensions
        $Dimensions = new stdClass();
        $UnitOfMeasurementD = new stdClass();
        $UnitOfMeasurementD->Code = 'IN';
        $Dimensions->UnitOfMeasurement = $UnitOfMeasurementD;
        $Dimensions->Length = '12';
        $Dimensions->Width = '12';
        $Dimensions->Height = '12';
        $Package->Dimensions = $Dimensions;
        
        //Package Weight
        $PackageWeight = new stdClass();
        $UnitOfMeasurementW = new stdClass();
        $UnitOfMeasurementW->Code = 'KGS';
        $PackageWeight->UnitOfMeasurement = $UnitOfMeasurementW;
        $PackageWeight->Weight = $strWeight == 0 ? '0.1' : $strWeight;
        $Package->PackageWeight = $PackageWeight;
        
        return $Package;
    }
    

    /**
     * Build a Hash string based on the shipping address
     * @param IsotopeProductCollection
     * @return string
     */
     protected static function makeHash(IsotopeProductCollection $objCollection)
     {
         $strBase = '';
         $objShippingAddress = $objCollection->getShippingAddress();
         $strBase .= $objShippingAddress->street_1;
         $strBase .= $objShippingAddress->city;
         $strBase .= $objShippingAddress->subdivision;
         $strBase .= $objShippingAddress->postal;
         
         // Hash the cart too
         foreach ($objCollection->getItems() as $item)
         {
	         $strBase .= $item->quantity;
	         $strBase .= $item->id;
	         $strBase .= implode(',', $item->getOptions());
         }
         
         return md5($strBase);
     }
     

	
}

