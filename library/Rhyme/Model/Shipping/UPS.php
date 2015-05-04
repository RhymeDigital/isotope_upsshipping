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
use Ups\Rate as Ups_Rate;
use Ups\Entity\Address as Ups_Address;
use Ups\Entity\Dimensions as Ups_Dimensions;
use Ups\Entity\InsuredValue as Ups_InsuredValue;
use Ups\Entity\Package as Ups_Package;
use Ups\Entity\PackageWeight as Ups_PackageWeight;
use Ups\Entity\PackagingType as Ups_PackagingType;
use Ups\Entity\PackageServiceOptions as Ups_PackageServiceOptions;
use Ups\Entity\Service as Ups_Service;
use Ups\Entity\Shipment as Ups_Shipment;
use Ups\Entity\ShipFrom as Ups_ShipFrom;
use Ups\Entity\ShipTo as Ups_ShipTo;
use Ups\Entity\Shipper as Ups_Shipper;
use Ups\Entity\UnitOfMeasurement as Ups_UnitOfMeasurement;
use stdClass;
use Haste\Units\Mass\Scale;
use Haste\Units\Mass\Weighable;
use Haste\Units\Mass\WeightAggregate;

/**
 * Class UPS
 *
 * Copyright (C) 2015 Rhyme Digital, LLC.
 * @author		Blair Winans <blair@rhyme.digital>
 * @author		Adam Fisher <adam@rhyme.digital>
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
        $strHash = static::makeHash($objCollection, array($this->ups_enabledService));
    
        if(!Cache::has($strHash)) {
    
            //Build shipment
            $Shipment = $this->buildShipmentFromCollection($objCollection);
            
            //Get Iso Config
            $Config = Isotope::getConfig();
            
            //UPS Rate Object
            $UPS = new Ups_Rate( $Config->UpsAccessKey,
                             $Config->UpsUsername, 
                             $Config->UpsPassword, 
                             ($Config->UpsMode == 'test' ? true : false));
                             
            
            try{
                $objResponse = $UPS->getRate($Shipment);
                $fltPrice = (float) $objResponse->RatedShipment[0]->TotalCharges->MonetaryValue;
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
        $Shipment = new Ups_Shipment();
        
        // Create the service
        $Service = new Ups_Service();
        $Service->setCode($this->ups_enabledService);
        $Service->setDescription($GLOBALS['TL_LANG']['tl_iso_shipping']['ups_service'][$this->ups_enabledService]);
        $Shipment->setService($Service);
        
        //Build Shipper information
        $Shipper = new Ups_Shipper();
        $Shipper->setShipperNumber($Config->UpsAccountNumber);
        
        //ShipFrom Address
        $ShipFromAddress = static::buildAddress($Config);
        
        //Assign to Shipper
        $Shipper->setAddress($ShipFromAddress);
        $Shipment->setShipper($Shipper);
        
        //ShipFrom Object
        $ShipFrom = new Ups_ShipFrom();
        $ShipFrom->setAddress($ShipFromAddress);
        $ShipFrom->setCompanyName($Config->company);
        $Shipment->setShipFrom($ShipFrom);
        
        //ShipTo Address
        $objShippingAddress = $objCollection->getShippingAddress();
        $ShipToAddress = static::buildAddress($objShippingAddress);
        
        //ShipTo Object
	    $ShipTo = new Ups_ShipTo();
        $ShipTo->setAddress($ShipToAddress);
        $ShipTo->setAttentionName($objShippingAddress->firstname . ' ' . $objShippingAddress->lastname);
        $Shipment->setShipTo($ShipTo);
        $Shipment->setPackages($this->buildPackages($objCollection));
        
        return $Shipment;
    }
    
    /**
     * Build a UPS Cpmpatible Address Object from a Model
     * @param Contao\Model
     * @return stdClass
     */
    protected static function buildAddress(Model $objModel)
    {
        $Address = new Ups_Address();
        $arrSubdivision = explode('-', $objModel->subdivision);
        $Address->setAddressLine1($objModel->street_1);
        $Address->setAddressLine2($objModel->street_2);
        $Address->setAddressLine3($objModel->street_3);
        $Address->setCity($objModel->city);
        $Address->setStateProvinceCode(strtoupper($arrSubdivision[1]));
        $Address->setPostalCode($objModel->postal);
        $Address->setCountryCode(strtoupper($arrSubdivision[0]));
        
        return $Address;
    }
    
    
    /**
     * Build a UPS Cpmpatible Package Object
     * @param IsotopeProductCollection
     * @return stdClass
     */
    protected function buildPackages(IsotopeProductCollection $objCollection)
    {
    	$arrPackages = array();
    	
    	foreach ($objCollection->getItems() as $objItem)
    	{
			$product = $objItem->getProduct();
			$arrDimensions = $product->package_dimensions;
	        $Package = new Ups_Package();
			$strWeight = strval($this->getShippingWeight($objItem, 'lb'));
			
            for ($i = 0; $i < $objItem->quantity; $i++) {
		        
		        //Packaging Type
		        $PackagingType = new Ups_PackagingType();
		        $PackagingType->setCode('02'); //Box for now
		        $PackagingType->setDescription('');
		        $Package->setPackagingType($PackagingType);
		        
		        //Package Dimensions
		        $Dimensions = new Ups_Dimensions();
		        $UnitOfMeasurementD = new Ups_UnitOfMeasurement();
		        $UnitOfMeasurementD->setCode('IN');
		        $Dimensions->setUnitOfMeasurement($UnitOfMeasurementD);
		        $Dimensions->setLength(round($arrDimensions[0]));
		        $Dimensions->setWidth(round($arrDimensions[1]));
		        $Dimensions->setHeight(round($arrDimensions[2]));
		        $Package->setDimensions($Dimensions);
		        
		        //Package Weight
		        $PackageWeight = new Ups_PackageWeight();
		        $UnitOfMeasurementW = new Ups_UnitOfMeasurement();
		        $UnitOfMeasurementW->setCode('LBS');
		        $PackageWeight->setUnitOfMeasurement($UnitOfMeasurementW);
		        $PackageWeight->setWeight($strWeight == 0 ? '1' : $strWeight);
		        $Package->setPackageWeight($PackageWeight);
				
		        //Insured Value
				if ($this->ups_insure_packages)
				{
			        $InsuredValue = new Ups_InsuredValue();
			        $InsuredValue->setCurrencyCode('USD'); //For now
			        $InsuredValue->setMonetaryValue($objItem->getProduct()->getPrice()->getAmount(1));
			        $PackageServiceOptions = new Ups_PackageServiceOptions();
			        $PackageServiceOptions->setInsuredValue($InsuredValue);
			        $Package->setPackageServiceOptions($PackageServiceOptions);
				}
		        
		        $arrPackages[] = $Package;
	        }
    	}
        
        return $arrPackages;
    }
	
	
	
	/**
	 * Calculate the weight of all products in the cart in a specific weight unit
	 *
	 * @access public
	 * @param array
	 * @param string
	 * @return string
	 */
	public static function getShippingWeight($objItem, $unit)
	{
        if (null === $objScale) {
            $objScale = new Scale();
        }

        if (!$objItem->hasProduct()) {
            return 0.0;
        }

        $objProduct = $objItem->getProduct();

        if ($objProduct instanceof WeightAggregate) {
            $objWeight = $objProduct->getWeight();

            if (null !== $objWeight) {
                $objScale->add($objWeight);
            }

        } elseif ($objProduct instanceof Weighable) {
            $objScale->add($objProduct);
        }

        return $objScale->amountIn($unit);
	}
    

    /**
     * Build a Hash string based on the shipping address
     * @param IsotopeProductCollection
     * @return string
     */
     protected static function makeHash(IsotopeProductCollection $objCollection, $arrExtras=array())
     {
         $strBase = get_called_class();
         $strBase .= !empty($arrExtras) ? implode(',', $arrExtras) : '';
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

	/**
	 * Use output buffer to var dump to a string
	 * 
	 * @param	string
	 * @return	string 
	 */
	public static function varDumpToString($var)
	{
		ob_start();
		var_dump($var);
		$result = ob_get_clean();
		return $result;
	}
     

	
}

