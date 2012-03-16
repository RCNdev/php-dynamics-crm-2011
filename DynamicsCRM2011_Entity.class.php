<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Entity extends DynamicsCRM2011 {
	/**
	 * Overridden in each child class; this is how Dynamics refers to this Entity
	 * @var String entityLogicalName
	 */
	protected $entityLogicalName = NULL;
	/* The details of the Entity structure (SimpleXML object) */
	protected $entityData;
	/* The Properties of the Entity */
	protected $properties = Array();
	protected $mandatories = Array();
	/* The ID of the Entity */
	private $entityID;
	
	/**
	 * 
	 * @param DynamicsCRM2011Connector $conn Connection to the Dynamics CRM server - should be active already.
	 * @param String $_logicalName Allows constructing arbritrary Entities by setting the EntityLogicalName directly
	 */
	function __construct(DynamicsCRM2011_Connector $conn, $_logicalName = NULL) {
		/* If a new LogicalName was passed, set it in this Entity */
		if ($_logicalName != NULL) {
			/* If this value was already set, don't allow changing it. */
			/* - otherwise, you could have a DynamicsCRM2011_Incident that was actually an Account! */
			if ($this->entityLogicalName != NULL) {
				throw new Exception('Cannot override the Entity Logical Name on a strongly typed Entity');
			}
			/* Set the Logical Name */
			$this->entityLogicalName = $_logicalName;
		}
		/* Check we have a Logical Name for the Entity */
		if ($this->entityLogicalName == NULL) {
			throw new Execption('Cannot instantiate an abstract Entity - specify the Logical Name');
		}
		/* Check if the Definition of this Entity is Cached on the Connector */
		if ($conn->isEntityDefinitionCached($this->entityLogicalName)) {
			/* Use the Cached values */
			$isDefined = $conn->getCachedEntityDefinition($this->entityLogicalName, 
					$this->entityData, $this->properties, $this->mandatories);
			if ($isDefined) return;	
		}
		
		/* At this point, we assume Entity is not Cached */
		/* So, get the full details of what an Incident is on this server */
		$this->entityData = $conn->retrieveEntity($this->entityLogicalName);
		
		/* Next, we analyse this data and determine what Properties this Entity has */
		foreach ($this->entityData->children('http://schemas.microsoft.com/xrm/2011/Metadata')->Attributes[0]->AttributeMetadata as $attribute) {
			/* Determine the Type of the Attribute */
			$attributeList = $attribute->attributes('http://www.w3.org/2001/XMLSchema-instance');
			$attributeType = self::stripNS($attributeList['type']);
			/* Handle the special case of Lookup types */
			$isLookup = ($attributeType == 'LookupAttributeMetadata');
			/* If it's a Lookup, check what Targets are allowed */
			if ($isLookup) {
				$lookupTypes = Array();
				foreach ($attribute->Targets->children('http://schemas.microsoft.com/2003/10/Serialization/Arrays') as $target) {
					$lookupTypes[] = (String)$target;
				}
			} else {
				$lookupTypes = NULL;
			}
			/* Check if this field is mandatory */
			$requiredLevel = (String)$attribute->RequiredLevel->children('http://schemas.microsoft.com/xrm/2011/Contracts')->Value;
			/* Add this property to the Object's Property array */
			$this->properties[strtolower((String)$attribute->LogicalName)] = Array(
					'Label' => (String)$attribute->DisplayName->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label,
					'Type'  => (String)$attribute->AttributeType,
					'isLookup' => $isLookup,
					'lookupTypes' => $lookupTypes,
					'Create' => ((String)$attribute->IsValidForCreate === 'true'),
					'Update' => ((String)$attribute->IsValidForUpdate === 'true'),
					'Read'   => ((String)$attribute->IsValidForRead === 'true'),
					'RequiredLevel' => $requiredLevel,
					'AttributeOf' => (String)$attribute->AttributeOf,
					'Value'  => NULL,
					'Changed' => false,
				);
			/* If appropriate, add this to the Mandatory Field list */
			if ($requiredLevel != 'None' && $requiredLevel != 'Recommended') {
				$this->mandatories[strtolower((String)$attribute->LogicalName)] = $requiredLevel;
			}
		}
		
		/* Finally, ensure that this Entity Definition is Cached for next time */
		$conn->setCachedEntityDefinition($this->entityLogicalName, 
				$this->entityData, $this->properties, $this->mandatories);
		return;
	}
	
	/**
	 * 
	 * @param String $property to be fetched
	 * @return value of the property, if it exists & is readable
	 */
	public function __get($property) {
		/* Handle special fields */
		switch ($property) {
			case 'ID':
				return $this->getID();
		}
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Only return the value if it exists & is readable */
		if (array_key_exists($property, $this->properties) && $this->properties[$property]['Read'] === true) {
			return $this->properties[$property]['Value'];
		}
		/* Property is not readable, but does exist - different error message! */
		if (array_key_exists($property, $this->properties)) {
			trigger_error('Property '.$property.' of the '.$this->entityLogicalName.' entity is not Readable', E_USER_NOTICE);
			return NULL;
		}
		/* Property doesn't exist - standard error */
		$trace = debug_backtrace();
		trigger_error('Undefined property via __get(): ' . $property 
				. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
				E_USER_NOTICE);
		return NULL;
	}
	
	/**
	 * 
	 * @param String $property to be changed
	 * @param mixed $value new value for the property
	 */
	public function __set($property, $value) {
		/* Handle special fields */
		switch ($property) {
			case 'ID':
				$this->setID($value);
				return;
		}
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->properties)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set(): ' . $property 
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		/* Check that this property can be set in Creation or Update */
		if ($this->properties[$property]['Create'] == false && $this->properties[$property]['Update'] == false) {
			trigger_error('Property '.$property.' of the '.$this->entityLogicalName
					.' entity cannot be set', E_USER_NOTICE);
			return;
		}
		/* If this is a Lookup field, it MUST be set to an Entity of an appropriate type */
		if ($this->properties[$property]['isLookup']) {
			/* Check the new value is an Entity */
			if (!$value instanceOf self) {
				trigger_error('Property '.$property.' of the '.$this->entityLogicalName
						.' entity must be a '.get_class(), E_USER_ERROR);
				return;
			}
			/* Check the new value is the right type of Entity */
			if (!in_array($value->entityLogicalName, $this->properties[$property]['lookupTypes'])) {
				trigger_error('Property '.$property.' of the '.$this->entityLogicalName
						.' entity must be a '.implode(' or ', $this->properties[$property]['lookupTypes']), E_USER_ERROR);
				return;
			}
		} 
		/* Update the property value with whatever value was passed */
		$this->properties[$property]['Value'] = $value;
		/* Mark the property as changed */
		$this->properties[$property]['Changed'] = true;
	}
	
	/**
	 * @return String description of the Entity including Type and ID
	 */
	public function __toString() {
		return $this->entityLogicalName.'<'.$this->getID().'>';
	}
	
	/**
	 * Reset all changed values to unchanged
	 */
	public function reset() {
		/* Loop through all the properties */
		foreach ($this->properties as &$property) {
			$property['Changed'] = false;
		}
	}
	
	/**
	 * Check if a property has been changed since creation of the Entity
	 * @param String $property
	 * @return boolean
	 */
	public function isChanged($property) {
		/* Dynamic properties are all stored in lowercase */
		$property = strtolower($property);
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->properties)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set(): ' . $property
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		return $this->properties[$property]['Changed'];
	}
	
	/**
	 * Private utility function to get the ID field; enforces NULL --> EmptyGUID
	 * @ignore
	 */
	private function getID() {
		if ($this->entityID == NULL) return self::EmptyGUID;
		else return $this->entityID;
	}
	
	/**
	 * Private utility function to set the ID field; enforces "Set Once" logic
	 * @param String $value
	 * @throws Exception if the ID is already set
	 */
	private function setID($value) {
		/* Only allow setting the ID once */
		if ($this->entityID != NULL) {
			throw new Exception('Cannot change the ID of an Entity');
		}
		$this->entityID = $value;
	}
	
	/**
	 * Utility function to check all mandatory fields are filled
	 * @param Array $details populated with any failures found
	 * @return boolean true if all mandatories are filled
	 */
	public function checkMandatories(Array &$details = NULL) {
		/* Assume true, until proved false */
		$allMandatoriesFilled = true;
		$missingFields = Array();
		/* Loop through all the Mandatory fields */
		foreach ($this->mandatories as $property => $reason) {
			/* If this is an attribute of another property, check that property instead */
			if ($this->properties[$property]['AttributeOf'] != NULL) {
				/* Check the other property */
				$propertyToCheck = $this->properties[$property]['AttributeOf'];
			} else {
				/* Check this property */
				$propertyToCheck = $property;
			}
			if ($this->properties[$propertyToCheck]['Value'] == NULL) {
				/* Ignore values that can't be in Create or Update */
				if ($this->properties[$propertyToCheck]['Create'] || $this->properties[$propertyToCheck]['Update']) {
					$missingFields[$propertyToCheck] = $reason;
					$allMandatoriesFilled = false;
				}
			}
		}
		/* If not all Mandatories were filled, and we have been given a Details array, populate it */
		if (is_array($details) && $allMandatoriesFilled == false) {
			$details += $missingFields;
		}
		/* Return the result */
		return $allMandatoriesFilled;
	}
	
	/**
	 * Create a DOMNode that represents this Entity, and can be used in a Create or Update 
	 * request to the CRM server
	 * 
	 * @param boolean $allFields indicates if we should include all fields, or only changed fields
	 */
	public function getEntityDOM($allFields = false) {
		/* Generate the Entity XML */
		$entityDOM = new DOMDocument();
		$entityNode = $entityDOM->appendChild($entityDOM->createElement('entity'));
		$entityNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:i', 'http://www.w3.org/2001/XMLSchema-instance');
		$attributeNode = $entityNode->appendChild($entityDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:Attributes'));
		$attributeNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Loop through all the attributes of this Entity */
		foreach ($this->properties as $property => $propertyDetails) {
			/* Only include changed properties */
			if ($propertyDetails['Changed']) {
				/* Create a Key/Value Pair of String/Any Type */
				$propertyNode = $attributeNode->appendChild($entityDOM->createElement('b:KeyValuePairOfstringanyType'));
				/* Set the Property Name */
				$propertyNode->appendChild($entityDOM->createElement('c:key', $property));
				/* Check the Type of the Value */
				if ($propertyDetails['isLookup']) {
					/* Special handling for Lookups - use an EntityReference, not the AttributeType */
					$valueNode = $propertyNode->appendChild($entityDOM->createElement('c:value'));
					$valueNode->setAttribute('i:type', 'b:EntityReference');
					$valueNode->appendChild($entityDOM->createElement('b:Id', $propertyDetails['Value']->ID));
					$valueNode->appendChild($entityDOM->createElement('b:LogicalName', $propertyDetails['Value']->entityLogicalName));
					$valueNode->appendChild($entityDOM->createElement('b:Name'))->setAttribute('i:nil', 'true');
				} else {
					/* Normal handling for all other types */
					$valueNode = $propertyNode->appendChild($entityDOM->createElement('c:value', $propertyDetails['Value']));
					/* Set the Type of the Value */
					$valueNode->setAttribute('i:type', 'd:'.strtolower($propertyDetails['Type']));
					$valueNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://www.w3.org/2001/XMLSchema');				
				}
			}
		}
		/* Entity State */
		$entityNode->appendChild($entityDOM->createElement('b:EntityState'))->setAttribute('i:nil', 'true');
		/* Formatted Values */
		$formattedValuesNode = $entityNode->appendChild($entityDOM->createElement('b:FormattedValues'));
		$formattedValuesNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Entity ID */
		$entityNode->appendChild($entityDOM->createElement('b:Id', $this->getID()));
		/* Logical Name */
		$entityNode->appendChild($entityDOM->createElement('b:LogicalName', $this->entityLogicalName));
		/* Related Entities */
		$relatedEntitiesNode = $entityNode->appendChild($entityDOM->createElement('b:RelatedEntities'));
		$relatedEntitiesNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Return the root node for the Entity */
		return $entityNode;
	}
}

?>