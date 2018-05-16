<?php

class JustKampers_Usa_Block_Checkout_Cart_Shipping extends Mage_Checkout_Block_Cart_Shipping
{

    /**
     * Show City in Shipping Estimation
     *
     * @return bool
     */
    public function getCityActive()
    {
        return (bool)Mage::getStoreConfig('carriers/ups/time_in_transit');
    }   
}