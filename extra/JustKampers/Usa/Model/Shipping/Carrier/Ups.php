<?php

class JustKampers_Usa_Model_Shipping_Carrier_Ups extends Mage_Usa_Model_Shipping_Carrier_Ups implements Mage_Shipping_Model_Carrier_Interface {

    public function isCityRequired() {
        return (bool) Mage::getStoreConfig('carriers/ups/time_in_transit');
    }

    /**
     * Prepare and set request to this instance
     *
     * @param Mage_Shipping_Model_Rate_Request $request
     * @return Mage_Usa_Model_Shipping_Carrier_Ups
     */
    public function setRequest(Mage_Shipping_Model_Rate_Request $request) {

        parent::setRequest($request);

        $r = $this->_rawRequest;

        $r->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode());

        $r->setDestCity($request->getDestCity());

        $this->_rawRequest = $r;

        return $this;
    }

    /**
     * Get xml rates
     *
     * @return Mage_Shipping_Model_Rate_Result
     */
    protected function _getXmlQuotes() {
        $url = $this->getConfigData('gateway_xml_url');
        if (!$url) {
            $url = $this->_defaultUrls['Rate'];
        }

        $this->setXMLAccessRequest();
        $xmlRequest = $this->_xmlAccessRequest;

        $r = $this->_rawRequest;
        $params = array(
            'accept_UPS_license_agreement' => 'yes',
            '10_action' => $r->getAction(),
            '13_product' => $r->getProduct(),
            '14_origCountry' => $r->getOrigCountry(),
            '15_origPostal' => $r->getOrigPostal(),
            'origCity' => $r->getOrigCity(),
            'origRegionCode' => $r->getOrigRegionCode(),
            '19_destPostal' => Mage_Usa_Model_Shipping_Carrier_Abstract::USA_COUNTRY_ID == $r->getDestCountry() ?
                    substr($r->getDestPostal(), 0, 5) :
                    $r->getDestPostal(),
            '22_destCountry' => $r->getDestCountry(),
            'destRegionCode' => $r->getDestRegionCode(),
            '23_weight' => $r->getWeight(),
            '47_rate_chart' => $r->getPickup(),
            '48_container' => $r->getContainer(),
            '49_residential' => $r->getDestType(),
        );

        if ($params['10_action'] == '4') {


            /* ------- XML Mod For Time In Transit Params --------- */
            $params['10_action'] = 'Shoptimeintransit';
            /* ---------------- End of XML Mod -------------------- */


            $serviceCode = null; // Service code is not relevant when we're asking ALL possible services' rates
        } else {
            $params['10_action'] = 'Rate';
            $serviceCode = $r->getProduct() ? $r->getProduct() : '';
        }
        $serviceDescription = $serviceCode ? $this->getShipmentByCode($serviceCode) : '';

        $xmlRequest .= <<< XMLRequest
<?xml version="1.0"?>
<RatingServiceSelectionRequest xml:lang="en-US">
  <Request>
    <TransactionReference>
      <CustomerContext>Rating and Service</CustomerContext>
      <XpciVersion>1.0</XpciVersion>
    </TransactionReference>
    <RequestAction>Rate</RequestAction>
    <RequestOption>{$params['10_action']}</RequestOption>
  </Request>
  <PickupType>
          <Code>{$params['47_rate_chart']['code']}</Code>
          <Description>{$params['47_rate_chart']['label']}</Description>
  </PickupType>

  <Shipment>
XMLRequest;

        if ($serviceCode !== null) {
            $xmlRequest .= "<Service>" .
                    "<Code>{$serviceCode}</Code>" .
                    "<Description>{$serviceDescription}</Description>" .
                    "</Service>";
        }

        $xmlRequest .= <<< XMLRequest
      <Shipper>
XMLRequest;

        if ($this->getConfigFlag('negotiated_active') && ($shipper = $this->getConfigData('shipper_number'))) {
            $xmlRequest .= "<ShipperNumber>{$shipper}</ShipperNumber>";
        }

        if ($r->getIsReturn()) {
            $shipperCity = '';
            $shipperPostalCode = $params['19_destPostal'];
            $shipperCountryCode = $params['22_destCountry'];
            $shipperStateProvince = $params['destRegionCode'];
        } else {
            $shipperCity = $params['origCity'];
            $shipperPostalCode = $params['15_origPostal'];
            $shipperCountryCode = $params['14_origCountry'];
            $shipperStateProvince = $params['origRegionCode'];
        }

        $xmlRequest .= <<< XMLRequest
      <Address>
          <City>{$shipperCity}</City>
          <PostalCode>{$shipperPostalCode}</PostalCode>
          <CountryCode>{$shipperCountryCode}</CountryCode>
          <StateProvinceCode>{$shipperStateProvince}</StateProvinceCode>
      </Address>
    </Shipper>
    <ShipTo>
      <Address>
XMLRequest;

        /* ------- XML Mod For Time In Transit Params --------- */
        $xmlRequest .= <<< XMLRequest
            <City>{$r->getDestCity()}</City>
XMLRequest;
        /* ---------------- End of XML Mod -------------------- */

        $xmlRequest .= <<< XMLRequest
          <PostalCode>{$params['19_destPostal']}</PostalCode>
          <CountryCode>{$params['22_destCountry']}</CountryCode>
          <ResidentialAddress>{$params['49_residential']}</ResidentialAddress>
          <StateProvinceCode>{$params['destRegionCode']}</StateProvinceCode>
XMLRequest;

        $xmlRequest .= ($params['49_residential'] === '01' ? "<ResidentialAddressIndicator>{$params['49_residential']}</ResidentialAddressIndicator>" : ''
                );

        $xmlRequest .= <<< XMLRequest
      </Address>
    </ShipTo>


    <ShipFrom>
      <Address>
          <PostalCode>H8T 3H2</PostalCode>
          <CountryCode>CA</CountryCode>
          <StateProvinceCode>QC</StateProvinceCode>
      </Address>
    </ShipFrom>

    <Package>
      <PackagingType><Code>{$params['48_container']}</Code></PackagingType>
      <PackageWeight>
         <UnitOfMeasurement><Code>{$r->getUnitMeasure()}</Code></UnitOfMeasurement>
        <Weight>{$params['23_weight']}</Weight>
      </PackageWeight>
    </Package>
XMLRequest;
        if ($this->getConfigFlag('negotiated_active')) {
            $xmlRequest .= "<RateInformation><NegotiatedRatesIndicator/></RateInformation>";
        }

        /* ------- XML Mod For Time In Transit Params --------- */
        $xmlRequest .= <<< XMLRequest
    <DeliveryTimeInformation>
        <PackageBillType>03</PackageBillType>
    </DeliveryTimeInformation>
    <ShipmentTotalWeight>
        <UnitOfMeasurement>
            <Code>{$r->getUnitMeasure()}</Code>
        </UnitOfMeasurement>
        <Weight>{$params['23_weight']}</Weight>
    </ShipmentTotalWeight>
    <InvoiceLineTotal>
        <CurrencyCode>{$r->getCurrencyCode()}</CurrencyCode>
        <MonetaryValue>{$r->getBaseSubtotalInclTax()}</MonetaryValue>
    </InvoiceLineTotal>
XMLRequest;
        /* ---------------- End of XML Mod -------------------- */

        $xmlRequest .= <<< XMLRequest
   </Shipment>
</RatingServiceSelectionRequest>
XMLRequest;

        $xmlResponse = $this->_getCachedQuotes($xmlRequest);
        if ($xmlResponse === null) {
            $debugData = array('request' => $xmlRequest);
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->getConfigFlag('verify_peer'));
                $xmlResponse = curl_exec($ch);

                $debugData['result'] = $xmlResponse;
                $this->_setCachedQuotes($xmlRequest, $xmlResponse);
            } catch (Exception $e) {
                $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
                $xmlResponse = '';
            }
            $this->_debug($debugData);
        }

        return $this->_parseXmlResponse($xmlResponse);
    }

    /**
     * Prepare shipping rate result based on response
     *
     * @param mixed $response
     * @return Mage_Shipping_Model_Rate_Result
     */
    protected function _parseXmlResponse($xmlResponse) {
        $costArr = array();
        $priceArr = array();
        $timeInTransitArr = array();
        $etaArr = array();

        if (strlen(trim($xmlResponse)) > 0) {
            $xml = new Varien_Simplexml_Config();
            $xml->loadString($xmlResponse);
            $arr = $xml->getXpath("//RatingServiceSelectionResponse/Response/ResponseStatusCode/text()");
            $success = (int) $arr[0];

            if ($success === 1) {
                $arr = $xml->getXpath("//RatingServiceSelectionResponse/RatedShipment");
                $allowedMethods = explode(",", $this->getConfigData('allowed_methods'));

                // Negotiated rates
                $negotiatedArr = $xml->getXpath("//RatingServiceSelectionResponse/RatedShipment/NegotiatedRates");
                $negotiatedActive = $this->getConfigFlag('negotiated_active') && $this->getConfigData('shipper_number') && !empty($negotiatedArr);

                $allowedCurrencies = Mage::getModel('directory/currency')->getConfigAllowCurrencies();

                foreach ($arr as $shipElement) {
                    $code = (string) $shipElement->Service->Code;
                    if (in_array($code, $allowedMethods)) {

                        if ($negotiatedActive) {
                            $cost = $shipElement->NegotiatedRates->NetSummaryCharges->GrandTotal->MonetaryValue;
                        } else {
                            $cost = $shipElement->TotalCharges->MonetaryValue;
                        }

                        /* ------------ Time In Transit Mod ------------ */

                        $daysInTransit = $shipElement->TimeInTransit->ServiceSummary->EstimatedArrival->TotalTransitDays;
                        $etaDate = $shipElement->TimeInTransit->ServiceSummary->EstimatedArrival->Arrival->Date;
                        $etaTime = $shipElement->TimeInTransit->ServiceSummary->EstimatedArrival->Arrival->Time;
                        $eta = new DateTime();
                        $eta->setDate(substr($etaDate, 0, 4), substr($etaDate, 4, 2), substr($etaDate, 6, 2));
                        $eta->setTime(substr($etaTime, 0, 2), substr($etaTime, 4, 2), substr($etaTime, 4, 2));

                        /* ----------- End Time In Transit Mod --------- */

                        //convert price with Origin country currency code to base currency code
                        $successConversion = true;
                        $responseCurrencyCode = (string) $shipElement->TotalCharges->CurrencyCode;
//                        if ($responseCurrencyCode) {
//                            if (in_array($responseCurrencyCode, $allowedCurrencies)) {
//                                $cost = (float) $cost * $this->_getBaseCurrencyRate($responseCurrencyCode);
//                            } else {
//                                $errorTitle = Mage::helper('directory')->__('Can\'t convert rate from "%s-%s".', $responseCurrencyCode, $this->_request->getPackageCurrency()->getCode());
//                                $error = Mage::getModel('shipping/rate_result_error');
//                                $error->setCarrier('ups');
//                                $error->setCarrierTitle($this->getConfigData('title'));
//                                $error->setErrorMessage($errorTitle);
//                                $successConversion = false;
//                            }
//                        }

                        if ($successConversion) {
                            $costArr[$code] = $cost;
                            $priceArr[$code] = $this->getMethodPrice(floatval($cost), $code);

                            /* ------------ Time In Transit Mod ------------ */

                            $timeInTransitArr[$code] = $daysInTransit;
                            $etaArr[$code] = $eta;

                            /* ----------- End Time In Transit Mod --------- */
                        }
                    }
                }
            } else {
                $arr = $xml->getXpath("//RatingServiceSelectionResponse/Response/Error/ErrorDescription/text()");
                $errorTitle = (string) $arr[0][0];
                $error = Mage::getModel('shipping/rate_result_error');
                $error->setCarrier('ups');
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            }
        }

        $result = Mage::getModel('shipping/rate_result');
        $defaults = $this->getDefaults();
        if (empty($priceArr)) {
            $error = Mage::getModel('shipping/rate_result_error');
            $error->setCarrier('ups');
            $error->setCarrierTitle($this->getConfigData('title'));
            if (!isset($errorTitle)) {
                $errorTitle = Mage::helper('usa')->__('Cannot retrieve shipping rates');
            }
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));

            /* ------------ Time In Transit Mod ------------ */

            $error->setErrorMessage($errorTitle);

            /* ----------- End Time In Transit Mod --------- */

            $result->append($error);
        } else {

            foreach ($priceArr as $method => $price) {
                $rate = Mage::getModel('shipping/rate_result_method');

                $rate->setCarrier('ups');
                $rate->setCarrierTitle($this->getConfigData('title'));
                $rate->setMethod($method);
                $method_arr = $this->getShipmentByCode($method);

                /* ------------ Time In Transit Mod ------------ */

                $transitDays = intval($timeInTransitArr[$method]);
                //$rate->setMethodTitle($method_arr . ' (ETA: ' . $etaArr[$method]->format('d-m-Y H:i'). ')');

                /* ----------- End Time In Transit Mod --------- */

                $rate->setMethodTitle($method_arr);
                $rate->setCost($costArr[$method]);
                $newPrice = $this->currencyConverter('CAD', 'USD', $price);
                $rate->setPrice($newPrice);
                $rate->setDeliveryTimeStamp($etaArr[$method]->format('d-m-Y H:i')); //checkpoint

                $result->append($rate);
            }
        }
        return $result;
    }

    protected function currencyConverter($from_Currency, $to_Currency, $amount) {
        $from_Currency = urlencode($from_Currency);
        $to_Currency = urlencode($to_Currency);
        $encode_amount = 1;
        $get = file_get_contents("https://finance.google.com/bctzjpnsun/converter?a=$encode_amount&from=$from_Currency&to=$to_Currency");
        $get = explode("<span class=bld>", $get);
        $get = explode("</span>", $get[1]);
        $rate = preg_replace("/[^0-9\.]/", null, $get[0]);
        $converted_amount = $amount * $rate;
        return $converted_amount;
    }

    /**
     * Fetch extra tax amount based on subtotal for canada country
     * Return 0 if shipping country canada is not there
     * @var string $countryCode
     * @var int $subtotal
     * @return array
     */
    protected function getEstimatedShippingAmount($subtotal, $countryCode) {
        $ex = 0;
        if ($countryCode == "CA") { // ground shipping CANADA
            if ($subtotal <= 150)
                $ex += Mage::getStoreConfig('canada_shipping/canada_shipping_charges/fee_up_to_150', Mage::app()->getStore()->getId());  // added brokrage fee 18 for CA 
            else if ($subtotal > 150 && $subtotal <= 200)
                $ex += Mage::getStoreConfig('canada_shipping/canada_shipping_charges/fee_up_to_200', Mage::app()->getStore()->getId());  // added brokrage fee 23 for CA
            else if ($subtotal > 200 && $subtotal <= 1600)
                $ex += Mage::getStoreConfig('canada_shipping/canada_shipping_charges/fee_up_to_1600', Mage::app()->getStore()->getId()); // added brokrage fee 28 for CA
            else if ($subtotal > 1600 && $subtotal <= 5000)
                $ex += Mage::getStoreConfig('canada_shipping/canada_shipping_charges/fee_up_to_5000', Mage::app()->getStore()->getId());  // added brokrage fee 53 for CA
            else // >=5000
                $ex += Mage::getStoreConfig('canada_shipping/canada_shipping_charges/above_5000', Mage::app()->getStore()->getId());;  // // added brokrage fee 78 for CA
        }
        return $ex;
    }
    
    /**
     * Collect and get rates
     *
     * @param Mage_Shipping_Model_Rate_Request $request
     * @return Mage_Shipping_Model_Rate_Result|bool|null
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request) {
        if (!$this->getConfigFlag($this->_activeFlag)) {
            return false;
        }

        $this->setRequest($request);

        $this->_result = $this->_getQuotes();
        $this->_updateFreeMethodQuote($request);
        $subtotal = Mage::helper('checkout')->formatPrice(Mage::getSingleton('checkout/cart')->getQuote()->getSubtotal());
        $shippingRates = $this->getResult()->getAllRates();
        foreach ($shippingRates as $rate) {
            $shippingEstimate = $this->getEstimatedShippingAmount($subtotal, 'CA');
            $rate->setCost($rate->getCost() + $shippingEstimate);
            $rate->setPrice($rate->getPrice() + $shippingEstimate);
        }
        return $this->getResult();
    }


}
