<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011 Claus Due <claus@wildside.dk>, Wildside A/S
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 *****************************************************************/

/**
 * @package Flux
 * @subpackage Backend
 */
class Tx_Flux_Backend_TceMain {

	/**
	 * @var Tx_Extbase_Object_ObjectManager
	 */
	protected $objectManager;

	/**
	 * @var Tx_Flux_Service_FlexForm
	 */
	protected $flexFormService;

	/**
	 * @var Tx_Extbase_Reflection_Service
	 */
	protected $reflectionService;

	/**
	 * @var Tx_Flux_Service_Content
	 */
	protected $contentService;

	/**
	 * CONSTRUCTOR
	 */
	public function __construct() {
		$this->objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
		$this->flexFormService = $this->objectManager->get('Tx_Flux_Service_FlexForm');
		$this->reflectionService = $this->objectManager->get('Tx_Extbase_Reflection_Service');
		$this->contentService = $this->objectManager->get('Tx_Flux_Service_Content');
	}

	/**
	 * @param string $command The TCEmain operation status, fx. 'update'
	 * @param string $table The table TCEmain is currently processing
	 * @param string $id The records id (if any)
	 * @param array $relativeTo Filled if command is relative to another element
	 * @param object $reference Reference to the parent object (TCEmain)
	 * @return void
	 */
	public function processCmdmap_preProcess(&$command, $table, $id, &$relativeTo, t3lib_TCEmain &$reference) {
		$data = array();
		if ($table === 'tt_content') {
			switch ($command) {
				case 'move':
					if (strpos($relativeTo, 'FLUX') !== FALSE) {
							// Triggers when CE is dropped on a nested content area's header dropzone (EXT:gridelements)
						list ($areaName, $parentElementUid, $pid) = explode('-', trim($relativeTo, '-'));
						$data['tx_flux_column'] = $areaName . ':' . $parentElementUid;
						$data['tx_flux_parent'] = $parentElementUid;
						$data['sorting'] = -1;
						$relativeTo = $pid;
					} elseif (strpos($relativeTo, 'x') > 0) {
							// Triggers when CE is dropped on a root column header's dropzone (EXT:gridelements)
						list ($relativeTo, $data['colPos']) = explode('x', $relativeTo);
						$data['tx_flux_column'] = $data['tx_flux_parent'] = '';
					} elseif ($relativeTo < 0) {
							// Triggers when sorting a CE after another CE, $relativeTo is negative value of CE's UID
						$data['tx_flux_column'] = $this->contentService->getFlexibleContentElementArea(array('pid' => $relativeTo));
						$data['tx_flux_parent'] = empty($data['tx_flux_column']) === FALSE ? array_pop(explode(':', $data['tx_flux_column'])) : NULL;
					} else {
							// Triggers only if sorting/pasting to a "raw" page. Note: also triggers when manually
							// sorting elements to the top position of a nested content area, in which case we preserve
							// the current tx_flux_column value.
						$data = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('tx_flux_column, colPos, pid', $table, "uid = '" . $id . "'");
						$data['sorting'] = -1;
						if ($data['pid'] != $relativeTo) {
								// Move outside FCE only if pasting to another page, which should be save because:
								// - you cannot paste to a particular column (paste triggers move cmd)
								// - you still need to select the "column" of the CE to move it to another column
								// - when D&D'ed to another column, colPos is handled earlier in this condition structure
							$data['tx_flux_column'] = '';
						}
					}
					if ($data['tx_flux_column'] != '') {
						$data['colPos'] = -42;
							// TODO: after migration to "parent" usage, remember to change this next line
						$data['tx_flux_parent'] = array_pop(explode(':', $data['tx_flux_column']));
					}
					if (count($data) > 0) {
						$GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, "uid = '" . $id . "'", $data);
					}
					break;
				default:
			}
		}
	}

	/**
	 * @param string $command The TCEmain operation status, fx. 'update'
	 * @param string $table The table TCEmain is currently processing
	 * @param string $id The records id (if any)
	 * @param array $relativeTo Filled if command is relative to another element
	 * @param object $reference Reference to the parent object (TCEmain)
	 * @return void
	 */
	public function processCmdmap_postProcess(&$command, $table, $id, &$relativeTo, t3lib_TCEmain &$reference) {
	}

	/**
	 * @param array $incomingFieldArray The original field names and their values before they are processed
	 * @param string $table The table TCEmain is currently processing
	 * @param string $id The records id (if any)
	 * @param t3lib_TCEmain $reference Reference to the parent object (TCEmain)
	 * @return void
	 */
	public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, $table, $id, t3lib_TCEmain &$reference) {
		if ($table === 'tt_content' && $id) {
			if (is_array($incomingFieldArray['pi_flexform']['data'])) {
				foreach ((array) $incomingFieldArray['pi_flexform']['data']['options']['lDEF'] as $key=>$value) {
					if (strpos($key, 'tt_content') === 0) {
						$realKey = array_pop(explode('.', $key));
						if (isset($incomingFieldArray[$realKey])) {
							$incomingFieldArray[$realKey] = $value['vDEF'];
						}
					}
				}
			}
			$area = $this->contentService->getFlexibleContentElementArea($incomingFieldArray, $id);
			$incomingFieldArray['tx_flux_column'] = $area;
			if ($area) {
				$incomingFieldArray['colPos'] = -42;
				$currentParent = array_pop(explode(':', $area));
				if ($incomingFieldArray['tx_flux_parent'] > 0 && $incomingFieldArray['tx_flux_parent'] != $currentParent) {
						// Means that TCA cascading has performed an action that cascaded,
						// changing the parent. Set the Flux area accordingly.
					$incomingFieldArray['tx_flux_column'] = array_shift(explode(':', $area)) . ':' . $incomingFieldArray['tx_flux_parent'];
				} else {
					$incomingFieldArray['tx_flux_parent'] = array_pop(explode(':', $area));
				}
			}
		}
	}

	/**
	 * @param string $status The TCEmain operation status, fx. 'update'
	 * @param string $table The table TCEmain is currently processing
	 * @param string $id The records id (if any)
	 * @param array $fieldArray The field names and their values to be processed
	 * @param object $reference Reference to the parent object (TCEmain)
	 * @return void
	 */
	public function processDatamap_postProcessFieldArray($status, $table, $id, &$fieldArray, t3lib_TCEmain &$reference) {
		if ($table === 'tt_content') {
			$area = $this->contentService->getFlexibleContentElementArea($fieldArray, $id);
			if ($area) {
				$fieldArray['colPos'] = -42;
				if ($fieldArray['tx_flux_parent'] > 0) {
					$fieldArray['tx_flux_column'] = array_shift(explode(':', $area)) . ':' . $fieldArray['tx_flux_parent'];
				}
			} else {
				$fieldArray['tx_flux_parent'] = NULL;
			}
		}
	}

	/**
	 * @param string $status The command which has been sent to processDatamap
	 * @param string $table	The table we're dealing with
	 * @param mixed $id Either the record UID or a string if a new record has been created
	 * @param array $fieldArray The record row how it has been inserted into the database
	 * @param object $reference A reference to the TCEmain instance
	 * @return void
	 */
	public function processDatamap_afterDatabaseOperations($status, $table, $id, &$fieldArray, t3lib_TCEmain &$reference) {
		if ($table == 'tt_content') {
			if ($status === 'new' && $fieldArray['tx_flux_parent'] > 0) {
				$area = $fieldArray['tx_flux_column'];
				$currentParent = array_pop(explode(':', $area));
				if ($fieldArray['tx_flux_parent'] > 0 && $fieldArray['tx_flux_parent'] != $currentParent) {
						// Means that TCA cascading has performed an action that cascaded, changing the parent. Set the Flux area accordingly.
					$fieldArray['tx_flux_column'] = array_shift(explode(':', $area)) . ':' . $fieldArray['tx_flux_parent'];
				} else {
						// Means area is defined but for some reason, parent is not. Set the parent to correct this problem.
					$fieldArray['tx_flux_parent'] = array_pop(explode(':', $area));
				}
			} elseif ($status === 'new') {
				$newUid = $reference->substNEWwithIDs[$id];
				$oldUid = $fieldArray['t3_origuid'];
				$languageFieldName = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
				$newLanguageUid = NULL;
				if ($oldUid) {
					$oldRecord = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid,' . $languageFieldName, $table, "uid = '" . $oldUid . "'");
					if (isset($oldRecord[$languageFieldName])) {
						$newLanguageUid = $fieldArray[$languageFieldName];
					}
				}
				if ($newLanguageUid === NULL) {
					return;
				}
				$fieldArray[$languageFieldName] = $newLanguageUid;

					// Perform localization on all children, since this is not handled by the TCA field which otherwise cascades changes
				$children = $this->contentService->getChildContentElementUids($oldUid);
				if (count($children) < 1) {
					return;
				}
				foreach ($children as $child) {
					if ($fieldArray['pid'] !== $child['pid']) {
							// Note: if we are moving content to a new pid or copying a page, do not perform manual localization nor child record copying
						continue;
					}
					$areaAndUid = explode(':', $child['tx_flux_column']);
					$areaAndUid[1] = $newUid; // re-assign parent UID
					$overrideValues = array(
						'tx_flux_column' => implode(':', $areaAndUid),
						'tx_flux_parent' => $newUid,
						$languageFieldName => $newLanguageUid
					);
					if (NULL !== $newLanguageUid) {
						$childUid = $reference->localize($table, $child['uid'], $newLanguageUid);
					} else {
						$childUid = $reference->copyRecord($table, $child['uid'], $fieldArray['pid']);
					}
					$GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, "uid = '" . $childUid . "'", $overrideValues);
				}
			}
		}
	}

}
?>