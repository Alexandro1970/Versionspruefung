
<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/myFunctions.php';  // globale Funktionen

define("DEVELOPMENT", false);

// Modul Prefix
if (!defined('MODUL_PREFIX'))
{
	define("MODUL_PREFIX", "SOLVIS");
}

// Offset von Register (erster Wert 1) zu Adresse (erster Wert 0) ist -1
if (!defined('MODBUS_REGISTER_TO_ADDRESS_OFFSET'))
{
	define("MODBUS_REGISTER_TO_ADDRESS_OFFSET", 0);
}

// ArrayOffsets
if (!defined('IMR_START_REGISTER'))
{
	define("IMR_START_REGISTER", 0);
//	define("IMR_END_REGISTER", 3);
//	define("IMR_SIZE", 1);
//	define("IMR_RW", 1);
	define("IMR_FUNCTION_CODE", 1);
	define("IMR_NAME", 2);
	define("IMR_DESCRIPTION", 3);
	define("IMR_TYPE", 4);
	define("IMR_UNITS", 5);
	define("IMR_SF", 6);
}

	class Solvis extends IPSModule
	{
		use myFunctions;

		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// *** Properties ***
			$this->RegisterPropertyBoolean('active', 'true');
			$this->RegisterPropertyString('hostIp', '');
			$this->RegisterPropertyInteger('hostPort', '502');
			$this->RegisterPropertyInteger('hostmodbusDevice', '101');
			$this->RegisterPropertyInteger('pollCycle', '60');
			$this->RegisterPropertyBoolean('loggingTemp', 'false');
			$this->RegisterPropertyBoolean('loggingAusgang', 'false');
			$this->RegisterPropertyBoolean('loggingSonstiges', 'false');

			// cyclic update of calculated values
			$this->RegisterTimer("cyclicDataUpdate", 0, MODUL_PREFIX."_CyclicDataUpdate(".$this->InstanceID.");");

			// *** Erstelle Variablen-Profile ***
			$this->checkProfiles();
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function GetConfigurationForm()
		{
			$formElements = array();
			$formElements[] = array(
				'type' => "Label",
				'label' => "Die Solvis Heizung muss Modbus TCP unterstützen!",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "Im Konfigurationsmenü der Solvis Heizung muss im SolvisControl-Menü „Installateur=>Sonstiges=>Remote bzw. „Installateur=>Sonstiges=>Modbus“ der Modus Modbus TCP aktiviert werden.",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => " ",
			);
			$formElements[] = array(
				'type' => "CheckBox",
				'caption' => "Open",
				'name' => "active",
			);
			$formElements[] = array(
				'type' => "ValidationTextBox",
				'caption' => "IP",
				'name' => "hostIp",
				'validate' => "^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$",
			);
			$formElements[] = array(
				'type' => "NumberSpinner",
				'caption' => "Port (Standard: 502)",
				'name' => "hostPort",
				'digits' => 0,
				'minimum' => 1,
				'maximum' => 65535,
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "Geräte ID der Solvis Heizung",
			);
			$formElements[] = array(
				'type' => "NumberSpinner",
				'caption' => "Geräte ID (Standard: 101)",
				'name' => "hostmodbusDevice",
				'digits' => 0,
				'minimum' => 1,
				'maximum' => 255,
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => " ",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "In welchem Zeitintervall sollen die Modbus-Werte abgefragt werden (Empfehlung: 10 Sekunden)?",
			);
			$formElements[] = array(
				'type' => "NumberSpinner",
				'caption' => "Abfrage-Intervall (in Sekunden)",
				'name' => "pollCycle",
				'minimum' => 1,
				'maximum' => 3600,
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "Achtung: Die Berechnung der Wirkarbeit (Wh/kWh) wird exakter, je kleiner der Abfarge-Intervall gewählt wird.\nABER: Je kleiner der Abfrage-Intervall, um so höher die Systemlast und auch die Archiv-Größe bei aktiviertem Logging!",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => " ",
			);
			$formElements[] = array(
				'type' => "Label",
				'label' => "Sollen Werte von Variablen im Archiv gelogged werden?",
			);
			$formElements[] = array(
				'type' => "CheckBox",
				'caption' => "Temperatur (S01 - S16)",
				'name' => "loggingTemp",
			);
			$formElements[] = array(
				'type' => "CheckBox",
				'caption' => "Ausgänge (A01 - A14)",
				'name' => "loggingAusgang",
			);
			$formElements[] = array(
				'type' => "CheckBox",
				'caption' => "Sonstiges (Brennerstarts, Brennerstufe,...)",
				'name' => "loggingSonstiges",
			);

			$formActions = array();

			$formStatus = array();
			$formStatus[] = array(
				'code' => IS_IPPORTERROR,
				'icon' => "error",
				'caption' => "IP oder Port sind nicht erreichtbar",
			);
			$formStatus[] = array(
				'code' => IS_NOARCHIVE,
				'icon' => "error",
				'caption' => "Archiv nicht gefunden",
			);
			return json_encode(array('elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus));
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();

			//Properties
			$active = $this->ReadPropertyBoolean('active');
			$hostIp = $this->ReadPropertyString('hostIp');
			$hostPort = $this->ReadPropertyInteger('hostPort');
			$hostmodbusDevice = $this->ReadPropertyInteger('hostmodbusDevice');
			$hostSwapWords = 0; // Solvis = false
			$pollCycle = $this->ReadPropertyInteger('pollCycle') * 1000;
			$loggingTemp = $this->ReadPropertyBoolean('loggingTemp');
			$loggingAusgang = $this->ReadPropertyBoolean('loggingAusgang');
			$loggingSonstiges = $this->ReadPropertyBoolean('loggingSonstiges');

			// activate Timer
			$this->SetTimerInterval("cyclicDataUpdate", 5000);

			$categoryArray = array(
				"Allg" => array("Name" => "Allgemeines", 'Position' => 1),
				"Anal" => array("Name" => "Analog In/Out", 'Position' => 2),
				"A" => array("Name" => "A01-A14", 'Position' => 3),
				"S" => array("Name" => "S01-S18", 'Position' => 4),
				"Meld" => array("Name" => "Meldungen", 'Position' => 5),
			);

			$archiveId = $this->getArchiveId();
			if (false === $archiveId)
			{
				// no archive found
				$this->SetStatus(IS_NOARCHIVE);
			}

			// Workaround für "InstanceInterface not available" Fehlermeldung beim Server-Start...
			if (KR_READY != IPS_GetKernelRunlevel())
			{
				// --> do nothing
			}
			// IP-Adresse nicht konfiguriert
			elseif ("" == $hostIp)
			{
				// keine IP --> inaktiv
				$this->SetStatus(IS_INACTIVE);

				$this->SendDebug("Module-Status", "ERROR: ".MODUL_PREFIX." IP not set!", 0);
			}
			// Instanzen nur mit Konfigurierter IP erstellen
			else
			{
				$this->checkProfiles();
				list($gatewayId_Old, $interfaceId_Old) = $this->readOldModbusGateway();
				list($gatewayId, $interfaceId) = $this->checkModbusGateway($hostIp, $hostPort, $hostmodbusDevice, $hostSwapWords);

				$parentId = $this->InstanceID;

				// Kategorien erstellen
				foreach ($categoryArray as $ident => $category)
				{
					$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($ident), $parentId);
					if (false === $categoryId)
					{
						$categoryId = IPS_CreateCategory();
						IPS_SetIdent($categoryId, $this->removeInvalidChars($ident));
						IPS_SetName($categoryId, $category['Name']);
						IPS_SetParent($categoryId, $parentId);
						if (isset($category['Position']))
						{
							IPS_SetPosition($categoryId, $category['Position']);
						}
						if (isset($category['Description']))
						{
							IPS_SetInfo($categoryId, $category['Description']);
						}
					}
				}

				/* ****** Solvis Register ************************************************************************** */
				$modelRegister_array = array(
					array(2049, "R", "Zirkulation Betriebsart", "Zirkulation: 1 - Aus 2 - Puls 3 - Temp 4 - Warten", "int16", "enumerated_Zirkulation"),
				);
				$categoryIdent = "Allg";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				// Logging setzen
				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					if (false !== $varId && false !== $archiveId)
					{
						AC_SetLoggingStatus($archiveId, $varId, $loggingSonstiges);
					}
				}


				$modelRegister_array = array(
					array(3840, "R", "Analog Out 1", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
					array(3845, "R", "Analog Out 2", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
					array(3850, "R", "Analog Out 3", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
					array(3855, "R", "Analog Out 4", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
					array(3860, "R", "Analog Out 5", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
					array(3865, "R", "Analog Out 6", "Betriebsart: Status,0 - Auto PWM 1 - Hand PWM 2 - Auto analog 3 - Hand analog", "int16", "enumerated_Betriebsart"),
				);
				$categoryIdent = "Anal";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					if (substr(IPS_GetName($varId), 0, 12) != substr($modelRegister[IMR_NAME], 0, 12))
					{
						IPS_SetName($varId, substr($modelRegister[IMR_NAME], 0, 12)." ".IPS_GetName($varId));
					}
				}


				$modelRegister_array = array(
					array(32768, "R", "Unix Timestamp high", "", "int16"/*,"secs"*/), // ToDo: Umrechnungsformel unbekannt...
					array(32769, "R", "Unix Timestamp low", "", "int16"/*,"secs"*/), // ToDo: Umrechnungsformel unbekannt...
					array(32770, "R", "Version SC2/SC3", "", "uint16", ""),
					array(32771, "R", "Version NBG", "", "uint16", ""),
				);
				$categoryIdent = "Allg";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}


				// Temperaturwerte S1 - S16 (division durch 10 nötig!!!)
				$modelRegister_array = array(
					array(33024, "R", "S01 Speicher oben", "", "int16"/*, "°C"*/),
					array(33025, "R", "S02 Warmwasser", "", "int16"/*, "°C"*/),
					array(33026, "R", "S03 Speicherreferenz", "", "int16"/*, "°C"*/),
					array(33027, "R", "S04 Heizungspuffer oben", "", "int16"/*, "°C"*/),
					array(33028, "R", "S05 Solarvorlauf", "", "int16"/*, "°C"*/),
					array(33029, "R", "S06 Solarrücklauf", "", "int16"/*, "°C"*/),
					array(33031, "R", "S08 Solarkollektor", "", "int16"/*, "°C"*/),
					array(33032, "R", "S09 Heizungspuffer unten", "", "int16"/*, "°C"*/),
					array(33033, "R", "S10 Aussentemperatur", "", "int16"/*, "°C"*/),	// ToDo: Sind uint16 für °C korrekt? Müsste es nicht int16 sein?
					array(33034, "R", "S11 Zirkulation", "", "int16"/*, "°C"*/),
					array(33035, "R", "S12 Vorlauf Heizkreis 1", "", "int16"/*, "°C"*/),
					array(33036, "R", "S13 Vorlauf Heizkreis 2", "", "int16"/*, "°C"*/),
					array(33037, "R", "S14 Vorlauf Heizkreis 3", "", "int16"/*, "°C"*/),
					array(33038, "R", "S15 Kaltwasser", "", "int16"/*, "°C"*/),
					array(33039, "R", "S16 unbenannt", "", "int16"/*, "°C"*/),
				);
				$categoryIdent = "S";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					IPS_SetVariableCustomProfile($varId, "");
					IPS_SetHidden($varId, true);

					$dataType = 7;
					$profile = $this->getProfile("°C"/*$modelRegister[IMR_UNITS]*/, $dataType);
					$varId = $this->MaintainInstanceVariable("Value_SF", substr($modelRegister[IMR_NAME], 0, 3)." ".IPS_GetName($varId), VARIABLETYPE_FLOAT, $profile, 0, true, $instanceId, $modelRegister[IMR_DESCRIPTION]);

					// Logging setzen
					if (false !== $varId && false !== $archiveId)
					{
						AC_SetLoggingStatus($archiveId, $varId, $loggingTemp);
					}

					$profile = MODUL_PREFIX.".TempFehler.Int";
					$varId = $this->MaintainInstanceVariable("status", substr($modelRegister[IMR_NAME], 0, 3)." Status", VARIABLETYPE_INTEGER, $profile, 0, true, $instanceId, "0 = OK, 1 = Kurzschlussfehler, 2 = Unterbrechungsfehler"/*$modelRegister[IMR_DESCRIPTION]*/);
					if (substr(IPS_GetName($varId), 0, 3) != substr($modelRegister[IMR_NAME], 0, 3))
					{
						IPS_SetName($varId, substr($modelRegister[IMR_NAME], 0, 3)." ".IPS_GetName($varId));
					}
				}


				$modelRegister_array = array(
					array(33030, "R", "S07 Solardruck", "", "int16", ""), // ToDo: Einheit mbar oder bar?
					array(33040, "R", "S17 Volumenstrom WW", "", "int16", "l/min"),
					array(33041, "R", "S18 Volumenstrom Solar", "", "int16", "l/min"),
					//					array(33045, "R", "DigIn Störungen", "", "",""), // ToDo: Datentyp
				);
				$categoryIdent = "S";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}


				$modelRegister_array = array(
					array(33042, "R", "Analog In 1", "", "int16"/*, "V"*/),
					array(33043, "R", "Analog In 2", "", "int16"/*, "V"*/),
					array(33044, "R", "Analog In 3", "", "int16"/*, "V"*/),
				);
				$categoryIdent = "Anal";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					IPS_SetVariableCustomProfile($varId, "");
					IPS_SetHidden($varId, true);

					$dataType = 7;
					$profile = $this->getProfile("V"/*$modelRegister[IMR_UNITS]*/, $dataType);
					$varId = $this->MaintainInstanceVariable("Value_SF", IPS_GetName($instanceId)." ".IPS_GetName($varId), VARIABLETYPE_FLOAT, $profile, 0, true, $instanceId, $modelRegister[IMR_DESCRIPTION]);
				}


				$modelRegister_array = array(
					array(33280, "R", "A01 Pumpe Zirkulation", "", "int8", "%"),
					array(33281, "R", "A02 Pumpe Solar", "", "int8", "%"),
					array(33282, "R", "A03 Pumpe Heizkreis 1", "", "int8", "%"),
					array(33283, "R", "A04 Pumpe Heizkreis 2", "", "int8", "%"),
					array(33284, "R", "A05 Pumpe Heizkreis 3", "", "int8", "%"),
					array(33285, "R", "A06", "", "int8", "%"),
					array(33286, "R", "A07", "", "int8", "%"),
					array(33287, "R", "A08 Mischer HK1 auf", "", "int8", "%"),
					array(33288, "R", "A09 Mischer HK1 zu", "", "int8", "%"),
					array(33289, "R", "A10 Mischer HK2 auf", "", "int8", "%"),
					array(33290, "R", "A11 Mischer HK2 zu", "", "int8", "%"),
					array(33291, "R", "A12 Brenner", "", "int8", "%"),
					array(33292, "R", "A13 Brenner Stufe 2", "", "int8", "%"),
					array(33293, "R", "A14 Wärmepumpe", "", "int8", "%"),
				);
				$categoryIdent = "A";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				// Logging setzen
				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					if (false !== $varId && false !== $archiveId)
					{
						AC_SetLoggingStatus($archiveId, $varId, $loggingAusgang);
					}
				}

				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					if (substr(IPS_GetName($varId), 0, 3) != substr($modelRegister[IMR_NAME], 0, 3))
					{
						IPS_SetName($varId, substr($modelRegister[IMR_NAME], 0, 3)." ".IPS_GetName($varId));
					}

					$profile = "~Switch";
					$varId = $this->MaintainInstanceVariable("aktiv", substr($modelRegister[IMR_NAME], 0, 3)." aktiv", VARIABLETYPE_BOOLEAN, $profile, 0, true, $instanceId, "false = nicht aktiv, true = aktiv"/*$modelRegister[IMR_DESCRIPTION]*/);
					if (substr(IPS_GetName($varId), 0, 3) != substr($modelRegister[IMR_NAME], 0, 3))
					{
						IPS_SetName($varId, substr($modelRegister[IMR_NAME], 0, 3)." ".IPS_GetName($varId));
					}
				}


				$modelRegister_array = array(
					array(33294, "R", "Analog Out O1", "", "int16", "", "V"),
					array(33295, "R", "Analog Out O2", "", "int16", "", "V"),
					array(33296, "R", "Analog Out O3", "", "int16", "", "V"),
					array(33297, "R", "Analog Out O4 WP Umwälzpumpe", "", "int16", "", "%"),
					array(33298, "R", "Analog Out O5", "", "int16", "", "V"),
					array(33299, "R", "Analog Out O6", "", "int16", "", "V"),
				);
				$categoryIdent = "Anal";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					IPS_SetVariableCustomProfile($varId, "");
					IPS_SetHidden($varId, true);

					$dataType = 7;
					$profile = $this->getProfile($modelRegister[(IMR_UNITS + 1)], $dataType);
					$varId = $this->MaintainInstanceVariable("Value_SF", IPS_GetName($instanceId)." ".IPS_GetName($varId), VARIABLETYPE_FLOAT, $profile, 0, true, $instanceId, $modelRegister[IMR_DESCRIPTION]);
				}


				$modelRegister_array = array(
					array(33536, "R", "Laufzeit Brennerstufe 1", "", "int16", "h"),	// ToDo: Einheit wirklich in ganze Stunden?
					array(33537, "R", "Brennerstarts Stufe 1", "", "uint16", ""),
					array(33538, "R", "Laufzeit Brennerstufe 2", "", "int16", "h"),	// ToDo: Einheit wirklich in ganze Stunden?
					array(33539, "R", "Wärmeerzeuger SX aktuelle Leistung", "", "int16", "W"),
					array(33540, "R", "Ionisationsstrom mA", "", "int16", "mA"),
				);
				$categoryIdent = "Allg";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}

				// Logging setzen
				foreach ($modelRegister_array as $modelRegister)
				{
					$instanceId = IPS_GetObjectIDByIdent($modelRegister[IMR_START_REGISTER], $categoryId);
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);
					if (false !== $varId && false !== $archiveId)
					{
						AC_SetLoggingStatus($archiveId, $varId, $loggingSonstiges);
					}
				}

				$modelRegister_array = array(
					array(33792, "R", "Meldungen Anzahl", "", "int16", ""),
					array(33793, "R", "Meldung 01 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33794, "R", "Meldung 01 UnixZeit H", "", "int16"/*,"secs"*/), // ToDo: Umrechnung unbekannt...
					array(33795, "R", "Meldung 01 UnixZeit L", "", "int16"/*,"secs"*/), // ToDo: Umrechnung unbekannt...
					array(33796, "R", "Meldung 01 Par 1", "", "int16", ""),
					array(33797, "R", "Meldung 01 Par 2", "", "int16", ""),
					array(33798, "R", "Meldung 02 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33799, "R", "Meldung 02 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33800, "R", "Meldung 02 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33801, "R", "Meldung 02 Par 1", "", "int16", ""),
					array(33802, "R", "Meldung 02 Par 2", "", "int16", ""),
					array(33803, "R", "Meldung 03 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33804, "R", "Meldung 03 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33805, "R", "Meldung 03 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33806, "R", "Meldung 03 Par 1", "", "int16", ""),
					array(33807, "R", "Meldung 03 Par 2", "", "int16", ""),
					array(33808, "R", "Meldung 04 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33809, "R", "Meldung 04 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33810, "R", "Meldung 04 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33811, "R", "Meldung 04 Par 1", "", "int16", ""),
					array(33812, "R", "Meldung 04 Par 2", "", "int16", ""),
					array(33813, "R", "Meldung 05 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33814, "R", "Meldung 05 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33815, "R", "Meldung 05 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33816, "R", "Meldung 05 Par 1", "", "int16", ""),
					array(33817, "R", "Meldung 05 Par 2", "", "int16", ""),
					array(33818, "R", "Meldung 06 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33819, "R", "Meldung 06 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33820, "R", "Meldung 06 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33821, "R", "Meldung 06 Par 1", "", "int16", ""),
					array(33822, "R", "Meldung 06 Par 2", "", "int16", ""),
					array(33823, "R", "Meldung 07 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33824, "R", "Meldung 07 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33825, "R", "Meldung 07 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33826, "R", "Meldung 07 Par 1", "", "int16", ""),
					array(33827, "R", "Meldung 07 Par 2", "", "int16", ""),
					array(33828, "R", "Meldung 08 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33829, "R", "Meldung 08 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33830, "R", "Meldung 08 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33831, "R", "Meldung 08 Par 1", "", "int16", ""),
					array(33832, "R", "Meldung 08 Par 2", "", "int16", ""),
					array(33833, "R", "Meldung 09 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33834, "R", "Meldung 09 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33835, "R", "Meldung 09 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33836, "R", "Meldung 09 Par 1", "", "int16", ""),
					array(33837, "R", "Meldung 09 Par 2", "", "int16", ""),
					array(33838, "R", "Meldung 10 Code", "", "int16", "enumerated_StatsHeizkreis"),
					array(33839, "R", "Meldung 10 UnixZeit H", "", "int16"/*,"secs"*/),
					array(33840, "R", "Meldung 10 UnixZeit L", "", "int16"/*,"secs"*/),
					array(33841, "R", "Meldung 10 Par 1", "", "int16", ""),
					array(33842, "R", "Meldung 10 Par 2", "", "int16", ""),
				);
				$categoryIdent = "Meld";
				$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
				if (false !== $categoryId)
				{
					$this->createModbusInstances($modelRegister_array, $categoryId, $gatewayId, $pollCycle);
				}
				else
				{
					$this->SendDebug("create instances", "ERROR: category \"".$categoryIdent."\" not found!", 0);
				}


				if ($active)
				{
					// Erreichbarkeit von IP und Port pruefen
					$portOpen = false;
					$waitTimeoutInSeconds = 1;
					// ACHTUNG: Die Solvis Heizung antwortet nicht auf den Port-Check per fsockopen!!!
					if (Sys_Ping($hostIp, $waitTimeoutInSeconds * 1000) /*$fp = @fsockopen($hostIp, $hostPort, $errCode, $errStr, $waitTimeoutInSeconds)*/)
					{
						// It worked
						$portOpen = true;

						// Client Socket aktivieren
						if (false == IPS_GetProperty($interfaceId, "Open"))
						{
							IPS_SetProperty($interfaceId, "Open", true);
							IPS_ApplyChanges($interfaceId);
							//IPS_Sleep(100);

							$this->SendDebug("ClientSocket-Status", "ClientSocket activated (".$interfaceId.")", 0);
						}

						// aktiv
						$this->SetStatus(IS_ACTIVE);

						$this->SendDebug("Module-Status", MODUL_PREFIX."-module activated", 0);
					}
					else
					{
						// IP oder Port nicht erreichbar
						$this->SetStatus(IS_IPPORTERROR);

						$this->SendDebug("Module-Status", "ERROR: ".MODUL_PREFIX." with IP=".$hostIp." and Port=".$hostPort." cannot be reached!", 0);
					}

					// Close fsockopen
					if (isset($fp) && false !== $fp)
					{
						fclose($fp); // nötig für fsockopen!
					}
				}
				else
				{
					// Client Soket deaktivieren
					if (true == IPS_GetProperty($interfaceId, "Open"))
					{
						IPS_SetProperty($interfaceId, "Open", false);
						IPS_ApplyChanges($interfaceId);
						//IPS_Sleep(100);

						$this->SendDebug("ClientSocket-Status", "ClientSocket deactivated (".$interfaceId.")", 0);
					}

					// Timer deaktivieren
					/*
									$this->SetTimerInterval("Update-Autarkie-Eigenverbrauch", 0);
									$this->SetTimerInterval("Update-EMS-Status", 0);
									$this->SetTimerInterval("Update-WallBox_X_CTRL", 0);
									$this->SetTimerInterval("Update-ValuesKw", 0);
									$this->SetTimerInterval("Wh-Berechnung", 0);
									$this->SetTimerInterval("HistoryCleanUp", 0);
					 */
					// inaktiv
					$this->SetStatus(IS_INACTIVE);

					$this->SendDebug("Module-Status", MODUL_PREFIX."-module deactivated", 0);
				}


				// pruefen, ob sich ModBus-Gateway geaendert hat
				if (0 != $gatewayId_Old && $gatewayId != $gatewayId_Old)
				{
					$this->deleteInstanceNotInUse($gatewayId_Old, MODBUS_ADDRESSES);

					$this->SendDebug("ModbusGateway-Status", "ModbusGateway deleted (".$gatewayId_Old.")", 0);
				}

				// pruefen, ob sich ClientSocket Interface geaendert hat
				if (0 != $interfaceId_Old && $interfaceId != $interfaceId_Old)
				{
					$this->deleteInstanceNotInUse($interfaceId_Old, MODBUS_INSTANCES);

					$this->SendDebug("ClientSocket-Status", "ClientSocket deleted (".$interfaceId_Old.")", 0);
				}
			}
		}

		public function CyclicDataUpdate()
		{
			$parentId = $this->InstanceID;

			// S01 - S18: Temperature values - SF Variables
			$modelRegister_array = array(33024, 33025, 33026, 33027, 33028, 33029, 33031, 33032, 33033, 33034, 33035, 33036, 33037, 33038, 33039);
			$categoryIdent = "S";
			$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
			foreach($modelRegister_array AS $modelRegister)
			{
				$instanceId = @IPS_GetObjectIDByIdent($modelRegister, $categoryId);
				$targetId = @IPS_GetObjectIDByIdent("Value_SF", $instanceId);
				$targetStatusId = @IPS_GetObjectIDByIdent("status", $instanceId);
				if(false !== $instanceId && false !== $targetId)
				{
					$sourceValue = GetValue(IPS_GetObjectIDByIdent("Value", $instanceId));
					$sfValue = -1;
					$newValue = $sourceValue * pow(10, $sfValue);

					if(-300 == $sourceValue)
					{
						$newStatusValue = 1;
					}
					else if(2200 == $sourceValue)
					{
						$newStatusValue = 2;
					}
					else
					{
						$newStatusValue = 0;
					}

					if(GetValue($targetId) != $newValue)
					{
						SetValue($targetId, $newValue);
					}

					if(GetValue($targetStatusId) != $newStatusValue)
					{
						SetValue($targetStatusId, $newStatusValue);
					}
				}
			}

			// Analog IN/Out: V values - SF Variables
			$modelRegister_array = array(33042, 33043, 33044, 33294, 33295, 33296, 33297, 33298, 33299);
			$categoryIdent = "Anal";
			$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
			foreach($modelRegister_array AS $modelRegister)
			{
				$instanceId = @IPS_GetObjectIDByIdent($modelRegister, $categoryId);
				$targetId = @IPS_GetObjectIDByIdent("Value_SF", $instanceId);
				if(false !== $instanceId && false !== $targetId)
				{
					$sourceValue = GetValue(IPS_GetObjectIDByIdent("Value", $instanceId));
					$sfValue = -1;
					$newValue = $sourceValue * pow(10, $sfValue);

					if(GetValue($targetId) != $newValue)
					{
						SetValue($targetId, $newValue);
					}
				}
			}

			// A0-A14 values - SF Variables
			$modelRegister_array = array(33280, 33281, 33282, 33283, 33284, 33285, 33286, 33287, 33288, 33289, 33290, 33291, 33292, 33293);
			$categoryIdent = "A";
			$categoryId = @IPS_GetObjectIDByIdent($this->removeInvalidChars($categoryIdent), $parentId);
			foreach($modelRegister_array AS $modelRegister)
			{
				$instanceId = @IPS_GetObjectIDByIdent($modelRegister, $categoryId);
				$targetId = @IPS_GetObjectIDByIdent("aktiv", $instanceId);
				if(false !== $instanceId && false !== $targetId)
				{
					$sourceValue = GetValue(IPS_GetObjectIDByIdent("Value", $instanceId));
					$newValue = ($sourceValue > 0);

					if(GetValue($targetId) != $newValue)
					{
						SetValue($targetId, $newValue);
					}
				}
			}
		}

		private function createModbusInstances($modelRegister_array, $parentId, $gatewayId, $pollCycle, $uniqueIdent = "")
		{
			// Workaround für "InstanceInterface not available" Fehlermeldung beim Server-Start...
			if (KR_READY == IPS_GetKernelRunlevel())
			{
				// Erstelle Modbus Instancen
				foreach ($modelRegister_array as $inverterModelRegister)
				{
					// get datatype
					$datenTyp = $this->getModbusDatatype($inverterModelRegister[IMR_TYPE]);
					if ("continue" == $datenTyp)
					{
						continue;
					}

					// if scale factor is given, variable will be of type float
					if (isset($inverterModelRegister[IMR_SF]) && 10000 >= $inverterModelRegister[IMR_SF])
					{
						$varDataType = MODBUSDATATYPE_REAL;
					}
					else
					{
						$varDataType = $datenTyp;
					}

					// get profile
					if (isset($inverterModelRegister[IMR_UNITS]))
					{
						$profile = $this->getProfile($inverterModelRegister[IMR_UNITS], $varDataType);
					}
					else
					{
						$profile = false;
					}

					$instanceId = @IPS_GetObjectIDByIdent($inverterModelRegister[IMR_START_REGISTER].$uniqueIdent, $parentId);
					$initialCreation = false;

					// Modbus-Instanz erstellen, sofern noch nicht vorhanden
					if (false === $instanceId)
					{
						$this->SendDebug("create Modbus address", "REG_".$inverterModelRegister[IMR_START_REGISTER]." - ".$inverterModelRegister[IMR_NAME]." (modbusDataType=".$datenTyp.", varDataType=".$varDataType.", profile=".$profile.")", 0);

						$instanceId = IPS_CreateInstance(MODBUS_ADDRESSES);

						IPS_SetParent($instanceId, $parentId);
						IPS_SetIdent($instanceId, $inverterModelRegister[IMR_START_REGISTER].$uniqueIdent);
						IPS_SetName($instanceId, $inverterModelRegister[IMR_NAME]);
						IPS_SetInfo($instanceId, $inverterModelRegister[IMR_DESCRIPTION]);

						$initialCreation = true;
					}

					// Gateway setzen
					if (IPS_GetInstance($instanceId)['ConnectionID'] != $gatewayId)
					{
						$this->SendDebug("set Modbus Gateway", "REG_".$inverterModelRegister[IMR_START_REGISTER]." - ".$inverterModelRegister[IMR_NAME]." --> GatewayID ".$gatewayId, 0);

						// sofern bereits eine Gateway verbunden ist, dieses trennen
						if (0 != IPS_GetInstance($instanceId)['ConnectionID'])
						{
							IPS_DisconnectInstance($instanceId);
						}

						// neues Gateway verbinden
						IPS_ConnectInstance($instanceId, $gatewayId);
					}


					// ************************
					// config Modbus-Instance
					// ************************
					// set data type
					if ($datenTyp != IPS_GetProperty($instanceId, "DataType"))
					{
						IPS_SetProperty($instanceId, "DataType", $datenTyp);
					}
					// set emulation state
					if (false != IPS_GetProperty($instanceId, "EmulateStatus"))
					{
						IPS_SetProperty($instanceId, "EmulateStatus", false);
					}
					// set poll cycle
					if ($pollCycle != IPS_GetProperty($instanceId, "Poller"))
					{
						IPS_SetProperty($instanceId, "Poller", $pollCycle);
					}
					// set length for modbus datatype string
					if (MODBUSDATATYPE_STRING == $datenTyp && $inverterModelRegister[IMR_SIZE] != IPS_GetProperty($instanceId, "Length"))
					{ // if string --> set length accordingly
						IPS_SetProperty($instanceId, "Length", $inverterModelRegister[IMR_SIZE]);
					}
					/*					// set scale factor
										if (isset($inverterModelRegister[IMR_SF]) && 10000 >= $inverterModelRegister[IMR_SF] && $inverterModelRegister[IMR_SF] != IPS_GetProperty($instanceId, "Factor"))
										{
											IPS_SetProperty($instanceId, "Factor", $inverterModelRegister[IMR_SF]);
										}
					 */

					// Read-Settings
					if ($inverterModelRegister[IMR_START_REGISTER] + MODBUS_REGISTER_TO_ADDRESS_OFFSET != IPS_GetProperty($instanceId, "ReadAddress"))
					{
						IPS_SetProperty($instanceId, "ReadAddress", $inverterModelRegister[IMR_START_REGISTER] + MODBUS_REGISTER_TO_ADDRESS_OFFSET);
					}
					if (6 == $inverterModelRegister[IMR_FUNCTION_CODE])
					{
						$ReadFunctionCode = 3;
					}
					elseif ("R" == $inverterModelRegister[IMR_FUNCTION_CODE])
					{
						$ReadFunctionCode = 3;
					}
					elseif ("RW" == $inverterModelRegister[IMR_FUNCTION_CODE])
					{
						$ReadFunctionCode = 3;
					}
					else
					{
						$ReadFunctionCode = $inverterModelRegister[IMR_FUNCTION_CODE];
					}

					if ($ReadFunctionCode != IPS_GetProperty($instanceId, "ReadFunctionCode"))
					{
						IPS_SetProperty($instanceId, "ReadFunctionCode", $ReadFunctionCode);
					}

					// Write-Settings
					if (4 < $inverterModelRegister[IMR_FUNCTION_CODE] && $inverterModelRegister[IMR_FUNCTION_CODE] != IPS_GetProperty($instanceId, "WriteFunctionCode"))
					{
						IPS_SetProperty($instanceId, "WriteFunctionCode", $inverterModelRegister[IMR_FUNCTION_CODE]);
					}

					if (4 < $inverterModelRegister[IMR_FUNCTION_CODE] && $inverterModelRegister[IMR_START_REGISTER] + MODBUS_REGISTER_TO_ADDRESS_OFFSET != IPS_GetProperty($instanceId, "WriteAddress"))
					{
						IPS_SetProperty($instanceId, "WriteAddress", $inverterModelRegister[IMR_START_REGISTER] + MODBUS_REGISTER_TO_ADDRESS_OFFSET);
					}

					if (0 != IPS_GetProperty($instanceId, "WriteFunctionCode"))
					{
						IPS_SetProperty($instanceId, "WriteFunctionCode", 0);
					}

					if (IPS_HasChanges($instanceId))
					{
						IPS_ApplyChanges($instanceId);
					}

					// Statusvariable der Modbus-Instanz ermitteln
					$varId = IPS_GetObjectIDByIdent("Value", $instanceId);

					// Profil der Statusvariable initial einmal zuweisen
					if (false != $profile && !IPS_VariableProfileExists($profile))
					{
						$this->SendDebug("Variable-Profile", "Profile ".$profile." does not exist!", 0);
					}
					elseif ($initialCreation && false != $profile)
					{
						// Justification Rule 11: es ist die Funktion RegisterVariable...() in diesem Fall nicht nutzbar, da die Variable durch die Modbus-Instanz bereits erstellt wurde
						// --> Custo Profil wird initial einmal beim Instanz-erstellen gesetzt
						if (!IPS_SetVariableCustomProfile($varId, $profile))
						{
							$this->SendDebug("Variable-Profile", "Error setting profile ".$profile." for VarID ".$varId."!", 0);
						}
					}
				}
			}
		}

		private function getModbusDatatype(string $type)//PHP8 :mixed
		{
			// Datentyp ermitteln
			// 0=Bit (1 bit)
			// 1=Byte (8 bit unsigned)
			if ("uint8" == strtolower($type)
				|| "enum8" == strtolower($type)
			) {
				$datenTyp = MODBUSDATATYPE_BIT;
			}
			// 2=Word (16 bit unsigned)
			elseif ("uint16" == strtolower($type)
				|| "enum16" == strtolower($type)
				|| "uint8+uint8" == strtolower($type)
			) {
				$datenTyp = MODBUSDATATYPE_WORD;
			}
			// 3=DWord (32 bit unsigned)
			elseif ("uint32" == strtolower($type)
				|| "acc32" == strtolower($type)
				|| "acc64" == strtolower($type)
			) {
				$datenTyp = MODBUSDATATYPE_DWORD;
			}
			// 4=Char / ShortInt (8 bit signed)
			elseif ("sunssf" == strtolower($type)
			|| "int8" == strtolower($type)
			) {
				$datenTyp = MODBUSDATATYPE_CHAR;
			}
			// 5=Short / SmallInt (16 bit signed)
			elseif ("int16" == strtolower($type))
			{
				$datenTyp = MODBUSDATATYPE_SHORT;
			}
			// 6=Integer (32 bit signed)
			elseif ("int32" == strtolower($type))
			{
				$datenTyp = MODBUSDATATYPE_INT;
			}
			// 7=Real (32 bit signed)
			elseif ("float32" == strtolower($type))
			{
				$datenTyp = MODBUSDATATYPE_REAL;
			}
			// 8=Int64
			elseif ("uint64" == strtolower($type))
			{
				$datenTyp = MODBUSDATATYPE_INT64;
			}
			/* 9=Real64 (32 bit signed)
			elseif ("???" == strtolower($type))
			{
				$datenTyp = MODBUSDATATYPE_REAL64;
			}*/
			// 10=String
			elseif ("string32" == strtolower($type)
				|| "string16" == strtolower($type)
				|| "string8" == strtolower($type)
				|| "string" == strtolower($type)
			) {
				$datenTyp = MODBUSDATATYPE_STRING;
			}
			else
			{
				$this->SendDebug("getModbusDatatype()", "Unbekannter Datentyp '".$type."'! --> skip", 0);

				return "continue";
			}

			return $datenTyp;
		}

		private function getProfile(string $unit, int $datenTyp = -1)//PHP8 :mixed
		{
			// Profil ermitteln
			if ("a" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = "~Ampere";
			}
			elseif ("a" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Ampere.Int";
			}
			elseif ("ma" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".MilliAmpere.Int";
			}
			elseif (("ah" == strtolower($unit)
					|| "vah" == strtolower($unit))
				&& MODBUSDATATYPE_REAL == $datenTyp
			) {
				$profile = MODUL_PREFIX.".AmpereHour.Float";
			}
			elseif ("ah" == strtolower($unit)
				|| "vah" == strtolower($unit)
			) {
				$profile = MODUL_PREFIX.".AmpereHour.Int";
			}
			elseif ("v" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = "~Volt";
			}
			elseif ("v" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Volt.Int";
			}
			elseif ("w" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = "~Watt.14490";
			}
			elseif ("w" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Watt.Int";
			}
			elseif ("h" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Hours.Int";
			}
			elseif ("hz" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = "~Hertz";
			}
			elseif ("hz" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Hertz.Int";
			}
			elseif ("l/min" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Volumenstrom.Int";
			}
			// Voltampere fuer elektrische Scheinleistung
			elseif ("va" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = MODUL_PREFIX.".Scheinleistung.Float";
			}
			// Voltampere fuer elektrische Scheinleistung
			elseif ("va" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Scheinleistung.Int";
			}
			// Var fuer elektrische Blindleistung
			elseif ("var" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = MODUL_PREFIX.".Blindleistung.Float";
			}
			// Var fuer elektrische Blindleistung
			elseif ("var" == strtolower($unit) || "var" == $unit)
			{
				$profile = MODUL_PREFIX.".Blindleistung.Int";
			}
			elseif ("%" == $unit && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = "~Valve.F";
			}
			elseif ("%" == $unit)
			{
				$profile = "~Valve";
			}
			elseif ("wh" == strtolower($unit) && (MODBUSDATATYPE_REAL == $datenTyp || MODBUSDATATYPE_INT64 == $datenTyp))
			{
				$profile = MODUL_PREFIX.".Electricity.Float";
			}
			elseif ("wh" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Electricity.Int";
			}
			elseif ((
				"° C" == $unit
					|| "°C" == $unit
					|| "C" == $unit
			) && MODBUSDATATYPE_REAL == $datenTyp
			) {
				$profile = "~Temperature";
			}
			elseif ("° C" == $unit
				|| "°C" == $unit
				|| "C" == $unit
			) {
				$profile = MODUL_PREFIX.".Temperature.Int";
			}
			elseif ("cos()" == strtolower($unit) && MODBUSDATATYPE_REAL == $datenTyp)
			{
				$profile = MODUL_PREFIX.".Angle.Float";
			}
			elseif ("cos()" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Angle.Int";
			}
			elseif ("ohm" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Ohm.Int";
			}
			elseif ("enumerated_id" == strtolower($unit))
			{
				$profile = "SunSpec.ID.Int";
			}
			elseif ("enumerated_chast" == strtolower($unit))
			{
				$profile = "SunSpec.ChaSt.Int";
			}
			elseif ("enumerated_st" == strtolower($unit))
			{
				$profile = "SunSpec.StateCodes.Int";
			}
			elseif ("enumerated_stvnd" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".StateCodes.Int";
			}
			elseif ("enumerated_zirkulation" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Zirkulation.Int";
			}
			elseif ("enumerated_betriebsart" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Betriebsart.Int";
			}
			elseif ("enumerated_statsheizkreis" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".StatsHeizkreis.Int";
			}
			elseif ("enumerated_emergency-power" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Emergency-Power.Int";
			}
			elseif ("enumerated_powermeter" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".Powermeter.Int";
			}
			elseif ("enumerated_sg-ready-status" == strtolower($unit))
			{
				$profile = MODUL_PREFIX.".SG-Ready-Status.Int";
			}
			elseif ("secs" == strtolower($unit))
			{
				$profile = "~UnixTimestamp";
			}
			elseif ("registers" == strtolower($unit)
				|| "bitfield" == strtolower($unit)
				|| "bitfield16" == strtolower($unit)
				|| "bitfield32" == strtolower($unit)
			) {
				$profile = false;
			}
			else
			{
				$profile = false;
				if ("" != $unit)
				{
					$this->SendDebug("getProfile()", "ERROR: Profil '".$unit."' unbekannt!", 0);
				}
			}

			return $profile;
		}


		private function checkProfiles()
		{
			$deleteProfiles_array = array();

			$this->createVarProfile(
				MODUL_PREFIX.".TempFehler.Int",
				VARIABLETYPE_INTEGER,
				'',
				0,
				2,
				1,
				0,
				0,
				array(
					array('Name' => "OK", 'Wert' => 0, "OK", 'Farbe' => $this->getRgbColor("green")),
					array('Name' => "Kurzschluss", 'Wert' => 1, "Kurzschlussfehler", 'Farbe' => $this->getRgbColor("red")),
					array('Name' => "Unterbrechung", 'Wert' => 2, "Unterbrechungsfehler", 'Farbe' => $this->getRgbColor("red")),
				)
			);

			$this->createVarProfile(
				MODUL_PREFIX.".Betriebsart.Int",
				VARIABLETYPE_INTEGER,
				'',
				0,
				0,
				0,
				0,
				0,
				array(
					array('Name' => "Auto PWM", 'Wert' => 0, "Auto PWM", 'Farbe' => $this->getRgbColor("green")),
					array('Name' => "Hand PWM", 'Wert' => 1, "Hand PWM", 'Farbe' => $this->getRgbColor("yellow")),
					array('Name' => "Auto analog", 'Wert' => 2, "Auto analog", 'Farbe' => $this->getRgbColor("green")),
					array('Name' => "Hand analog", 'Wert' => 3, "Hand analog", 'Farbe' => $this->getRgbColor("yellow")),
					array('Name' => "FEHLER", 'Wert' => 255, "FEHLER", 'Farbe' => $this->getRgbColor("red")),
				)
			);

			$this->createVarProfile(
				MODUL_PREFIX.".StatsHeizkreis.Int",
				VARIABLETYPE_INTEGER,
				'',
				0,
				0,
				0,
				0,
				0,
				array(
					array('Name' => "Aus", 'Wert' => 1, "Aus"),
					array('Name' => "Automatik", 'Wert' => 2, "Automatik"),
					array('Name' => "Tagbetrieb", 'Wert' => 3, "Tagbetrieb"),
					array('Name' => "Absenkbetrieb", 'Wert' => 4, "Absenkbetrieb"),
					array('Name' => "Standby", 'Wert' => 5, "Standby"),
					array('Name' => "Eco", 'Wert' => 6, "Eco"),
					array('Name' => "Urlaub", 'Wert' => 7, "Urlaub"),
					array('Name' => "WW Vorrang", 'Wert' => 8, "WW Vorrang"),
					array('Name' => "Frostschutz", 'Wert' => 9, "Frostschutz"),
					array('Name' => "Pumpenschutz", 'Wert' => 10, "Pumpenschutz"),
					array('Name' => "Estrich", 'Wert' => 11, "Estrich"),
					array('Name' => "FEHLER", 'Wert' => 255, "FEHLER", 'Farbe' => $this->getRgbColor("red")),
				)
			);

			$this->createVarProfile(
				MODUL_PREFIX.".Zirkulation.Int",
				VARIABLETYPE_INTEGER,
				'',
				0,
				0,
				0,
				0,
				0,
				array(
					array('Name' => "Aus", 'Wert' => 1, "Aus"),
					array('Name' => "Puls", 'Wert' => 2, "Puls"),
					array('Name' => "Temp", 'Wert' => 3, "Temp"),
					array('Name' => "Warten", 'Wert' => 4, "Warten"),
					array('Name' => "FEHLER", 'Wert' => 255, "FEHLER", 'Farbe' => $this->getRgbColor("red")),
				)
			);
			/*
						$this->createVarProfile("SunSpec.ChaSt.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
								array('Name' => "N/A", 'Wert' => 0, "Unbekannter Status"),
								array('Name' => "OFF", 'Wert' => 1, "OFF: Energiespeicher nicht verfügbar"),
								array('Name' => "EMPTY", 'Wert' => 2, "EMPTY: Energiespeicher vollständig entladen"),
								array('Name' => "DISCHAGING", 'Wert' => 3, "DISCHARGING: Energiespeicher wird entladen"),
								array('Name' => "CHARGING", 'Wert' => 4, "CHARGING: Energiespeicher wird geladen"),
								array('Name' => "FULL", 'Wert' => 5, "FULL: Energiespeicher vollständig geladen"),
								array('Name' => "HOLDING", 'Wert' => 6, "HOLDING: Energiespeicher wird weder geladen noch entladen"),
								array('Name' => "TESTING", 'Wert' => 7, "TESTING: Energiespeicher wird getestet"),
							)
						);
						$this->createVarProfile("SunSpec.ID.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
								array('Name' => "single phase Inv (i)", 'Wert' => 101, "101: single phase Inverter (int)"),
								array('Name' => "split phase Inv (i)", 'Wert' => 102, "102: split phase Inverter (int)"),
								array('Name' => "three phase Inv (i)", 'Wert' => 103, "103: three phase Inverter (int)"),
								array('Name' => "single phase Inv (f)", 'Wert' => 111, "111: single phase Inverter (float)"),
								array('Name' => "split phase Inv (f)", 'Wert' => 112, "112: split phase Inverter (float)"),
								array('Name' => "three phase Inv (f)", 'Wert' => 113, "113: three phase Inverter (float)"),
								array('Name' => "single phase Meter (i)", 'Wert' => 201, "201: single phase Meter (int)"),
								array('Name' => "split phase Meter (i)", 'Wert' => 202, "202: split phase (int)"),
								array('Name' => "three phase Meter (i)", 'Wert' => 203, "203: three phase (int)"),
								array('Name' => "single phase Meter (f)", 'Wert' => 211, "211: single phase Meter (float)"),
								array('Name' => "split phase Meter (f)", 'Wert' => 212, "212: split phase Meter (float)"),
								array('Name' => "three phase Meter (f)", 'Wert' => 213, "213: three phase Meter (float)"),
								array('Name' => "string combiner (i)", 'Wert' => 403, "403: String Combiner (int)"),
							)
						);
						$this->createVarProfile("SunSpec.StateCodes.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
								array('Name' => "N/A", 'Wert' => 0, "Unbekannter Status"),
								array('Name' => "OFF", 'Wert' => 1, "Wechselrichter ist aus"),
								array('Name' => "SLEEPING", 'Wert' => 2, "Auto-Shutdown"),
								array('Name' => "STARTING", 'Wert' => 3, "Wechselrichter startet"),
								array('Name' => "MPPT", 'Wert' => 4, "Wechselrichter arbeitet normal", 'Farbe' => $this->getRgbColor("green")),
								array('Name' => "THROTTLED", 'Wert' => 5, "Leistungsreduktion aktiv", 'Farbe' => $this->getRgbColor("orange")),
								array('Name' => "SHUTTING_DOWN", 'Wert' => 6, "Wechselrichter schaltet ab"),
								array('Name' => "FAULT", 'Wert' => 7, "Ein oder mehr Fehler existieren, siehe St *oder Evt * Register", 'Farbe' => $this->getRgbColor("red")),
								array('Name' => "STANDBY", 'Wert' => 8, "Standby"),
							)
						);
						$this->createVarProfile(MODUL_PREFIX.".StateCodes.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
								array('Name' => "N/A", 'Wert' => 0, "Unbekannter Status"),
								array('Name' => "OFF", 'Wert' => 1, "Wechselrichter ist aus"),
								array('Name' => "SLEEPING", 'Wert' => 2, "Auto-Shutdown"),
								array('Name' => "STARTING", 'Wert' => 3, "Wechselrichter startet"),
								array('Name' => "MPPT", 'Wert' => 4, "Wechselrichter arbeitet normal", 'Farbe' => $this->getRgbColor("green")),
								array('Name' => "THROTTLED", 'Wert' => 5, "Leistungsreduktion aktiv", 'Farbe' => $this->getRgbColor("orange")),
								array('Name' => "SHUTTING_DOWN", 'Wert' => 6, "Wechselrichter schaltet ab"),
								array('Name' => "FAULT", 'Wert' => 7, "Ein oder mehr Fehler existieren, siehe St * oder Evt * Register", 'Farbe' => $this->getRgbColor("red")),
								array('Name' => "STANDBY", 'Wert' => 8, "Standby"),
								array('Name' => "NO_BUSINIT", 'Wert' => 9, "Keine SolarNet Kommunikation"),
								array('Name' => "NO_COMM_INV", 'Wert' => 10, "Keine Kommunikation mit Wechselrichter möglich"),
								array('Name' => "SN_OVERCURRENT", 'Wert' => 11, "Überstrom an SolarNet Stecker erkannt"),
								array('Name' => "BOOTLOAD", 'Wert' => 12, "Wechselrichter wird gerade upgedatet"),
								array('Name' => "AFCI", 'Wert' => 13, "AFCI Event (Arc-Erkennung)"),
							)
						);
						$this->createVarProfile(MODUL_PREFIX.".Emergency-Power.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
								array('Name' => "nicht unterstützt", 'Wert' => 0, "Notstrom wird nicht von Ihrem Gerät unterstützt", 'Farbe' => 16753920),
								array('Name' => "aktiv", 'Wert' => 1, "Notstrom aktiv (Ausfall des Stromnetzes)", 'Farbe' => $this->getRgbColor("green")),
								array('Name' => "nicht aktiv", 'Wert' => 2, "Notstrom nicht aktiv", 'Farbe' => -1),
								array('Name' => "nicht verfügbar", 'Wert' => 3, "Notstrom nicht verfügbar", 'Farbe' => 16753920),
								array('Name' => "Fehler", 'Wert' => 4, "Der Motorschalter des S10 E befindet sich nicht in der richtigen Position, sondern wurde manuell abgeschaltet oder nicht eingeschaltet.", 'Farbe' => $this->getRgbColor("red")),
							)
						);
						$this->createVarProfile(MODUL_PREFIX.".Powermeter.Int", VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, 0, array(
							array('Name' => "N/A", 'Wert' => 0),
							array('Name' => "Wurzelleistungsmesser", 'Wert' => 1, "Dies ist der Regelpunkt des Systems. Der Regelpunkt entspricht üblicherweise dem Hausanschlusspunkt."),
							array('Name' => "Externe Produktion", 'Wert' => 2),
							array('Name' => "Zweirichtungszähler", 'Wert' => 3),
							array('Name' => "Externer Verbrauch", 'Wert' => 4),
							array('Name' => "Farm", 'Wert' => 5),
							array('Name' => "Wird nicht verwendet", 'Wert' => 6),
							array('Name' => "Wallbox", 'Wert' => 7),
							array('Name' => "Externer Leistungsmesser Farm", 'Wert' => 8),
							array('Name' => "Datenanzeige", 'Wert' => 9, "Wird nicht in die Regelung eingebunden, sondern dient nur der Datenaufzeichnung des Kundenportals."),
							array('Name' => "Regelungsbypass", 'Wert' => 10, "Die gemessene Leistung wird nicht in die Batterie geladen, aus der Batterie entladen."),
							)
						);
			 */
//			$this->createVarProfile(MODUL_PREFIX.".Ampere.Int", VARIABLETYPE_INTEGER, ' A');
//			$this->createVarProfile(MODUL_PREFIX.".AmpereHour.Float", VARIABLETYPE_FLOAT, ' Ah');
//			$this->createVarProfile(MODUL_PREFIX.".AmpereHour.Int", VARIABLETYPE_INTEGER, ' Ah');
//			$this->createVarProfile(MODUL_PREFIX.".Angle.Float", VARIABLETYPE_FLOAT, ' °');
//			$this->createVarProfile(MODUL_PREFIX.".Angle.Int", VARIABLETYPE_INTEGER, ' °');
//			$this->createVarProfile(MODUL_PREFIX.".Blindleistung.Float", VARIABLETYPE_FLOAT, ' Var');
//			$this->createVarProfile(MODUL_PREFIX.".Blindleistung.Int", VARIABLETYPE_INTEGER, ' Var');
//			$this->createVarProfile(MODUL_PREFIX.".Electricity.Float", VARIABLETYPE_FLOAT, ' Wh');
//			$this->createVarProfile(MODUL_PREFIX.".Electricity.Int", VARIABLETYPE_INTEGER, ' Wh');
//			$this->createVarProfile(MODUL_PREFIX.".Hertz.Int", VARIABLETYPE_INTEGER, ' Hz');
			$this->createVarProfile(MODUL_PREFIX.".Hours.Int", VARIABLETYPE_INTEGER, ' h');
			$this->createVarProfile(MODUL_PREFIX.".MilliAmpere.Int", VARIABLETYPE_INTEGER, ' mA');
//			$this->createVarProfile(MODUL_PREFIX.".Ohm.Int", VARIABLETYPE_INTEGER, ' Ohm');
//			$this->createVarProfile(MODUL_PREFIX.".Scheinleistung.Float", VARIABLETYPE_FLOAT, ' VA');
//			$this->createVarProfile(MODUL_PREFIX.".Scheinleistung.Int", VARIABLETYPE_INTEGER, ' VA');
			// Temperature.Float: ~Temperature
//			$this->createVarProfile(MODUL_PREFIX.".Temperature.Int", VARIABLETYPE_INTEGER, ' °C');
			// Volt.Float: ~Volt
//			$this->createVarProfile(MODUL_PREFIX.".Volt.Int", VARIABLETYPE_INTEGER, ' V');
			$this->createVarProfile(MODUL_PREFIX.".Volumenstrom.Int", VARIABLETYPE_INTEGER, ' l/min');
			$this->createVarProfile(MODUL_PREFIX.".Watt.Int", VARIABLETYPE_INTEGER, ' W');

			// delete not used profiles
			foreach ($deleteProfiles_array as $profileName)
			{
				if (IPS_VariableProfileExists($profileName))
				{
					IPS_DeleteVariableProfile($profileName);
				}
			}
		}

		private function GetVariableValue(string $instanceIdent, string $variableIdent = "Value")//PHP8 : mixed
		{
			$instanceId = IPS_GetObjectIDByIdent($this->removeInvalidChars($instanceIdent), $this->InstanceID);
			$varId = IPS_GetObjectIDByIdent($this->removeInvalidChars($variableIdent), $instanceId);

			return GetValue($varId);
		}

		private function GetVariableId(string $instanceIdent, string $variableIdent = "Value"): int
		{
			$instanceId = IPS_GetObjectIDByIdent($this->removeInvalidChars($instanceIdent), $this->InstanceID);
			$varId = IPS_GetObjectIDByIdent($this->removeInvalidChars($variableIdent), $instanceId);

			return $varId;
		}

		private function GetLoggedValuesInterval(int $id, int $minutes)//PHP8 :mixed
		{
			$archiveId = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}");
			if (isset($archiveId[0]))
			{
				$archiveId = $archiveId[0];

				$returnValue = $this->getArithMittelOfLog($archiveId, $id, $minutes);
			}
			else
			{
				$archiveId = false;

				// no archive found
				$this->SetStatus(IS_NOARCHIVE);

				$returnValue = GetValue($id);
			}

			return $returnValue;
		}

		/* ToDo:
		## Raumtemperatur per Modbus-Register statt Raumbedienelement
		Normalerweise wird über das (optionale) Raumbedienelement die Raumtemperatur an die Solvis Control2 (SC2) gemeldet. Dadurch kann die Heizung die Regelung an die erreichte Raumtemperatur anpassen.

		Es gibt aber ein Modbus Register 34304 (Raumtemperatur 1), mit welchem die Raumtemperatur über die in IP Symcon ggf. vorhandenen Temperatursensoren per Modbus in das Register zu schreiben.
		Achtung: Schreiben schlägt trotz aktiviertem schreibenden Modbus-Zugriff fehl, wenn SC2/SC3 nicht für ein Raumbedienelement eingerichtet ist!

		### Voraussetzungen
		- Solvis Control muss mit Raumbedienelement für den Heizkreislauf konfiguriert sein (auch wenn kein Raumbedienelement per Kabel angeschlossen wird)
		- dafür ist ein Zurücksetzen der Solvis Control auf Werkseinstellung nötig, da in der Initialisierung das Raumbedienelement zum Heizkreislauf zugeordnet wird
		- Anschließend muss im Installateur-Menü unter `Sonstiges --> Remote --> Seite 3 --> Raumfühler HK1` auf `Modbus` umgestellt werden
		![Remote-HKR1-Raumfühler-Modbus](../doc/Solvis_SC2_Sonstiges_Remote_3.png)
		- und der Modbus-Modus muss auf `senden` was dem schreibenden Zugriff entspricht umgestellt werden, falls noch nicht geschehen

		!!! info "Hinweis"
			Die Temperatur muss ca. alle 60 Sekunden per Modbus in das Register geschrieben werden, sonst "verschwindet" die Temperatur in der Anzeige und zeigt nur noch "--"
		 */
	}
