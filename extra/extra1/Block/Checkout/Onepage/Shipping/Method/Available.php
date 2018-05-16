<?php

/**
 * One page checkout status
 *
 * @package    TM_Fedex
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class TM_Fedex_Block_Checkout_Onepage_Shipping_Method_Available extends Mage_Checkout_Block_Onepage_Shipping_Method_Available {

    protected $_rates;
    /**
     * Get shipping rates on the basis dropship account.
     *
     * @return array
     */
    public function getShippingRates() {
        Mage::getSingleton('checkout/session')->getQuote()->setData('address_type_new', Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress()->getData('address_type_new'));
        $is_drop_ship = Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress()->getData('is_dropship_account');
        $is_dropship_accounts_attr = Mage::getSingleton('eav/config')->getAttribute('customer_address', 'is_dropship_account');
        if ($is_dropship_accounts_attr->usesSource()) {
            $options = $is_dropship_accounts_attr->getSource()->getAllOptions(false);
            foreach ($options as $key => $val) {
                if ($val['label'] === 'Yes') {
                    $yesValue = $val['value'];
                }
            }
        }
        if (empty($this->_rates)) {
            $this->getAddress()->collectShippingRates()->save();

            $groups = $this->getAddress()->getGroupedAllShippingRates();
            $free = array();
            foreach ($groups as $code => $_rates) {
                foreach ($_rates as $_rate) {
                    if (isset($is_drop_ship) && $is_drop_ship == $yesValue && $_rate->getCarrier() == 'freeshipping') {
                        $free[$code] = $_rates;
                    }
                }
            }
            if (!empty($free)) {
                $this->_rates = $free;
            } else {
                unset($groups['freeshipping']);
                $this->_rates = $groups;
            }
        }

        return $this->_rates;
    }

}
