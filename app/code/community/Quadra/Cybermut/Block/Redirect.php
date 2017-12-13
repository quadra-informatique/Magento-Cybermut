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
class Quadra_Cybermut_Block_Redirect extends Mage_Core_Block_Abstract
{

    protected function _toHtml()
    {
        $methodInstance = $this->getMethodInstance();
        $form = new Varien_Data_Form();
        $form->setAction($methodInstance->getCybermutUrl())
                ->setId('cybermut_payment_checkout')
                ->setName('cybermut_payment_checkout')
                ->setMethod('POST')
                ->setUseContainer(true);
        foreach ($methodInstance->setOrder($this->getOrder())->getFormFields() as $field => $value) {
            $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
        }

        $formHTML = $form->toHtml();

        $html = '<html><body>';
        $html.= $this->__('You will be redirected to Cybermut in a few seconds.');
        $html.= $formHTML;
        $html.= '<script type="text/javascript">document.getElementById("cybermut_payment_checkout").submit();</script>';
        $html.= '</body></html>';

        if ($methodInstance->isDebugModeActivated()) {
            Mage::getModel('cybermut/api_debug')
                    ->setRequestBody($formHTML)
                    ->save();
        }

        return $html;
    }

}
