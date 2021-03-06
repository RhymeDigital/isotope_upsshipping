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

class ShippingUPSMultiple extends IsotopeShipping
{

	/**
	 * Current Shipment array
	 * @var array
	 */
	protected $Shipment;
	protected $blnShowError = false;
	/**
	 * Initialize the object
	 *
	 * @access public
	 * @param array $arrRow
	 * @param array $arrShipment
	 */
	public function __construct($arrRow, $arrShipment=array() )
	{
		parent::__construct($arrRow);
		
		if(!count($arrShipment) && TL_MODE == 'FE')
		{
			$arrProducts = $this->Isotope->Cart->getProducts();
			$arrProductIds = array();
			
			foreach ($arrProducts as $objProduct)
			{
				$arrProductIds[] = $objProduct->cart_id;
			}
			
			$arrShipment = array(
				'address' => $this->Isotope->Cart->shippingAddress,
				'products'	=> $arrProducts,
				'productids' => $arrProductIds,
				'quantity'	=> $this->Isotope->Cart->items
			);
		}
		
		//Build a Shipments array from passed data or the current cart
		$this->Shipment = $arrShipment;
	}
	
	/**
	 * Return an object property
	 *
	 * @access public
	 * @param string
	 * @return mixed
	 */
	public function __get($strKey)
	{

		switch( $strKey )
		{
			case 'price':
				$arrShipment = $this->Shipment;
				
				if(	!count($this->Shipment) ||
					($arrShipment['address']['postal']==$this->Isotope->Config->postal && 
					$arrShipment['address']['subdivision']==$this->Isotope->Config->subdivision && 
					$arrShipment['address']['country']==$this->Isotope->Config->country && 
					(!isset($arrShipment['address']['firstname']) ||
					!isset($arrShipment['address']['lastname']) ||
					!isset($arrShipment['address']['city']) ||
					!isset($arrShipment['address']['subdivision']) ||
					!isset($arrShipment['address']['postal']) ||
					!isset($arrShipment['address']['country']) ||
					!isset($arrShipment['address']['street_1'])) ||
					($arrShipment['address']['firstname']=='' ||
					 $arrShipment['address']['lastname']=='' ||
					 $arrShipment['address']['city']=='' ||
					 $arrShipment['address']['subdivision']=='' ||
					 $arrShipment['address']['postal']=='' ||
					 $arrShipment['address']['country']=='' ||
					 $arrShipment['address']['street_1']=='')
				  ))
				{
				
					return 0;
				}
					
				$blnShowError = false;
				$strPrice = $this->arrData['price'];
				$blnPercentage = substr($strPrice, -1) == '%' ? true : false;

				if ($blnPercentage)
				{
					$fltSurcharge = (float)substr($strPrice, 0, -1);
					$fltPrice = $this->Isotope->Cart->subTotal / 100 * $fltSurcharge;
				}
				else
				{
					$fltPrice = (float)$strPrice;
				}
				
				$arrPackage = $this->buildShipment();

				list($arrOrigin, $arrDestination, $arrShipment) = $arrPackage;

				//Cache the request so we don't have to run it again as the API is slow
				$strRequestHash = md5(implode('.',$arrDestination) . $arrShipment['service'] . $arrShipment['weight'] . implode('.',$this->Shipment['productids']) . $this->Shipment['quantity']);
												
				if( $_SESSION['CHECKOUT_DATA']['UPS'][$strRequestHash])
				{
					$arrResponse = $_SESSION['CHECKOUT_DATA']['UPS'][$strRequestHash];
				}
				else
				{
					// Construct UPS Object: For now, Origin is assumed to be the same for origin and shipping info
					$objUPSAPI = new UpsAPIRatesAndService($arrShipment, $arrOrigin, $arrOrigin, $arrDestination); 
					$strRequestXML = $objUPSAPI->buildRequest('RatingServiceSelectionRequest');
					$arrResponse = $objUPSAPI->sendRequest($strRequestXML);
					$_SESSION['CHECKOUT_DATA']['UPS'][$strRequestHash] = $arrResponse;
					if($this->blnShowError){
						$blnShowError = true;
					}
				}
						
				if((int)$arrResponse['RatingServiceSelectionResponse']['Response']['ResponseStatusCode']==1)
				{
					$fltUPSPrice = floatval($arrResponse['RatingServiceSelectionResponse']['RatedShipment']['TotalCharges']['MonetaryValue']);
					
				}
				elseif($blnShowError)
				{
					$strLogMessage = sprintf('Error in shipping digest: %s - %s',$arrResponse['RatingServiceSelectionResponse']["Response"]["ResponseStatusDescription"], $arrResponse['RatingServiceSelectionResponse']["Response"]["Error"]["ErrorDescription"]);
						$strMessage = sprintf('%s - %s',$arrResponse['RatingServiceSelectionResponse']["Response"]["ResponseStatusDescription"], $arrResponse['RatingServiceSelectionResponse']["Response"]["Error"]["ErrorDescription"]);
				
					if( (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')  ){
						// add something if this is an ajax with error?
					}
					else{	
						//Log and display error if this is not an AJAX request to prevent a billion error msgs
						$_SESSION['ISO_ERROR']['ups'] = $strMessage;
							
					}
					
					$this->log($strLogMessage, __METHOD__, TL_ERROR);
				}
															
				return $this->Isotope->calculatePrice(($fltPrice + $fltUPSPrice), $this, 'price', $this->arrData['tax_class']);
				break;
				
			case 'available':
				$blnAvailable = ($this->price > 0 ? parent::__get('available') : false);
			
				// HOOK for determining availability
				if (isset($GLOBALS['ISO_HOOKS']['shippingAvailable']) && is_array($GLOBALS['ISO_HOOKS']['shippingAvailable']))
				{
					foreach ($GLOBALS['ISO_HOOKS']['shippingAvailable'] as $callback)
					{
						$this->import($callback[0]);
						$blnAvailable = $this->$callback[0]->$callback[1]($blnAvailable, $this);
					}
				}
			
				return $blnAvailable;
				break;
		}

		return parent::__get($strKey);
	}

	
	/**
	 * Button Callback for the backend interface for label generation
	 *
	 * @access public
	 * @param int
	 * @return string
	 */
	public function backendInterface($intOrderId, $blnMultiple=false, $intPackageId=0)
	{	
		if($blnMultiple)
		{
			return $this->backendInterfaceMultiple($intOrderId, $intPackageId);
		}
		
		$objOrder = new IsotopeOrder();
		$strFormId = 'ups_backend_interface';
		
		//Check for valid order
		if(!$objOrder->findBy('id', $intOrderId))
		{
			$this->log('Invalid order id.', __METHOD__, TL_ERROR);	
			$this->redirect('contao/main.php?act=error');
		}
		
		//Get the order's products
		$arrProducts = $objOrder->getProducts();

		//Build the initial compiled package data array
		$arrPackage = array(
			'id' => $objorder->id,
			'address' => deserialize($objOrder->shipping_address, true),
			'formattedaddress' => $this->Isotope->generateAddressString(deserialize($objOrder->shipping_address, true), $this->Isotope->Config->shipping_fields),
			'status' => $GLOBALS['TL_LANG']['ISO']['multipleshipping'][$objOrder->shipping_status],
			'formid' => $strFormId . '_' . $objOrder->id
		);
		
		//Check for an existing label thumbnail and create one if it has not been created
		if($objOrder->ups_label)
		{
			//Set a cache name
			$strCacheName = 'system/html/ups_label_' . $objOrder->order_id . '_' . $objPackage->id . substr(md5($arrPackage['formattedaddress']), 0, 8) . '.gif';
			$arrPackage['label'] = $this->getShippingLabelImage($objOrder->ups_label, $strCacheName, 75, 75, 'exact');
			$arrPackage['labelLink'] = $this->Environment->request . '&printLabel=' . $arrPackage['formid'];
			
			//Now that we have the label created check for request to output to PDF
			if($this->Input->get('printLabel') == $arrPackage['formid'])
			{
				$this->printShippingLabel($strCacheName, 'order_' . $objOrder->order_id . '_' . $intPackageId, true);
			}
		}
		
		//Add tracking number	
		if(strlen($objOrder->ups_tracking_number))
			$arrPackage['tracking'] = $objOrder->ups_tracking_number;
		
		//Add package products
		$arrPackage['products'] = $arrProducts;
		
		//Data has been submitted. Send request for tracking numbers and label
		if($this->Input->post('FORM_SUBMIT')==$arrPackage['formid'])
		{
			$this->Shipment = $arrPackage;
			
			list($arrOrigin, $arrDestination, $arrShipment) = $this->buildShipment();
			
			$objUPSAPI = new UpsAPIShipping($arrShipment, $arrOrigin, $arrOrigin, $arrDestination);
			$xmlShip = $objUPSAPI->buildRequest();
			$arrResponse = $objUPSAPI->sendRequest($xmlShip);
			
			//Request was successful - add the new data to the package
			if((int)$arrResponse['ShipmentAcceptResponse']['Response']['ResponseStatusCode']==1)
			{				
				$objOrder->ups_tracking_number = $arrResponse['ShipmentAcceptResponse']['ShipmentResults']['ShipmentIdentificationNumber'];
				$objOrder->ups_label = $arrResponse['ShipmentAcceptResponse']['ShipmentResults']['PackageResults']['LabelImage']['GraphicImage'];
				$objOrder->save();
				
				$strCacheName = 'system/html/ups_label_' . $objOrder->order_id . '_' . $objPackage->id . substr(md5($arrPackage['formattedaddress']), 0, 8) . '.gif';
				$arrPackage['label'] = $this->getShippingLabelImage($objOrder->ups_label, $strCacheName);
				$arrPackage['tracking'] = $objOrder->ups_tracking_number;
			}
			else
			{
				//Request returned an error
				$strDescription = $arrResponse['ShipmentAcceptResponse']["Response"]["ResponseStatusDescription"];
				$strError = $arrResponse['ShipmentAcceptResponse']["Response"]["Error"]["ErrorDescription"];
				$_SESSION['TL_ERROR'][] = $strDescription . ' - ' . $strError;
				$this->log(sprintf('Error in shipping digest: %s - %s',$strDescription, $strError), __METHOD__, TL_ERROR);
				$this->redirect('contao/main.php?act=error');
			}
		}
		
		//Set template data
		$objTemplate = new IsotopeTemplate('be_iso_ups');
		$objTemplate->setData($arrPackage);
		$objTemplate->message = $strMessage ? $strMessage : '';
		$objTemplate->labelHeader = $GLOBALS['TL_LANG']['MSC']['labelLabel'];
		$objTemplate->trackingHeader = $GLOBALS['TL_LANG']['MSC']['trackingNumberLabel'];
		$objTemplate->addressHeader = $GLOBALS['TL_LANG']['MSC']['shippingAddress'];
		$objTemplate->statusHeader = $GLOBALS['TL_LANG']['MSC']['shippingStatus'];
		$objTemplate->submitLabel = $objOrder->shipping_status != 'not_shipped' ? $GLOBALS['TL_LANG']['MSC']['re-ship'] : $GLOBALS['TL_LANG']['MSC']['ship'];
		
		return '<div id="tl_buttons">
	<a href="'.ampersand(str_replace('&key=shipping', '', $this->Environment->request)).'" class="header_back" title="'.$GLOBALS['TL_LANG']['MSC']['backBT'].'">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.sprintf($GLOBALS['TL_LANG']['ISO']['multipleshipping_backend'], $objOrder->order_id).'</h2>

<div class="tl_formbody_edit">' . 
		
		$objTemplate->parse() .

'</div>

<div class="tl_formbody_submit">
<div class="tl_submit_container">
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="Go back">
</div>
</div>';
			
	}
	
	
	/**
	 * Button Callback for the MultipleShipping backend interface for label generation
	 *
	 * @access public
	 * @param int
	 * @return string
	 */
	public function backendInterfaceMultiple($intOrderId, $intPackageId=0)
	{
		$objOrder = new IsotopeOrder();
		$strFormId = 'ups_backend_interface';
		
		//Check for valid order
		if(!$objOrder->findBy('id', $intOrderId))
		{
			$this->log('Invalid order id.', __METHOD__, TL_ERROR);	
			$this->redirect('contao/main.php?act=error');
		}
		
		//Get the order's products
		$arrProducts = $objOrder->getProducts();
		
		//Get the package data
		$objPackage = $this->Database->execute("SELECT * FROM tl_iso_packages WHERE id=$intPackageId");
		
		if(!$objPackage->numRows) 
			return '<p class="tl_gerror">'.$GLOBALS['TL_LANG']['ISO']['backendShippingNotFound'].'</p>';

		//Build the initial compiled package data array
		$arrPackage = array(
			'id' => $objPackage->id,
			'address' => deserialize($objPackage->order_address, true),
			'formattedaddress' => $this->Isotope->generateAddressString(deserialize($objPackage->order_address, true), $this->Isotope->Config->shipping_fields),
			'status' => $GLOBALS['TL_LANG']['ISO']['multipleshipping'][$objPackage->status],
			'formid' => $strFormId . '_' . $objPackage->id
		);
		
		//Check for an existing label thumbnail and create one if it has not been created
		if($objPackage->ups_label)
		{
			//Set a cache name
			$strCacheName = 'system/html/ups_label_' . $objOrder->order_id . '_' . $objPackage->id . substr(md5($arrPackage['formattedaddress']), 0, 8) . '.gif';
			$arrPackage['label'] = $this->getShippingLabelImage($objPackage->ups_label, $strCacheName, 75, 75, 'exact');
			$arrPackage['labelLink'] = $this->Environment->request . '&printLabel=' . $arrPackage['formid'];
			
			//Now that we have the label created check for request to output to PDF
			if($this->Input->get('printLabel') == $arrPackage['formid'])
			{
				$this->printShippingLabel($strCacheName, 'order_' . $objOrder->order_id . '_' . $intPackageId, true);
			}
		}
		
		//Add tracking number	
		if(strlen($objPackage->ups_tracking_number))
			$arrPackage['tracking'] = $objPackage->ups_tracking_number;

		
		//Add package products
		$arrShipmentProducts = $this->Database->execute("SELECT product_id FROM tl_iso_order_items WHERE package_id=$objPackage->id")->fetchEach('product_id');
		
		foreach($arrProducts as $objProduct)
		{
			if(in_array($objProduct->id, $arrShipmentProducts))
				$arrPackage['products'][] = $objProduct;
		}
		
		//Data has been submitted. Send request for tracking numbers and label
		if($this->Input->post('FORM_SUBMIT')==$arrPackage['formid'])
		{
			$this->Shipment = $arrPackage;
			
			list($arrOrigin, $arrDestination, $arrShipment) = $this->buildShipment();
			
			if ($this->isValidDestination($arrDestination)){
				$objUPSAPI = new UpsAPIShipping($arrShipment, $arrOrigin, $arrOrigin, $arrDestination);
				$xmlShip = $objUPSAPI->buildRequest();
				$arrResponse = $objUPSAPI->sendRequest($xmlShip);
			}
			//Request was successful - add the new data to the package
			if((int)$arrResponse['ShipmentAcceptResponse']['Response']['ResponseStatusCode']==1)
			{				
				$objOrder->ups_tracking_number = $arrResponse['ShipmentAcceptResponse']['ShipmentResults']['ShipmentIdentificationNumber'];
				$objOrder->ups_label = $arrResponse['ShipmentAcceptResponse']['ShipmentResults']['PackageResults']['LabelImage']['GraphicImage'];
				$objOrder->save();
				
				if($this->Database->tableExists('tl_iso_packages') && $arrPackage['formid'] != $strFormId . '_' . 'order')
				{
					$this->Database->prepare("UPDATE tl_iso_packages SET ups_tracking_number=?, ups_label=?, status='shipped' WHERE id=?")
								  			->execute($objOrder->ups_tracking_number, $objOrder->ups_label, $arrPackage['id']);
				}
				
				$strCacheName = 'system/html/ups_label_' . $objOrder->order_id . '_' . $objPackage->id . substr(md5($arrPackage['formattedaddress']), 0, 8) . '.gif';
				$arrPackage['label'] = $this->getShippingLabelImage($objOrder->ups_label, $strCacheName);
				$arrPackage['tracking'] = $objOrder->ups_tracking_number;
			}
			elseif($this->isValidDestination($arrDestination) == false){
			
			}
			else
			{
				//Request returned an error
				$strDescription = $arrResponse['ShipmentAcceptResponse']["Response"]["ResponseStatusDescription"];
				$strError = $arrResponse['ShipmentAcceptResponse']["Response"]["Error"]["ErrorDescription"];
				$_SESSION['TL_ERROR'][] = $strDescription . ' - ' . $strError;
				$this->log(sprintf('Error in shipping digest: %s - %s',$strDescription, $strError), __METHOD__, TL_ERROR);
				$this->redirect('contao/main.php?act=error');
			}
		}
		
		//Set template data
		$objTemplate = new IsotopeTemplate('be_iso_ups');
		$objTemplate->setData($arrPackage);
		$objTemplate->labelHeader = $GLOBALS['TL_LANG']['MSC']['labelLabel'];
		$objTemplate->trackingHeader = $GLOBALS['TL_LANG']['MSC']['trackingNumberLabel'];
		$objTemplate->addressHeader = $GLOBALS['TL_LANG']['MSC']['shippingAddress'];
		$objTemplate->statusHeader = $GLOBALS['TL_LANG']['MSC']['shippingStatus'];
		$objTemplate->submitLabel = $objPackage->status != 'not_shipped' ? $GLOBALS['TL_LANG']['MSC']['re-ship'] : $GLOBALS['TL_LANG']['MSC']['ship'];
		
		return $objTemplate->parse();
	}
	
	
	/**
	 * Function to build an array for Origin, Destination, and Shipment based on ProductCollection
	 *
	 * @access protected
	 * @param IsotopeProductCollection
	 * @return array
	 */
	protected function buildShipment()
	{
		$arrPackage = $this->Shipment;
		
		$arrSubDivisionShipping = explode('-',$arrPackage['address']['subdivision']);
		$arrSubDivisionStore = explode('-',$this->Isotope->Config->subdivision);
		$this->blnShowError = false;

		if( 
			(strtoupper($arrPackage['address']['street_1']) == strtoupper($this->Isotope->Config->street_1) ) &&
			(strtoupper($arrPackage['address']['city']) == strtoupper($this->Isotope->Config->city) ) &&
			(strtoupper($arrPackage['address']['postal']) == strtoupper($this->Isotope->Config->postal) ) &&
			(strtoupper($arrSubDivisionShipping[1]) == strtoupper($arrSubDivisionStore[1]) )
			
			)
		{	
			
			$this->blnShowError = true;
			$arrDestination = array
			(
				'name'			=> '',
				'company'		=> '',
				'street'		=> '',
				'street2'		=> '',
				'street3'		=> '',
				'city'			=> '',
				'state'			=> '',
				'zip'			=> '',
				'country'		=> ''
			);
		}
		else
		{
			$arrDestination = array
			(
				'name'			=> $arrPackage['address']['firstname'] . ' ' . $arrPackage['address']['lastname'],
				'company'		=> $arrPackage['address']['company'],
				'street'		=> strtoupper($arrPackage['address']['street_1']),
				'street2'		=> strtoupper($arrPackage['address']['street_2']),
				'street3'		=> strtoupper($arrPackage['address']['street_3']),
				'city'			=> strtoupper($arrPackage['address']['city']),
				'state'			=> $arrSubDivisionShipping[1],
				'zip'			=> $arrPackage['address']['postal'],
				'country'		=> strtoupper($arrPackage['address']['country'])
			);
		}	

		$arrOrigin = array
		(
			'name'			=> $this->Isotope->Config->company, //$this->Isotope->Config->firstname . ' ' . $this->Isotope->Config->lastname,
			'phone'			=> $this->Isotope->Config->phone,
			'company'		=> $this->Isotope->Config->company,
			'street'		=> strtoupper($this->Isotope->Config->street_1),
			'street2'		=> strtoupper($this->Isotope->Config->street_2),
			'street3'		=> strtoupper($this->Isotope->Config->street_3),
			'city'			=> strtoupper($this->Isotope->Config->city),
			'state'			=> $arrSubDivisionStore[1],
			'zip'			=> $this->Isotope->Config->postal,
			'country'		=> strtoupper($this->Isotope->Config->country),
			'number'		=> $this->Isotope->Config->UpsAccountNumber
		);

		$arrShipment['service'] = ((integer)$this->ups_enabledService < 10 ? $this->ups_enabledService : $this->ups_enabledService);		//Ground for now

		$arrShipment['pickup_type']	= array
		(
			'code'			=> '01',		//default to one-time, but needs perhaps to be chosen by store admin.
			'description'	=> ''
		);

		$fltWeight = $this->getShippingWeight($arrPackage['products'], 'lb');

		$arrShipment['packages'][] = array
		(
			'packaging'		=> array
			(
				'code'			=> '02',	//counter
				'description'	=> 'Customer Supplied'
			),				
			'units'		=> 'LBS',
			'weight'	=> ceil($fltWeight)
		);
		
		return array($arrOrigin, $arrDestination, $arrShipment);
	}
	
	
	
	 /**
	 * Calculate the weight of all products in the cart in a specific weight unit
	 *
	 * @access public
	 * @param array
	 * @param string
	 * @return string
	 */
	public function getShippingWeight($arrProducts, $unit)
	{
		$arrWeights = array();

		foreach( $arrProducts as $objProduct )
		{
			$arrWeight = deserialize($objProduct->shipping_weight, true);
			$intQuantity = $objProduct->quantity_requested > 0 ? $objProduct->quantity_requested : 1;
			$arrWeight['value'] = floatval($arrWeight['value']) * $intQuantity;

			$arrWeights[] = $arrWeight;
		}

		return $this->Isotope->calculateWeight($arrWeights, $unit);
	}
	
	
	/**
	 * Compile content for printing a shipping label
	 *
	 * @access protected
	 * @param array
	 * @param string
	 * @return string
	 */
	protected function printShippingLabel($strImagePath, $strTitle, $blnOutput=true)
	{
		$objTemplate = new IsotopeTemplate('be_iso_upslabel');
		$objTemplate->label = $this->Environment->base . '/' . $strImagePath;
		$objTemplate->title = $strTitle;

		$this->generatePDF($strTitle, $objTemplate->parse(), true);
	}
	
	
	/**
	 * Return a GIF image from shipping label data or cached image
	 *
	 * @access protected
	 * @param string
	 * @param string
	 * @param string
	 * @param string
	 * @param string
	 * @return string
	 */
	protected function getShippingLabelImage($strImageData, $strCacheName, $width='', $height='', $mode='')
	{
		$strImage = '';
		
		//Check for existing file
		if (file_exists(TL_ROOT . '/' . $strCacheName))
		{
			$strImage = $this->getImage($strCacheName, $width, $height, $mode);
		}
		else
		{
			//Create a new one
			$data = base64_decode($strImageData);
			$img = imagecreatefromstring($data);
			if ($img !== false) 
			{
				$img = imagegif($img, TL_ROOT . '/' . $strCacheName);
				$strImage = $this->getImage($img, $width, $height, $mode);
			}
		}
	
		return $strImage;
	}
	
	

	/**
	 * Generate a PDF from precompiled HTML content
	 *
	 * @access protected
	 * @param array
	 * @param string
	 * @return string
	 */
	protected function generatePDF($strTitle, $strHTML, $blnOutput=true, $pdf=NULL)
	{
		if (!is_object($pdf))
		{
			// TCPDF configuration
			$l['a_meta_dir'] = 'ltr';
			$l['a_meta_charset'] = $GLOBALS['TL_CONFIG']['characterSet'];
			$l['a_meta_language'] = $GLOBALS['TL_LANGUAGE'];
			$l['w_page'] = 'page';

			// Include library
			require_once(TL_ROOT . '/system/config/tcpdf.php');
			require_once(TL_ROOT . '/plugins/tcpdf/tcpdf.php');

			// Create new PDF document
			$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true);

			// Set document information
			$pdf->SetCreator(PDF_CREATOR);
			$pdf->SetAuthor(PDF_AUTHOR);

			// Remove default header/footer
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);

			// Set margins
			$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);

			// Set auto page breaks
			$pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

			// Set image scale factor
			$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

			// Set some language-dependent strings
			$pdf->setLanguageArray($l);

			// Initialize document and add a page
			$pdf->AliasNbPages();

			// Set font
			$pdf->SetFont(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN);
		}

		// Start new page
		$pdf->AddPage();

		// Write the HTML content
		$pdf->writeHTML($strHTML, true, 0, true, 0);

		if ($blnOutput)
		{
			// Close and output PDF document
			// @todo $strInvoiceTitle is not defined
			$pdf->lastPage();
			$pdf->Output(standardize(ampersand($strTitle, false), true) . '.pdf', 'D');

			// Stop script execution
			exit;
		}

		return $pdf;
	}
	
	
	
	protected function isValidDestination($arrDestination)
	{
		if( strlen($arrDestination['street']) > 0 && strlen($arrDestination['zip']) > 0 && strlen($arrDestination['country']) > 0 && strlen($arrDestination['state']) > 0 && strlen($arrDestination['city']) > 0){
			return true;
		}
		else{
			return false;
		}
	
	}
	
	/**
	 * Get the checkout surcharge for this shipping method
	 */
	public function getSurcharge($objCollection)
	{
		if ($this->price == 0)
		{
			return false;
		}

		return $this->Isotope->calculateSurcharge(
								$this->price,
								($GLOBALS['TL_LANG']['MSC']['shippingLabel'] . ' (' . $this->label . ')'),
								$this->arrData['tax_class'],
								$objCollection->getProducts(),
								$this);
	}
	
}

