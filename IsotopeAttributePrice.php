<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2011 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Ruud Walraven 2010
 * @author     Ruud Walraven <ruud.walraven@gmail.com>
 * @license    LGPL
 */

class IsotopeAttributePrice extends Frontend
{
	/**
	 * Add custom javascript
	 */
	protected function __construct()
	{
		parent::__construct();
		// $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/isotope_attribute_price/html/isotope_attribute_price.js';
	}
	
	/**
	 * Adjust the price based the attribute
	 */
	public function attributePrice($fltPrice, $objSource, $strField, $intTaxClass)
	{
		if ($objSource instanceof IsotopeProduct)
		{
			foreach($objSource->getAttributes() as $field => $value)
			{
				if (isset($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$field]) && !empty($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$field]['attributes']['options'])) {
					$arrOptions = deserialize($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$field]['attributes']['options']);

					if ($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$field]['attributes']['customer_defined'])
					{
						$arrUserOptions = $objSource->getOptions(true);
						$optionsKey = $this->search($arrOptions, 'value', $arrUserOptions[$field]);
					}
					else
					{
						$optionsKey = $this->search($arrOptions, 'value', $value);
					}

					if (($optionsKey !== false) && isset($arrOptions[$optionsKey]['price']))
					{
						$operator = substr($arrOptions[$optionsKey]['price'], 0, 1);
						$operator = preg_replace('/[^+-]/', '', $operator);
						$type = substr($arrOptions[$optionsKey]['price'], -1);
						$type = preg_replace('/[^%]/', '', $type);
						$attrPrice = floatval(preg_replace('/[^0-9,\.]/', '', $arrOptions[$optionsKey]['price']));
						
						switch ($operator)
						{
							case '-':
								$fltPrice = ($type == '%' ? $fltPrice * (1 - ($attrPrice/100)) : $fltPrice - $attrPrice);
								break;
								
							case '+':
							default:
								$fltPrice = ($type == '%' ? $fltPrice * (1 + ($attrPrice/100)) : $fltPrice + $attrPrice);
						}
					}
				}
			}
		}

		return $fltPrice;
	}

	/**
	 * Adds the price to the return values on Ajax call
	 */
	public function updateAjaxAttributePrice($arrOptions, $objSource)
	{
		$found = 0;
		$prodOptions = $objSource->getOptions(true);
		foreach( $objSource->getAttributes() as $attribute => $arrAttribute )
		{
			if (!isset($prodOptions[$attribute])) {
				if ($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['customer_defined']) {
					$found++;
					$prodOptions[$attribute] = $this->Input->post($attribute);
				}
			}
		}

		if ($found) {
			$this->Import('Isotope');
			$objSource->setOptions($prodOptions);
			$objHasVariants = $this->Database->prepare("SELECT `variants` FROM `tl_iso_producttypes` WHERE `id`=?")
											 ->limit(1)
											 ->execute($objSource->type);
			$keyExists = false;
			if($objHasVariants->numRows && $objHasVariants->variants)
			{
				foreach ($arrOptions as $key => $option) {
					if ($option['id'] == (($objSource->formSubmit).'_price'))
					{
						$keyExists = $key;
						break;
					}
				}
			}
			
			if(!$objSource->pid && $objSource->low_price)
			{
				$strBuffer = sprintf($GLOBALS['TL_LANG']['MSC']['priceRangeLabel'], $this->Isotope->formatPriceWithCurrency($objSource->price));
			}
			else
			{
				$strBuffer = $this->Isotope->formatPriceWithCurrency($objSource->price);
				
				if ($objSource->original_price > 0 && $objSource->price != $objSource->original_price)
				{
					$strBuffer = '<div class="original_price"><strike>' . $objSource->formatted_original_price . '</strike></div><div class="price">' . $strBuffer . '</div>';
				}
			}
			$strBuffer = '<div class="iso_attribute price" id="'.$objSource->formSubmit.'_price">' . $strBuffer . '</div>';
			
			if ($keyExists !== false)
			{
				$arrOptions[$key]['html'] = $strBuffer;
			}
			else
			{
				$arrOptions[] = array
				(
					'id'		=> $objSource->formSubmit . '_price',
					'html'		=> $strBuffer,
				);
			}
		}

		return $arrOptions;
	}
	
	/**
	 * Change the js line that activates the product onchange features
	 */
	public function pickAdditionalAjaxOptions($objTemplate, $objSource)
	{
		$arrAjaxOptions = array();
		$arrAttributes = $objSource->getAttributes();

		foreach( $arrAttributes as $attribute => $varValue )
		{
			// Only add user defined attributes that aren't in the js line already (like variant options)
			if ($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['customer_defined']
			&& !$GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['variant_option']
			&& !empty($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['options']))
			{
				$arrAjaxOptions[] = $attribute;
			}
		}

		end($GLOBALS['TL_MOOTOOLS']);
		$key = key($GLOBALS['TL_MOOTOOLS']);
		$GLOBALS['TL_MOOTOOLS'][$key] = str_replace
										(
											'],',
											", 'ctrl_".implode("_".$objSource->formSubmit."', 'ctrl_", $arrAjaxOptions)."_".$objSource->formSubmit."'],",
											$GLOBALS['TL_MOOTOOLS'][$key]
										);

		return $objTemplate;
	}

	/**
	 * Add the price attribute to the variant attributes no matter what
	 */
	public function addPriceToVariants($attribute, $varValue, $strBuffer, $objProduct)
	{
		if ($attribute == 'price')
		{
			$arrType = $this->Database->execute("SELECT `variants`, `variant_attributes` FROM tl_iso_producttypes WHERE id=".(int)$objProduct->type)->fetchAssoc();
			$arrVariantAttributes = $arrType['variants'] ? deserialize($arrType['variant_attributes']) : array();
			if (!$arrType['variants'] || !in_array($attribute, $arrVariantAttributes))
			{
				return $objProduct->variants . '<div class="iso_attribute ' . $attribute . '" id="' . $objProduct->formSubmit . '_' . $attribute . '">' . $strBuffer . '</div>';
			}
		}
		return $strBuffer;
	}
	
	/**
	 * Search two dimensional array for a value and return the index of the uppermost array
	 */
	function search($array, $key, $value) {
		foreach ($array as $k => $v) {
			if ($v[$key] == $value) {
				return $k;
			}
		}
		return false;
	}
}
