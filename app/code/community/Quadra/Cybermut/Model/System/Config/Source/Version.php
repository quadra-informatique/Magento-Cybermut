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
class Quadra_Cybermut_Model_System_Config_Source_Version
{

    public function toOptionArray()
    {
        $options = array();
        $options[] = array(
            'value' => '',
            'label' => Mage::helper('adminhtml')->__('-- Please Select --')
        );

        $options[] = array(
            'value' => '1.2open',
            'label' => '1.2open'
        );
        $options[] = array(
            'value' => '3.0',
            'label' => '3.0'
        );
        $options[] = array(
        		'value' => '4.0',
        		'label' => '4.0'
        );

        return $options;
    }

}
