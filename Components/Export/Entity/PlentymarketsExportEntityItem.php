<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */


/**
 * PlentymarketsExportEntityItem provides the actual items export functionality. Like the other export
 * entities this class is called in PlentymarketsExportController. It is important to deliver the correct
 * article model to the constructor method of this class, which is \Shopware\Models\Article\Article.
 * The data export takes place based on plentymarkets SOAP-calls.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportEntityItem
{

	/**
	 *
	 * @var \Shopware\Models\Article\Article
	 */
	protected $SHOPWARE_Article;

	/**
	 *
	 * @var integer
	 */
	protected $PLENTY_itemID;

	/**
	 *
	 * @var integer
	 */
	protected $PLENTY_priceID;

	/**
	 *
	 * @var array
	 */
	protected $storeIds = array();

	/**
	 * Constructor method
	 *
	 * @param Shopware\Models\Article\Article $Article
	 */
	public function __construct(Shopware\Models\Article\Article $Article)
	{
		$this->SHOPWARE_Article = $Article;
	}

	/**
	 * Exports the base item
	 *
	 * @throws PlentymarketsExportException
	 * @return boolean
	 */
	protected function exportItemBase()
	{
		$Item = $this->SHOPWARE_Article;

		$Request_AddItemsBase = new PlentySoapRequest_AddItemsBase();
		$Request_AddItemsBase->BaseItems = array();

		$Object_AddItemsBaseItemBase = new PlentySoapObject_AddItemsBaseItemBase();
		$ItemDetails = $Item->getMainDetail();

		if (!$ItemDetails instanceof \Shopware\Models\Article\Detail)
		{
			throw new PlentymarketsExportException('The item »' . $this->SHOPWARE_Article->getName() . '« with the id »' . $this->SHOPWARE_Article->getId() . '« could not be exported (no main detail)', 2810);
		}

		try
		{
			// Release date
			$ReleaseDate = $ItemDetails->getReleaseDate();
		}

		// May crash – when the relation is in the database but the actual data record is missing
		catch (Doctrine\ORM\EntityNotFoundException $E)
		{
			throw new PlentymarketsExportException('The item »' . $this->SHOPWARE_Article->getName() . '« with the id »' . $this->SHOPWARE_Article->getId() . '« could not be exported (missing main detail)', 2811);
		}

		// Set the release date
		if ($ReleaseDate instanceof \DateTime)
		{
			$Object_AddItemsBaseItemBase->Published = $ReleaseDate->getTimestamp();
		}

		$Object_AddItemsBaseItemBase->ExternalItemID = PlentymarketsUtils::getExternalItemID($Item->getId());
		if ($Item->getSupplier() instanceof Shopware\Models\Article\Supplier)
		{
			$Object_AddItemsBaseItemBase->ProducerID = PlentymarketsMappingController::getProducerByShopwareID($Item->getSupplier()->getId());
		}
		$Object_AddItemsBaseItemBase->EAN1 = $ItemDetails->getEan();
		$Object_AddItemsBaseItemBase->VATInternalID = PlentymarketsMappingController::getVatByShopwareID($Item->getTax()->getId());
		$Object_AddItemsBaseItemBase->ItemNo = $ItemDetails->getNumber();

		//
		$Object_AddItemsBaseItemBase->Availability = $this->getObjectAvailabiliy();
		$Object_AddItemsBaseItemBase->Texts = $this->getObjectTexts();
		$Object_AddItemsBaseItemBase->FreeTextFields = $this->getObjectFreeTextFields();
		$Object_AddItemsBaseItemBase->PriceSet = $this->getObjectPriceSet();

		//
		$Object_AddItemsBaseItemBase->Categories = array();
		$Object_AddItemsBaseItemBase->StoreIDs = array();

		/** @var Shopware\Models\Category\Category $Category */
		foreach ($Item->getCategories() as $Category)
		{
			// Get the store for this category
			$rootId = PlentymarketsUtils::getRootIdByCategory($Category);
			$shops = PlentymarketsUtils::getShopIdByCategoryRootId($rootId);
			$plentyCategoryBranchId = null;

			foreach ($shops as $shopId)
			{
				try
				{
					$plentyCategoryBranchId = PlentymarketsMappingEntityCategory::getCategoryByShopwareID($Category->getId(), $shopId);
				}
				catch (PlentymarketsMappingExceptionNotExistant $E)
				{
					PlentymarketsLogger::getInstance()->error('Export:Initial:Item', 'The category »' . $Category->getName() . '« with the id »' . $Category->getId() . '« will not be assigned to the item with the number »' . $ItemDetails->getNumber() . '«', 2820);
					continue;
				}

				try
				{
					$storeId = PlentymarketsMappingController::getShopByShopwareID($shopId);
				}
				catch (PlentymarketsMappingExceptionNotExistant $E)
				{
					continue;
				}

				if (!isset($this->storeIds[$storeId]))
				{
					// Activate the item for this store
					$Object_Integer = new PlentySoapObject_Integer();
					$Object_Integer->intValue = $storeId;
					$Object_AddItemsBaseItemBase->StoreIDs[] = $Object_Integer;

					// Cache
					$this->storeIds[$storeId] = true;
				}
			}

			if (!$plentyCategoryBranchId)
			{
				continue;
			}

			// Activate the category
			$Object_ItemCategory = new PlentySoapObject_ItemCategory();
			$Object_ItemCategory->ItemCategoryID = $plentyCategoryBranchId; // string
			$Object_AddItemsBaseItemBase->Categories[] = $Object_ItemCategory;
		}

		$Request_AddItemsBase->BaseItems[] = $Object_AddItemsBaseItemBase;

		$Response_AddItemsBase = PlentymarketsSoapClient::getInstance()->AddItemsBase($Request_AddItemsBase);

		$ResponseMessage = $Response_AddItemsBase->ResponseMessages->item[0];

		if (!$Response_AddItemsBase->Success || $ResponseMessage->Code != 100)
		{
			throw new PlentymarketsExportException('The item with the number »' . $ItemDetails->getNumber() . '« could not be exported', 2800);
		}

		foreach ($ResponseMessage->SuccessMessages->item as $SubMessage)
		{
			if ($SubMessage->Key == 'ItemID')
			{
				$this->PLENTY_itemID = (integer) $SubMessage->Value;
			}
			else if ($SubMessage->Key == 'PriceID')
			{
				$this->PLENTY_priceID = (integer) $SubMessage->Value;
			}
		}

		if ($this->PLENTY_itemID && $this->PLENTY_priceID)
		{
			PlentymarketsLogger::getInstance()->message('Export:Initial:Item', 'The item with the number »' . $ItemDetails->getNumber() . '« has been created (ItemId: ' . $this->PLENTY_itemID . ', PriceId: ' . $this->PLENTY_priceID . ')');
			PlentymarketsMappingController::addItem($Item->getId(), $this->PLENTY_itemID);
		}
		else
		{
			throw new PlentymarketsExportException('The item with the number »' . $ItemDetails->getNumber() . '« could not be exported (no item ID and price ID respectively)', 2830);
		}
	}

	/**
	 * Exports the images of the item
	 */
	protected function exportImages()
	{
		/**
		 * @var Shopware\Models\Article\Image $Image
		 * @var Shopware\Models\Media\Media $ImageMedia
		 */
		foreach ($this->SHOPWARE_Article->getImages() as $Image)
		{
			$ImageMedia = $Image->getMedia();
			if (is_null($ImageMedia))
			{
				PlentymarketsLogger::getInstance()->error('Export:Initial:Item:Image', 'The image with the id »' . $Image->getId() . '« could not be added to the item with the number »' . $this->SHOPWARE_Article->getMainDetail()->getNumber(). '« (no media associated)', 2850);
				continue;
			}

			try
			{
				$fullpath = Shopware()->DocPath() . $ImageMedia->getPath();
			}
			catch (Exception $E)
			{
				PlentymarketsLogger::getInstance()->error('Export:Initial:Item:Image', 'The image with the id »' . $Image->getId() . '« could not be added to the item with the number »' . $this->SHOPWARE_Article->getMainDetail()->getNumber() . '« (' . $E->getMessage() . ')', 2860);
				continue;
			}

			$Request_AddItemsImage = new PlentySoapRequest_AddItemsImage();

			$Object_ItemImage = new PlentySoapObject_ItemImage();
			$Object_ItemImage->Availability = 1;

			$Object_FileBase64Encoded = new PlentySoapObject_FileBase64Encoded();
			$Object_FileBase64Encoded->FileData = base64_encode(file_get_contents($fullpath)); // base64Binary
			$Object_FileBase64Encoded->FileEnding = $Image->getExtension(); // string
			$Object_FileBase64Encoded->FileName = $Image->getPath(); // string
			$Object_ItemImage->ImageData = $Object_FileBase64Encoded;
			$Object_ItemImage->Position = $Image->getPosition();

			$Request_AddItemsImage->Image = $Object_ItemImage;
			$Request_AddItemsImage->ItemID = $this->PLENTY_itemID;

			$mappings = $Image->getMappings();
			if (count($mappings))
			{
				/** @var Shopware\Models\Article\Image\Mapping $mapping */
				$mapping = $mappings->first();
				$rules = $mapping->getRules();

				if (count($rules))
				{
					/** @var Shopware\Models\Article\Image\Rule $rule */
					$rule = $rules->first();

					$option = $rule->getOption();
					$group = $option->getGroup();

					$Request_AddItemsImage->ItemAttributeID = PlentymarketsMappingController::getAttributeGroupByShopwareID($group->getId());
					$Request_AddItemsImage->ItemAttributeValueID = PlentymarketsMappingController::getAttributeOptionByShopwareID($option->getId());
				}
			}

			// Do the request
			PlentymarketsSoapClient::getInstance()->AddItemsImage($Request_AddItemsImage);
		}
	}

	/**
	 * Generates the item availability SOAP object
	 *
	 * @return PlentySoapObject_ItemAvailability
	 */
	protected function getObjectAvailabiliy()
	{
		//
		$Object_ItemAvailability = new PlentySoapObject_ItemAvailability();
		$Object_ItemAvailability->AvailableUntil = null; // int
		$Object_ItemAvailability->Inactive = (integer) !$this->SHOPWARE_Article->getActive(); // int
		$Object_ItemAvailability->IntervalSalesOrderQuantity = $this->SHOPWARE_Article->getMainDetail()->getPurchaseSteps(); // int
		$Object_ItemAvailability->MaximumSalesOrderQuantity = $this->SHOPWARE_Article->getMainDetail()->getMaxPurchase();
		$Object_ItemAvailability->MinimumSalesOrderQuantity = $this->SHOPWARE_Article->getMainDetail()->getMinPurchase();
		$Object_ItemAvailability->WebAPI = 1; // int
		$Object_ItemAvailability->Webshop = 1; // int

		return $Object_ItemAvailability;
	}

	/**
	 * Genereated the item test SOAP object
	 *
	 * @return PlentySoapObject_ItemTexts
	 */
	protected function getObjectTexts()
	{
		//
		$Object_ItemTexts = new PlentySoapObject_ItemTexts();
		$Object_ItemTexts->Keywords = PlentymarketsSoapClient::removeControlChars($this->SHOPWARE_Article->getKeywords()); // string
		$Object_ItemTexts->Lang = 'de'; // string
		$Object_ItemTexts->LongDescription = PlentymarketsSoapClient::removeControlChars($this->SHOPWARE_Article->getDescriptionLong()); // string
		$Object_ItemTexts->Name = PlentymarketsSoapClient::removeControlChars($this->SHOPWARE_Article->getName()); // string
		$Object_ItemTexts->ShortDescription = PlentymarketsSoapClient::removeControlChars($this->SHOPWARE_Article->getDescription()); // string

		return $Object_ItemTexts;
	}

	/**
	 * Generated the item free text SOAP object
	 *
	 * @return PlentySoapObject_ItemFreeTextFields
	 */
	protected function getObjectFreeTextFields()
	{
		//
		$MainDetailAttribute = $this->SHOPWARE_Article->getMainDetail()->getAttribute();

		//
		$Object_ItemFreeTextFields = new PlentySoapObject_ItemFreeTextFields();

		if (!is_null($MainDetailAttribute))
		{
			$Object_ItemFreeTextFields->Free1 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr1()); // string
			$Object_ItemFreeTextFields->Free2 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr2()); // string
			$Object_ItemFreeTextFields->Free3 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr3()); // string
			$Object_ItemFreeTextFields->Free4 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr4()); // string
			$Object_ItemFreeTextFields->Free5 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr5()); // string
			$Object_ItemFreeTextFields->Free6 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr6()); // string
			$Object_ItemFreeTextFields->Free7 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr7()); // string
			$Object_ItemFreeTextFields->Free8 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr8()); // string
			$Object_ItemFreeTextFields->Free9 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr9()); // string
			$Object_ItemFreeTextFields->Free10 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr10()); // string
			$Object_ItemFreeTextFields->Free11 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr11()); // string
			$Object_ItemFreeTextFields->Free12 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr12()); // string
			$Object_ItemFreeTextFields->Free13 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr13()); // string
			$Object_ItemFreeTextFields->Free14 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr14()); // string
			$Object_ItemFreeTextFields->Free15 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr15()); // string
			$Object_ItemFreeTextFields->Free16 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr16()); // string
			$Object_ItemFreeTextFields->Free17 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr17()); // string
			$Object_ItemFreeTextFields->Free18 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr18()); // string
			$Object_ItemFreeTextFields->Free19 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr19()); // string
			$Object_ItemFreeTextFields->Free20 = PlentymarketsSoapClient::removeControlChars($MainDetailAttribute->getAttr20()); // string
		}

		return $Object_ItemFreeTextFields;
	}

	/**
	 * Generates the item price SOAP object
	 *
	 * @return PlentySoapObject_ItemPriceSet
	 */
	protected function getObjectPriceSet()
	{
		//
		$MainDetail = $this->SHOPWARE_Article->getMainDetail();
		$Unit = $MainDetail->getUnit();
		$Tax = $this->SHOPWARE_Article->getTax();

		//
		$Object_ItemPriceSet = new PlentySoapObject_ItemPriceSet();
		$Object_ItemPriceSet->HeightInMM = $MainDetail->getHeight() * 100; // int
		$Object_ItemPriceSet->LengthInMM = $MainDetail->getLen() * 100;
		$Object_ItemPriceSet->Lot = $MainDetail->getPurchaseUnit(); // float
		$Object_ItemPriceSet->PackagingUnit = $MainDetail->getReferenceUnit();
		$Object_ItemPriceSet->PurchasePriceNet = null; // float
		$Object_ItemPriceSet->TypeOfPackage = null; // int
		$Object_ItemPriceSet->Unit1 = $MainDetail->getPackUnit();
		$Object_ItemPriceSet->WeightInGramm = $MainDetail->getWeight() * 1000;
		$Object_ItemPriceSet->WidthInMM = $MainDetail->getWidth() * 100; // int

		if ($Unit instanceof \Shopware\Models\Article\Unit && $Unit->getId() > 0)
		{
			$Object_ItemPriceSet->Unit = PlentymarketsMappingController::getMeasureUnitByShopwareID($Unit->getId()); // string
		}

		$prices = array();
		$ItemPrice = Shopware()->Models()->getRepository('Shopware\Models\Article\Price');

		/** @var Shopware\Models\Article\Price $ItemPrice */
		foreach ($ItemPrice->findBy(array(
			'customerGroupKey' => PlentymarketsConfig::getInstance()->getDefaultCustomerGroupKey(),
			'articleDetailsId' => $MainDetail->getId()
		)) as $ItemPrice)
		{
			$price = array();
			$price['to'] = $ItemPrice->getTo();
			$price['price'] = $ItemPrice->getPrice() * ($Tax->getTax() + 100) / 100;
			$price['rrp'] = $ItemPrice->getPseudoPrice() * ($Tax->getTax() + 100) / 100;
			$price['pp'] = $ItemPrice->getBasePrice();

			$prices[$ItemPrice->getFrom()] = $price;
		}

		//
		ksort($prices);

		//
		$n = 0;
		foreach ($prices as $from => $p)
		{
			// The base price
			if ($from == 1)
			{
				$Object_ItemPriceSet->RRP = $p['rrp'];
				$Object_ItemPriceSet->Price = $p['price'];
				$Object_ItemPriceSet->PurchasePriceNet = $p['pp'];
			}

			// Set PriceX values
			else
			{
				$priceIndex = 'Price' . ($n + 5);
				$Object_ItemPriceSet->$priceIndex = $p['price'];

				$rebateLevel = 'RebateLevelPrice' . ($n + 5);
				$Object_ItemPriceSet->$rebateLevel = $from;
			}
			++$n;
		}

		return $Object_ItemPriceSet;
	}

	/**
	 * Manages the export
	 *
	 * @return boolean
	 */
	public function export()
	{
		$this->exportItemBase();
		$this->exportImages();
		$this->exportVariants();
		$this->exportProperties();

		return true;
	}

	/**
	 * Exports the item properties
	 */
	protected function exportProperties()
	{
		$Request_AddPropertyToItem = new PlentySoapRequest_AddPropertyToItem();
		$Request_AddPropertyToItem->PropertyToItemList = array();

		// max 50

		$PropertyGroup = $this->SHOPWARE_Article->getPropertyGroup();

		if (!$PropertyGroup instanceof \Shopware\Models\Property\Group)
		{
			return;
		}

		/** @var Shopware\Models\Property\Value $PropertyValue */
		foreach ($this->SHOPWARE_Article->getPropertyValues() as $PropertyValue)
		{
			$PropertyOption = $PropertyValue->getOption();

			try
			{
				$PLENTY_propertyID = PlentymarketsMappingController::getPropertyByShopwareID($PropertyGroup->getId() . ';' . $PropertyOption->getId());
			}
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				PlentymarketsLogger::getInstance()->error('Export:Initial:Item:Property', 'The property »' . $PropertyGroup->getName() . ' → ' . $PropertyOption->getName() .'« could not be added to the item with the number »' . $this->SHOPWARE_Article->getMainDetail()->getNumber() . '«', 2870);
				continue;
			}

			$Object_AddPropertyToItem = new PlentySoapObject_AddPropertyToItem();
			$Object_AddPropertyToItem->ItemId = $this->PLENTY_itemID; // int
			$Object_AddPropertyToItem->Lang = 'de'; // string
			$Object_AddPropertyToItem->PropertyId = $PLENTY_propertyID; // int
			$Object_AddPropertyToItem->PropertyItemSelectionValue = null; // int
			$Object_AddPropertyToItem->PropertyItemValue = $PropertyValue->getValue(); // string
			$Request_AddPropertyToItem->PropertyToItemList[] = $Object_AddPropertyToItem;
		}

		PlentymarketsSoapClient::getInstance()->AddPropertyToItem($Request_AddPropertyToItem);
	}

	/**
	 * Exports the item variants
	 */
	protected function exportVariants()
	{
		// Verknüpfung mit den Attribut(-werten)
		$ConfiguratorSet = $this->SHOPWARE_Article->getConfiguratorSet();
		if (!$ConfiguratorSet instanceof Shopware\Models\Article\Configurator\Set)
		{
			return;
		}

		// Active the attribute values at the item --------------------------------------------------------------------
		$objectsActivateVariations = array();

		$Request_AddItemAttributeValueSets = new PlentySoapRequest_AddItemAttributeValueSets();
		$Request_AddItemAttributeValueSets->ItemID = $this->PLENTY_itemID; // int
		$Request_AddItemAttributeValueSets->ActivateVariations = array();

		/** @var Shopware\Models\Article\Configurator\Option $ConfiguratorOption */
		foreach ($ConfiguratorSet->getOptions() as $ConfiguratorOption)
		{
			$PLENTY_attributeID = PlentymarketsMappingController::getAttributeGroupByShopwareID($ConfiguratorOption->getGroup()->getId());
			$PLENTY_attributeValueID = PlentymarketsMappingController::getAttributeOptionByShopwareID($ConfiguratorOption->getId());

			$Object_AttributeVariantion = new PlentySoapObject_AttributeVariantion();
			$Object_AttributeVariantion->AttributeID = $PLENTY_attributeID; // int
			$Object_AttributeVariantion->AttributeValueID = $PLENTY_attributeValueID; // int
			$objectsActivateVariations[] = $Object_AttributeVariantion;
		}

		// Run the calls
		foreach (array_chunk($objectsActivateVariations, 100) as $activateVariations)
		{
			$Request_AddItemAttributeValueSets->ActivateVariations = $activateVariations;
			PlentymarketsSoapClient::getInstance()->AddItemAttributeValueSets($Request_AddItemAttributeValueSets);
		}

		// generate the attribute value sets --------------------------------------------------------------------------
		$objectsAddAttributeValueSets = array();
		$cacheAttributeValueSets = array();

		$Request_AddItemAttributeValueSets = new PlentySoapRequest_AddItemAttributeValueSets();
		$Request_AddItemAttributeValueSets->ItemID = $this->PLENTY_itemID; // int
		$Request_AddItemAttributeValueSets->AddAttributeValueSets = array();

		$Details = $this->SHOPWARE_Article->getDetails();

		/**
		 * @var Shopware\Models\Article\Detail $ItemVariation
		 * @var Shopware\Models\Article\Configurator\Option $ConfiguratorOption
		 */
		foreach ($Details as $ItemVariation)
		{
			$cacheAttributeValueSets[$ItemVariation->getId()] = array();

			$Object_AddItemAttributeVariationList = new PlentySoapObject_AddItemAttributeVariationList();

			foreach ($ItemVariation->getConfiguratorOptions() as $ConfiguratorOption)
			{
				$PLENTY_attributeValueID = PlentymarketsMappingController::getAttributeOptionByShopwareID($ConfiguratorOption->getId());

				$cacheAttributeValueSets[$ItemVariation->getId()][] = $PLENTY_attributeValueID;

				$Object_Integer = new PlentySoapObject_Integer();
				$Object_Integer->intValue = $PLENTY_attributeValueID;
				$Object_AddItemAttributeVariationList->AttributeValueIDs[] = $Object_Integer;
			}

			$objectsAddAttributeValueSets[] = $Object_AddItemAttributeVariationList;
		}

		foreach (array_chunk($objectsAddAttributeValueSets, 100) as $addAttributeValueSets)
		{
			// Complete the request
			$Request_AddItemAttributeValueSets->AddAttributeValueSets = $addAttributeValueSets;

			// and go for it
			$Response_AddItemAttributeValueSets = PlentymarketsSoapClient::getInstance()->AddItemAttributeValueSets($Request_AddItemAttributeValueSets);

			// Matching der Varianten
			foreach ($Response_AddItemAttributeValueSets->ResponseMessages->item as $ResponseMessage)
			{
				if ($ResponseMessage->IdentificationKey != 'AttributeValueIDs')
				{
					continue;
				}

				// If there is an error message, go ahead
				if (!is_null($ResponseMessage->ErrorMessages))
				{
					continue;
				}

				$PLENTY_attributeValueIDs = array_map('intval', explode(';', $ResponseMessage->IdentificationValue));
				$PLENTY_variantID = (integer) $ResponseMessage->SuccessMessages->item[0]->Value;

				foreach ($cacheAttributeValueSets as $SHOPWARE_variantID => $attributeValueIDs)
				{
					if (PlentymarketsUtils::arraysAreEqual($attributeValueIDs, $PLENTY_attributeValueIDs))
					{
						PlentymarketsMappingController::addItemVariant($SHOPWARE_variantID, sprintf('%s-%s-%s', $this->PLENTY_itemID, $this->PLENTY_priceID, $PLENTY_variantID));
						break;
					}
				}
			}
		}

		// Set the variation details ----------------------------------------------------------------------------------
		$objectsAttributeValueSetsDetails = array();

		// start the request
		$Request_SetAttributeValueSetsDetails = new PlentySoapRequest_SetAttributeValueSetsDetails();
		$Request_SetAttributeValueSetsDetails->AttributeValueSetsDetails = array();

		/** @var Shopware\Models\Article\Detail $ItemVariation */
		foreach ($Details as $ItemVariation)
		{
			try
			{
				$sku = PlentymarketsMappingController::getItemVariantByShopwareID($ItemVariation->getId());
			}
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				// Roll back the item
				$this->rollback();

				// and quit
				throw new PlentymarketsExportException('The item variation with the number »' . $ItemVariation->getNumber() . '« could not be created (corrupt data)', 2880);
			}

			$Object_SetAttributeValueSetsDetails = new PlentySoapObject_SetAttributeValueSetsDetails();
			$Object_SetAttributeValueSetsDetails->Availability = $ItemVariation->getActive(); // int
			$Object_SetAttributeValueSetsDetails->EAN1 = $ItemVariation->getEan(); // string
			$Object_SetAttributeValueSetsDetails->MaxStock = null; // float
			$Object_SetAttributeValueSetsDetails->PurchasePrice = null; // float
			$Object_SetAttributeValueSetsDetails->SKU = $sku;
			$Object_SetAttributeValueSetsDetails->Variantnumber = $ItemVariation->getNumber(); // string

			$objectsAttributeValueSetsDetails[] = $Object_SetAttributeValueSetsDetails;
		}

		foreach (array_chunk($objectsAttributeValueSetsDetails, 50) as $attributeValueSetsDetails)
		{
			$Request_SetAttributeValueSetsDetails->AttributeValueSetsDetails = $attributeValueSetsDetails;
			PlentymarketsSoapClient::getInstance()->SetAttributeValueSetsDetails($Request_SetAttributeValueSetsDetails);
		}
	}

	/**
	 * Rolls back the item (delete all mappings and the item in plentymarkets)
	 */
	protected function rollback()
	{
		// Delete the item in plentymarktes
		$Request_DeleteItems = new PlentySoapRequest_DeleteItems();
		$Request_DeleteItems->DeleteItems = array();

		$Object_DeleteItems = new PlentySoapObject_DeleteItems();
		$Object_DeleteItems->ItemID = $this->PLENTY_itemID;
		$Request_DeleteItems->DeleteItems[] = $Object_DeleteItems;

		PlentymarketsSoapClient::getInstance()->DeleteItems($Request_DeleteItems);
		PlentymarketsLogger::getInstance()->message('Export:Initial:Item', 'The item with the id »' . $this->PLENTY_itemID . '« has been deleted in plentymarkets');

		// Delete the mapping for the main item
		PlentymarketsMappingController::deleteItemByShopwareID($this->SHOPWARE_Article->getId());

		// And for the details
		foreach ($this->SHOPWARE_Article->getDetails() as $ItemVariation)
		{
			PlentymarketsMappingController::deleteItemVariantByShopwareID($ItemVariation->getId());
		}
	}
}
