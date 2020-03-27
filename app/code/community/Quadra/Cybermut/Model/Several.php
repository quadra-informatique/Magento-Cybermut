<?php

/**
 * 1997-2016 Quadra Informatique
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is available
 * through the world-wide-web at this URL: http://www.opensource.org/licenses/OSL-3.0
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to modules@quadra-informatique.fr so we can send you a copy immediately.
 *
 * @author Quadra Informatique
 * @copyright 1997-2016 Quadra Informatique
 * @license http://www.opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
class Quadra_Cybermut_Model_Several extends Quadra_Cybermut_Model_Abstract
{

    protected $_code = 'cybermut_several';

    /**
     *  Return URL for Cybermut success response
     *
     *  @return	  string URL
     */
    protected function getSuccessURL()
    {
        return Mage::getUrl('cybermut/several/success', array('_secure' => true));
    }

    /**
     *  Return URL for Cybermut failure response
     *
     *  @return	  string URL
     */
    protected function getErrorURL()
    {
        return Mage::getUrl('cybermut/several/error', array('_secure' => true));
    }

    /**
     *  Return URL for Cybermut notify response
     *
     *  @return	  string URL
     */
    protected function getNotifyURL()
    {
        return Mage::getUrl('cybermut/several/notify', array('_secure' => true));
    }

    /**
     *  Return Order Place Redirect URL
     *
     *  @return	  string Order Redirect URL
     */
    public function getOrderPlaceRedirectUrl()
    {
        Mage::getSingleton('checkout/session')->setIsMultishipping(false);
        return Mage::getUrl('cybermut/several/redirect');
    }

    /**
     *  Return Form Fields for request to Cybermut
     *
     *  @return	  array Array of hidden form fields
     */
    public function getFormFields()
    {
        $fields = parent::getFormFields();
        return $this->getSplitCheckoutFormFields($fields);
    }

    /**
     *  Return Split Checkout Form Fields for request to Cybermut
     *
     *  @return	  array Array of hidden form fields
     */
    public function getSplitCheckoutFormFields($fields)
    {
        $nbTerms = $this->getQuote()->getPayment()->getNbrech();

        if ($nbTerms > 1) {
            $amount = $this->getAmount();

            $fields['nbrech'] = $nbTerms;

            $terms = array(
                'montantech1' => round($amount / $nbTerms, 2),
                'dateech1' => date('d/m/Y'));

            for ($i = 2; $i < $nbTerms + 1; $i++) {
                $terms['montantech' . $i] = $terms['montantech1'];
                $dateech = '+ ' . ($i - 1) . ' month';
                $terms['dateech' . $i] = date('d/m/Y', strtotime($dateech));
            }

            if ($terms['montantech1'] * $nbTerms != $amount) {
                $result = $terms['montantech1'] * ($nbTerms - 1);
                $terms['montantech1'] = $amount - $result;
            }

            $order = $this->getOrder();
            for ($i = 1; $i < $nbTerms + 1; $i++) {
                $terms['montantech' . $i] = sprintf('%.2f', $terms['montantech' . $i]) . $order->getBaseCurrencyCode();
            }

            $fields = array_merge($fields, $terms);
        }
        
        return $fields;
    }

}
