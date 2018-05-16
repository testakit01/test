<?php

/**
 * Fedex shipping implementation
 *
 * @category   Mage
 * @package    TM_Usa
 * @author     Magento Core Team <core@magentocommerce.com>
 */
class TM_Fedex_Model_Usa_Shipping_Carrier_Fedex extends Mage_Usa_Model_Shipping_Carrier_Fedex {

    /**
     * Forming request for rate estimation depending to the purpose
     *
     * @param string $purpose
     * @return array
     */
    protected function _formRateRequest($purpose) {
        $r = $this->_rawRequest;
        /* added code for address type validation */
        $controller = Mage::app()->getRequest()->getControllerName();
        if ($controller == 'sales_order_create' || $controller == 'sales_order_edit') {
            $addr_type = Mage::getSingleton('core/session')->getAddressType();
        } else {
            $addr_type = Mage::getSingleton('checkout/session')->getQuote()->getData('address_type_new');
        }
        $addr_type_attr = Mage::getSingleton('eav/config')->getAttribute('customer_address', 'address_type_new');
        if ($addr_type_attr->usesSource()) {
            $options = $addr_type_attr->getSource()->getAllOptions(false);
            foreach ($options as $key => $val) {
                if ($val['label'] === 'Personal') {
                    $personal = $val['value'];
                }
            }
        }
        if ($addr_type == $personal) {
            $residential_flag = 1;
        } else {
            $residential_flag = '';
        }
        /* close  code for address type validation */
        $ratesRequest = array(
            'ReturnTransitAndCommit' => true,
            'WebAuthenticationDetail' => array(
                'UserCredential' => array(
                    'Key' => $r->getKey(),
                    'Password' => $r->getPassword()
                )
            ),
            'ClientDetail' => array(
                'AccountNumber' => $r->getAccount(),
                'MeterNumber' => $r->getMeterNumber()
            ),
            'Version' => $this->getVersionInfo(),
            'RequestedShipment' => array(
                'DropoffType' => $r->getDropoffType(),
                'ShipTimestamp' => date('c'),
                'PackagingType' => $r->getPackaging(),
                'TotalInsuredValue' => array(
                    'Amount' => $r->getValue(),
                    'Currency' => $this->getCurrencyCode()
                ),
                'Shipper' => array(
                    'Address' => array(
                        'PostalCode' => $r->getOrigPostal(),
                        'CountryCode' => $r->getOrigCountry()
                    )
                ),
                'Recipient' => array(
                    'Address' => array(
                        'PostalCode' => $r->getDestPostal(),
                        'CountryCode' => $r->getDestCountry(),
                        'Residential' => (bool) $residential_flag
                    )
                ),
                'ShippingChargesPayment' => array(
                    'PaymentType' => 'SENDER',
                    'Payor' => array(
                        'AccountNumber' => $r->getAccount(),
                        'CountryCode' => $r->getOrigCountry()
                    )
                ),
                'CustomsClearanceDetail' => array(
                    'CustomsValue' => array(
                        'Amount' => $r->getValue(),
                        'Currency' => $this->getCurrencyCode()
                    )
                ),
                'RateRequestTypes' => 'LIST',
                'PackageCount' => '1',
                'PackageDetail' => 'INDIVIDUAL_PACKAGES',
                'RequestedPackageLineItems' => array(
                    '0' => array(
                        'Weight' => array(
                            'Value' => (float) $r->getWeight(),
                            'Units' => $this->getConfigData('unit_of_measure')
                        ),
                        'GroupPackageCount' => 1,
                       
                    )
                )
            )
        );

        if ($purpose == self::RATE_REQUEST_GENERAL) {
            $ratesRequest['RequestedShipment']['RequestedPackageLineItems'][0]['InsuredValue'] = array(
                'Amount' => $r->getValue(),
                'Currency' => $this->getCurrencyCode()
            );
        } else if ($purpose == self::RATE_REQUEST_SMARTPOST) {
            $ratesRequest['RequestedShipment']['ServiceType'] = self::RATE_REQUEST_SMARTPOST;
            $ratesRequest['RequestedShipment']['SmartPostDetail'] = array(
                'Indicia' => ((float) $r->getWeight() >= 1) ? 'PARCEL_SELECT' : 'PRESORTED_STANDARD',
                'HubId' => $this->getConfigData('smartpost_hubid')
            );
        }

        return $ratesRequest;
    }
    /**
     * Prepare shipping rate result based on response
     *
     * @param mixed $response
     * @return Mage_Shipping_Model_Rate_Result
     */
    protected function _prepareRateResponse($response)
    {
        $timestamp = array();
        $costArr = array();
        $priceArr = array();
        $errorTitle = 'Unable to retrieve tracking';

        if (is_object($response)) {
            if ($response->HighestSeverity == 'FAILURE' || $response->HighestSeverity == 'ERROR') {
                if (is_array($response->Notifications)) {
                    $notification = array_pop($response->Notifications);
                    $errorTitle = (string)$notification->Message;
                } else {
                    $errorTitle = (string)$response->Notifications->Message;
                }
            } elseif (isset($response->RateReplyDetails)) {
                $allowedMethods = explode(",", $this->getConfigData('allowed_methods'));

                if (is_array($response->RateReplyDetails)) {
                    foreach ($response->RateReplyDetails as $rate) {
                        $serviceName = (string)$rate->ServiceType;
                        if (in_array($serviceName, $allowedMethods)) {
                            $amount = $this->_getRateAmountOriginBased($rate);
                            $costArr[$serviceName]  = $amount;
                            $priceArr[$serviceName] = $this->getMethodPrice($amount, $serviceName);
                        $timestamp[$serviceName] = (string) $rate->DeliveryTimestamp;//checkpoint
                            
                        }
                    }
                    asort($priceArr);
                } else {
                    $rate = $response->RateReplyDetails;
                    $serviceName = (string)$rate->ServiceType;
                    if (in_array($serviceName, $allowedMethods)) {
                        $amount = $this->_getRateAmountOriginBased($rate);
                        $costArr[$serviceName]  = $amount;
                        $priceArr[$serviceName] = $this->getMethodPrice($amount, $serviceName);
                    }
                }
            }
        }

        $result = Mage::getModel('shipping/rate_result');
        if (empty($priceArr)) {
            $error = Mage::getModel('shipping/rate_result_error');
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($errorTitle);
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            $result->append($error);
        } else {
            foreach ($priceArr as $method=>$price) {
               $date = strtotime("+7 day");
                $after7 = date('Y-m-d', $date);
                if(empty($timestamp[$method])){
                    $timestamp[$method] = $after7;
                }              
                $rate = Mage::getModel('shipping/rate_result_method');
                $rate->setCarrier($this->_code);
                $rate->setCarrierTitle($this->getConfigData('title'));
                $rate->setMethod($method);
                $rate->setMethodTitle($this->getCode('method', $method));
                $rate->setCost($costArr[$method]);
                $rate->setPrice($price);
                $rate->setDeliveryTimeStamp($timestamp[$method]);//checkpoint
                $result->append($rate);
            }
        }
        return $result;
    }

}
