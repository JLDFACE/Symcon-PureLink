<?php

/*
 * PureLink VL-VB300-PRO (4K All-in-One Video Bar) - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, keine globalen Funktionen ausserhalb der Klasse.
 * - Kein IO: Steuerung über die Telnet-CLI (Port 23) mit kurzlebiger Verbindung pro Aktion.
 *   Gleiche OEM-Plattform wie VL-BYOD200/VL-PTZ100 (OUI 34-1b-22), Befehle 'gbconfig'/'gbcontrol'.
 *   Die getestete Firmware V1.2.15 (Gerätekennung "VB20-P20") meldet sich OHNE Login direkt
 *   mit dem Prompt '~ #'. Ueber 'gbconfig --telnet-auth y' lässt sich am Gerät eine
 *   Anmeldung einschalten - der Transport erkennt den Login-Prompt daher automatisch.
 * - Polling: Slow/Fast Timer, FastAfterChange nach Aktionen. Der Status wird in EINER
 *   Telnet-Sitzung als Batch geholt (CliBatch), nicht mit einer Verbindung je Wert.
 * - Stabilität: Semaphore/Lock, niemals Fatals; Online/LastError nur bei Aenderung.
 * - UI-Stabilität: Pending-Logik für gesetzte Sollwerte.
 *
 * Unterschied zur VL-PTZ100: Diese Kamera fährt NICHT relativ ('--camera-autocoord'),
 * sondern über ABSOLUTE Koordinaten ('--camera-coord <x> <y>'). Die Grenzen und die
 * Schrittweite liefert das Gerät selbst per '--camera-geteptz' (xmin/xmax/xstep/ymin/
 * ymax/ystep). Manuelles Fahren ist damit deterministisch - kein Burst/Warmup nötig.
 * Manuelles Fahren setzt allerdings den Kamera-Modus 'normal' voraus (Tracking aus).
 *
 * VERIFIZIERT am Gerät: Solange kein Videostream läuft (kein USB-Host verbunden),
 * lehnt die Kamera '--camera-coord' und '--camera-zoom' mit Returncode -1 ab, auch im
 * Modus 'normal' und auch bei Wiederholung in derselben Sitzung. Die Konfigurations-
 * befehle (Spiegeln, WDR, OSD, Audio, Standby ...) greifen davon unabhängig sofort.
 * Das ist ein Gerätezustand, kein Modulfehler - LastError benennt ihn im Klartext.
 */

class PureLinkVB300PRO extends IPSModule
{
    // Kamera-Modi: Gerät spricht Klartext, Symcon speichert einen Integer.
    private static function ModeNames()
    {
        return array(
            0 => 'normal',
            1 => 'autoframing',
            2 => 'speakertracking',
            3 => 'presentertracking',
            4 => 'individualsgallery'
        );
    }

    public function Create()
    {
        parent::Create();

        // ---- Properties ----
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 23);
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('TimeoutMs', 4000);

        // Manuelles Fahren erzwingt Modus 'normal' (Tracking aus), sonst lehnt das Gerät ab.
        $this->RegisterPropertyBoolean('TrackingOffOnManual', true);
        // Schrittweite je Tipp als Vielfaches des geräteeigenen xstep/ystep.
        $this->RegisterPropertyInteger('StepFine', 1);
        $this->RegisterPropertyInteger('StepCoarse', 4);

        $this->RegisterPropertyInteger('PollSlow', 30);
        $this->RegisterPropertyInteger('PollFast', 5);
        $this->RegisterPropertyInteger('FastAfterChange', 20);
        $this->RegisterPropertyInteger('PendingTimeout', 8);

        // ---- State / Buffers ----
        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('LastStatusTs', '0');
        $this->SetBuffer('EPTZ', '');

        // ---- Diagnose-Variablen ----
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        $this->DisableAction('Online');
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 20);
        $this->RegisterVariableString('DeviceName', 'Gerätename', '~TextBox', 30);
        $this->RegisterVariableString('Model', 'Modell', '~TextBox', 35);
        $this->RegisterVariableString('Firmware', 'Firmware', '~TextBox', 40);

        // ---- Kamera: Modus ----
        $this->RegisterProfileInteger('PLVB.Mode', 'Eye', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Mode', array(
            array(0, 'Normal (Tracking aus)', '', -1),
            array(1, 'Auto-Framing', '', -1),
            array(2, 'Speaker-Tracking', '', -1),
            array(3, 'Presenter-Tracking', '', -1),
            array(4, 'Individuals-Gallery', '', -1)
        ));
        $this->RegisterVariableInteger('Mode', 'Kamera-Modus', 'PLVB.Mode', 50);
        $this->EnableAction('Mode');

        // ---- Kamera: manuelles Fahren ----
        // Transiente Steuervariable: fällt nach der Aktion auf 0 zurück, damit
        // jeder Tipp erneut auslöst. 1=Links 2=Rechts 3=Hoch 4=Runter 5=Mitte.
        $this->RegisterProfileInteger('PLVB.Drive', 'Move', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Drive', array(
            array(1, 'Links',  'ArrowLeft',  -1),
            array(2, 'Rechts', 'ArrowRight', -1),
            array(3, 'Hoch',   'ArrowUp',    -1),
            array(4, 'Runter', 'ArrowDown',  -1),
            array(5, 'Mitte',  'Crosshairs', -1)
        ));
        $this->RegisterVariableInteger('Drive', 'Fahren', 'PLVB.Drive', 52);
        $this->EnableAction('Drive');

        $this->RegisterVariableBoolean('CoarseStep', 'Grosse Schritte', '~Switch', 54);
        $this->EnableAction('CoarseStep');

        // Ist-Position (vom Gerät gelesen, nicht schaltbar)
        $this->RegisterVariableInteger('PosX', 'Position X', '', 56);
        $this->DisableAction('PosX');
        $this->RegisterVariableInteger('PosY', 'Position Y', '', 58);
        $this->DisableAction('PosY');

        // ---- Kamera: Zoom ----
        // Bereich wird in ApplyChanges aus phymaxzoom des Geräts nachgezogen.
        $this->RegisterProfileInteger('PLVB.Zoom', 'Zoom', '', '', 100, 500, 10);
        $this->RegisterVariableInteger('Zoom', 'Zoom (100 = 1x)', 'PLVB.Zoom', 60);
        $this->EnableAction('Zoom');

        // ---- Kamera: Presets (Gerät kennt genau 2 Speicherplätze) ----
        $this->RegisterProfileInteger('PLVB.Preset', 'Camera', '', '', 1, 2, 1);
        $this->RegisterVariableInteger('Preset', 'Preset (Laden)', 'PLVB.Preset', 70);
        $this->EnableAction('Preset');

        // ---- Kamera: Tracking-Verhalten ----
        $this->RegisterProfileInteger('PLVB.Transition', 'Move', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Transition', array(
            array(0, 'Weich (smooth)', '', -1),
            array(1, 'Sofort (immediate)', '', -1)
        ));
        $this->RegisterVariableInteger('AutoFraming', 'Auto-Framing Uebergang', 'PLVB.Transition', 72);
        $this->EnableAction('AutoFraming');

        $this->RegisterProfileInteger('PLVB.Speed', 'Speedo', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Speed', array(
            array(0, 'Langsam', '', -1),
            array(1, 'Normal', '', -1),
            array(2, 'Schnell', '', -1)
        ));
        $this->RegisterVariableInteger('AutoFramingSpeed', 'Auto-Framing Tempo', 'PLVB.Speed', 74);
        $this->EnableAction('AutoFramingSpeed');

        // ---- Kamera: Bild ----
        $this->RegisterVariableBoolean('Mirror', 'Bild spiegeln', '~Switch', 80);
        $this->EnableAction('Mirror');

        $this->RegisterVariableBoolean('HD', 'HD-Modus', '~Switch', 82);
        $this->EnableAction('HD');

        $this->RegisterProfileInteger('PLVB.WDR', 'Sun', '', '', -7, 7, 1);
        $this->RegisterVariableInteger('WDR', 'WDR', 'PLVB.WDR', 84);
        $this->EnableAction('WDR');

        $this->RegisterProfileInteger('PLVB.PowerFreq', 'Lightbulb', '', ' Hz', 0, 0, 0);
        $this->SetAssociations('PLVB.PowerFreq', array(
            array(50, '50 Hz', '', -1),
            array(60, '60 Hz', '', -1)
        ));
        $this->RegisterVariableInteger('PowerFreq', 'Netzfrequenz (Flimmern)', 'PLVB.PowerFreq', 86);
        $this->EnableAction('PowerFreq');

        $this->RegisterProfileInteger('PLVB.MaxRes', 'Display', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.MaxRes', array(
            array(0, 'Stufe 0', '', -1),
            array(1, 'Stufe 1', '', -1),
            array(2, 'Stufe 2', '', -1)
        ));
        $this->RegisterVariableInteger('MaxResolution', 'Max. Auflösung', 'PLVB.MaxRes', 88);
        $this->EnableAction('MaxResolution');

        // ---- Audio ----
        $this->RegisterProfileInteger('PLVB.AudioOut', 'Speaker', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.AudioOut', array(
            array(0, 'Lautsprecher (intern)', '', -1),
            array(1, 'HDMI', '', -1),
            array(2, 'Modus 2', '', -1)
        ));
        $this->RegisterVariableInteger('AudioOut', 'Audio-Ausgang', 'PLVB.AudioOut', 90);
        $this->EnableAction('AudioOut');

        // Transiente Lautstärke-Wippe (Gerät kennt nur inc/dec, keinen absoluten Pegel)
        $this->RegisterProfileInteger('PLVB.Volume', 'Volume', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Volume', array(
            array(1, 'Leiser', 'Minus', -1),
            array(2, 'Lauter', 'Plus', -1)
        ));
        $this->RegisterVariableInteger('VolumeStep', 'Lautstärke', 'PLVB.Volume', 92);
        $this->EnableAction('VolumeStep');

        // Gerät kennt das Flag invertiert ('vb-mic-disable'); die Variable zeigt
        // den verständlichen Zustand "Mikrofon aktiv".
        $this->RegisterVariableBoolean('MicEnabled', 'Mikrofon aktiv', '~Switch', 94);
        $this->EnableAction('MicEnabled');

        $this->RegisterVariableBoolean('MiceEnabled', 'USB-Maus/Tastatur', '~Switch', 96);
        $this->EnableAction('MiceEnabled');

        // ---- Gerät / Display ----
        $this->RegisterVariableBoolean('OSD', 'OSD-Einblendung', '~Switch', 100);
        $this->EnableAction('OSD');

        $this->RegisterVariableBoolean('CEC', 'CEC', '~Switch', 102);
        $this->EnableAction('CEC');

        $this->RegisterVariableBoolean('AutoStandby', 'Auto-Standby', '~Switch', 104);
        $this->EnableAction('AutoStandby');

        $this->RegisterProfileInteger('PLVB.StandbyTime', 'Clock', '', ' Min.', 0, 30, 1);
        $this->RegisterVariableInteger('AutoStandbyTime', 'Auto-Standby nach', 'PLVB.StandbyTime', 106);
        $this->EnableAction('AutoStandbyTime');

        // Display-Power über CEC (sinkpower). Reiner Sendebefehl - das Gerät
        // meldet den Zustand des Displays nicht zurück, daher kein Polling.
        $this->RegisterVariableBoolean('DisplayPower', 'Display (CEC)', '~Switch', 110);
        $this->EnableAction('DisplayPower');

        // ---- Timers ----
        $this->RegisterTimer('PollSlow', 0, 'PLVB_PollNow($_IPS["TARGET"]);');
        $this->RegisterTimer('PollFast', 0, 'PLVB_PollNow($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Destroy() beim Modul-Update löscht die Instanzprofile - hier bewusst
        // neu anlegen, damit sie auch bei offline-Gerät wieder stehen.
        $this->EnsureProfiles();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host != '') {
            // Zoom-Grenze und Fahr-Schrittweite vom Gerät übernehmen (best effort).
            $this->RefreshEPTZ();
        }

        $this->UpdatePollingTimers(false);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        // Die öffentlichen Setter pflegen Variable und Pending selbst - hier wird
        // deshalb nur noch abgebildet, welcher Ident welchen Setter aufruft.
        switch ($Ident) {
            case 'Drive':
                $v = intval($Value);
                if ($v == 5) {
                    $this->MoveHome();
                } else {
                    $map = array(1 => 'l', 2 => 'r', 3 => 'u', 4 => 'd');
                    if (isset($map[$v])) $this->Move($map[$v]);
                }
                // Steuervariable bleibt neutral, damit jeder Tipp neu auslöst
                $this->SetValueIntegerSafe('Drive', 0);
                return;

            case 'CoarseStep':
                $this->SetValueBooleanSafe('CoarseStep', (bool)$Value);
                return;

            case 'VolumeStep':
                $v = intval($Value);
                if ($v == 1) $this->VolumeDown();
                if ($v == 2) $this->VolumeUp();
                $this->SetValueIntegerSafe('VolumeStep', 0);
                return;

            case 'Mode':             $this->SetCameraMode(intval($Value)); return;
            case 'Zoom':             $this->SetZoom(intval($Value)); return;
            case 'Preset':           $this->PresetLoad(intval($Value)); return;
            case 'AutoFraming':      $this->SetAutoFramingTransition(intval($Value)); return;
            case 'AutoFramingSpeed': $this->SetAutoFramingSpeed(intval($Value)); return;
            case 'WDR':              $this->SetWDR(intval($Value)); return;
            case 'PowerFreq':        $this->SetPowerFrequency(intval($Value)); return;
            case 'MaxResolution':    $this->SetMaxResolution(intval($Value)); return;
            case 'AudioOut':         $this->SetAudioOutput(intval($Value)); return;
            case 'AutoStandbyTime':  $this->SetAutoStandbyTime(intval($Value)); return;
            case 'Mirror':           $this->SetMirror((bool)$Value); return;
            case 'HD':               $this->SetHD((bool)$Value); return;
            case 'MicEnabled':       $this->SetMicrophone((bool)$Value); return;
            case 'MiceEnabled':      $this->SetMice((bool)$Value); return;
            case 'OSD':              $this->SetOSD((bool)$Value); return;
            case 'CEC':              $this->SetCEC((bool)$Value); return;
            case 'AutoStandby':      $this->SetAutoStandby((bool)$Value); return;
            case 'DisplayPower':     $this->SetDisplayPower((bool)$Value, 'ALL'); return;
        }
    }

    // =========================================================================
    // Public API (Form-Buttons / Skripte)
    // =========================================================================

    // Kamera-Modus 0..4 (siehe ModeNames()).
    public function SetCameraMode(int $mode)
    {
        $names = self::ModeNames();
        $m = intval($mode);
        if (!isset($names[$m])) {
            $this->SetOnline(false, 'Ungültiger Kamera-Modus: ' . $m);
            return false;
        }
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-mode ' . $names[$m]));
        $this->ActionInt('Mode', $m, $ok);
        return $ok;
    }

    // Richtung: 'l' | 'r' | 'u' | 'd' - ein Schritt (fein/grob je CoarseStep).
    public function Move(string $dir)
    {
        $d = strtolower(trim(strval($dir)));
        if ($d != 'l' && $d != 'r' && $d != 'u' && $d != 'd') {
            $this->SetOnline(false, 'Ungültige Richtung: ' . $dir);
            return false;
        }

        if (!$this->TryLock()) return false;

        $e = $this->GetEPTZ();
        $cur = $this->ReadCoord();
        if ($cur === false) {
            $this->Unlock();
            return false;
        }

        $coarse = false;
        $cid = @$this->GetIDForIdent('CoarseStep');
        if ($cid) $coarse = GetValueBoolean($cid);
        $mult = intval($coarse ? $this->ReadPropertyInteger('StepCoarse') : $this->ReadPropertyInteger('StepFine'));
        if ($mult < 1) $mult = 1;

        $x = $cur[0];
        $y = $cur[1];
        // Links/Rechts bewegen die Bildmitte: nach links = kleinere X-Koordinate.
        if ($d == 'l') $x -= $e['xstep'] * $mult;
        if ($d == 'r') $x += $e['xstep'] * $mult;
        if ($d == 'u') $y += $e['ystep'] * $mult;
        if ($d == 'd') $y -= $e['ystep'] * $mult;

        $x = $this->Clamp($x, $e['xmin'], $e['xmax']);
        $y = $this->Clamp($y, $e['ymin'], $e['ymax']);

        $ok = $this->EvalReturn($this->ManualCli('gbconfig --camera-coord ' . $x . ' ' . $y));
        if ($ok) {
            $this->SetValueIntegerSafe('PosX', $x);
            $this->SetValueIntegerSafe('PosY', $y);
        }
        $this->Unlock();
        if ($ok) $this->BumpFastPoll();
        return $ok;
    }

    // Fährt auf die Mittelposition (0/0).
    public function MoveHome()
    {
        return $this->MoveTo(0, 0);
    }

    // Absolute Position anfahren (Grenzen liefert das Gerät, Werte werden begrenzt).
    public function MoveTo(int $x, int $y)
    {
        if (!$this->TryLock()) return false;
        $e = $this->GetEPTZ();
        $px = $this->Clamp(intval($x), $e['xmin'], $e['xmax']);
        $py = $this->Clamp(intval($y), $e['ymin'], $e['ymax']);
        $ok = $this->EvalReturn($this->ManualCli('gbconfig --camera-coord ' . $px . ' ' . $py));
        if ($ok) {
            $this->SetValueIntegerSafe('PosX', $px);
            $this->SetValueIntegerSafe('PosY', $py);
        }
        $this->Unlock();
        if ($ok) $this->BumpFastPoll();
        return $ok;
    }

    // Zoom, 100 = 1x. Obergrenze liefert das Gerät (phymaxzoom).
    public function SetZoom(int $zoom)
    {
        if (!$this->TryLock()) return false;
        $e = $this->GetEPTZ();
        $z = $this->Clamp(intval($zoom), 100, $e['phymaxzoom']);
        $ok = $this->EvalReturn($this->ManualCli('gbconfig --camera-zoom ' . $z));
        $this->Unlock();
        $this->ActionInt('Zoom', $z, $ok);
        return $ok;
    }

    // Preset 1..2 speichern (aktuelle Position).
    public function PresetSave(int $no)
    {
        $n = intval($no);
        if ($n < 1 || $n > 2) {
            $this->SetOnline(false, 'Preset-Nummer muss 1 oder 2 sein.');
            return false;
        }
        return $this->EvalReturn($this->Cli('gbconfig --camera-savecoord ' . $n));
    }

    // Preset 1..2 laden.
    public function PresetLoad(int $no)
    {
        $n = intval($no);
        if ($n < 1 || $n > 2) {
            $this->SetOnline(false, 'Preset-Nummer muss 1 oder 2 sein.');
            return false;
        }
        if (!$this->TryLock()) return false;
        $ok = $this->EvalReturn($this->ManualCli('gbconfig --camera-loadcoord ' . $n));
        $this->Unlock();
        if ($ok) {
            $this->SetValueIntegerSafe('Preset', $n);
            $this->BumpFastPoll();
        }
        return $ok;
    }

    // 0 = weich (smooth), 1 = sofort (immediate)
    public function SetAutoFramingTransition(int $mode)
    {
        $v = intval($mode) ? 'immediate' : 'smooth';
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-autoframing ' . $v));
        $this->ActionInt('AutoFraming', intval($mode) ? 1 : 0, $ok);
        return $ok;
    }

    // 0 = langsam, 1 = normal, 2 = schnell
    public function SetAutoFramingSpeed(int $speed)
    {
        $map = array(0 => 'slow', 1 => 'normal', 2 => 'fast');
        $s = intval($speed);
        if (!isset($map[$s])) {
            $this->SetOnline(false, 'Ungültiges Tempo: ' . $s);
            return false;
        }
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-autoframingspeed ' . $map[$s]));
        $this->ActionInt('AutoFramingSpeed', $s, $ok);
        return $ok;
    }

    public function SetMirror(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-mirror ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('Mirror', $enable, $ok);
        return $ok;
    }

    public function SetHD(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-hd ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('HD', $enable, $ok);
        return $ok;
    }

    // WDR -7 .. 7
    public function SetWDR(int $value)
    {
        $v = $this->Clamp(intval($value), -7, 7);
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-wdr ' . $v));
        $this->ActionInt('WDR', $v, $ok);
        return $ok;
    }

    // 50 oder 60 (Hz)
    public function SetPowerFrequency(int $hz)
    {
        $v = intval($hz);
        if ($v != 50 && $v != 60) {
            $this->SetOnline(false, 'Netzfrequenz muss 50 oder 60 sein.');
            return false;
        }
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-powerfreq ' . $v));
        $this->ActionInt('PowerFreq', $v, $ok);
        return $ok;
    }

    // 0 | 1 | 2
    public function SetMaxResolution(int $level)
    {
        $v = intval($level);
        if ($v < 0 || $v > 2) {
            $this->SetOnline(false, 'Auflösungsstufe muss 0, 1 oder 2 sein.');
            return false;
        }
        $ok = $this->EvalReturn($this->Cli('gbconfig --camera-max-resolution ' . $v));
        $this->ActionInt('MaxResolution', $v, $ok);
        return $ok;
    }

    // 0 = Lautsprecher, 1 = HDMI, 2 = Modus 2
    public function SetAudioOutput(int $mode)
    {
        $v = intval($mode);
        if ($v < 0 || $v > 2) {
            $this->SetOnline(false, 'Audio-Ausgang muss 0, 1 oder 2 sein.');
            return false;
        }
        $ok = $this->EvalReturn($this->Cli('gbconfig --ao-select ' . $v));
        $this->ActionInt('AudioOut', $v, $ok);
        return $ok;
    }

    public function VolumeUp()
    {
        return $this->EvalReturn($this->Cli('gbconfig --autovolume inc'));
    }

    public function VolumeDown()
    {
        return $this->EvalReturn($this->Cli('gbconfig --autovolume dec'));
    }

    // true = Mikrofon aktiv (Gerät kennt das Flag invertiert als 'vb-mic-disable').
    public function SetMicrophone(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --vb-mic-disable ' . ($enable ? 'n' : 'y')));
        $this->ActionBool('MicEnabled', $enable, $ok);
        return $ok;
    }

    public function SetMice(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --mice-enable ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('MiceEnabled', $enable, $ok);
        return $ok;
    }

    public function SetOSD(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --osd-enable ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('OSD', $enable, $ok);
        return $ok;
    }

    public function SetCEC(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --cec-enable ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('CEC', $enable, $ok);
        return $ok;
    }

    public function SetAutoStandby(bool $enable)
    {
        $ok = $this->EvalReturn($this->Cli('gbconfig --auto-standby-enable ' . ($enable ? 'y' : 'n')));
        $this->ActionBool('AutoStandby', $enable, $ok);
        return $ok;
    }

    // 0..30 Minuten (0 = Countdown erscheint sofort)
    public function SetAutoStandbyTime(int $minutes)
    {
        $v = $this->Clamp(intval($minutes), 0, 30);
        $ok = $this->EvalReturn($this->Cli('gbconfig --auto-standby-time ' . $v));
        $this->ActionInt('AutoStandbyTime', $v, $ok);
        return $ok;
    }

    // Display per CEC schalten. $sink: 'HDMI1' | 'HDMI2' | 'ALL'
    public function SetDisplayPower(bool $on, string $sink)
    {
        $s = strtoupper(trim(strval($sink)));
        if ($s != 'HDMI1' && $s != 'HDMI2' && $s != 'ALL') $s = 'ALL';
        $ok = $this->EvalReturn($this->Cli('gbcontrol --sinkpower ' . ($on ? 'on' : 'off') . ' ' . $s));
        // Kein Rückkanal: das Gerät meldet den Zustand des Displays nicht,
        // der Schalter folgt deshalb dem gesendeten Befehl.
        if ($ok) $this->SetValueBooleanSafe('DisplayPower', (bool)$on);
        return $ok;
    }

    public function Reboot()
    {
        $r = $this->Cli('gbcontrol --reboot');
        $this->BumpFastPoll();
        return $r !== false;
    }

    // Beliebigen CLI-Befehl senden (Diagnose).
    public function SendCommand(string $rawCommand)
    {
        $cmd = trim(strval($rawCommand));
        if ($cmd == '') return '';
        $r = $this->Cli($cmd);
        return ($r === false) ? '' : $r;
    }

    public function TestConnection()
    {
        $r = $this->Cli('gbconfig -s device-info');
        if ($r === false || trim($r) === '') {
            $this->SetOnline(false, 'Keine Antwort auf device-info.');
            return false;
        }
        $this->SetOnline(true, '');
        return true;
    }

    public function PollNow()
    {
        $this->Poll();
    }

    // =========================================================================
    // Core: Polling
    // =========================================================================

    private function Poll()
    {
        if (!$this->TryLock()) {
            $this->BumpFastPoll();
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host == '') {
            $this->SetOnline(false, 'Konfiguration unvollständig (Host).');
            $this->Unlock();
            return;
        }

        // Kompletter Status in EINER Sitzung - eine Verbindung je Wert würde die
        // CLI unnötig belasten und dauert bei ~17 Werten deutlich zu lange.
        // Die Zuordnung läuft über den Befehl als Schlüssel, nicht über den Index:
        // eine später eingeschobene Abfrage kann so keine Werte verschieben.
        $cmds = array(
            'info'      => 'gbconfig -s device-info',
            'name'      => 'gbconfig -s name',
            'mode'      => 'gbconfig -s camera-mode',
            'coord'     => 'gbconfig -s camera-coord',
            'zoom'      => 'gbconfig -s camera-zoom',
            'getconfig' => 'gbconfig --camera-getconfig',
            'mirror'    => 'gbconfig -s camera-mirror',
            'wdr'       => 'gbconfig -s camera-wdr',
            'hd'        => 'gbconfig -s camera-hd',
            'maxres'    => 'gbconfig -s camera-max-resolution',
            'ao'        => 'gbconfig -s ao-select',
            'micdis'    => 'gbconfig -s vb-mic-disable',
            'mice'      => 'gbconfig -s mice-enable',
            'osd'       => 'gbconfig -s osd-enable',
            'cec'       => 'gbconfig -s cec-enable',
            'standby'   => 'gbconfig -s auto-standby-enable',
            'standbyt'  => 'gbconfig -s auto-standby-time'
        );
        $r = $this->CliBatch($cmds);
        if ($r === false) {
            $this->SetOnline(false, 'Keine Antwort (Status-Abfrage).');
            $this->Unlock();
            return;
        }
        $this->SetOnline(true, '');

        // device-info: Zeile 1 Modell (z.B. "VB20-P20"), Zeile 2 Firmware
        $info = $this->SplitLines($this->Pick($r, 'info'));
        if (isset($info[0])) $this->SetValueStringSafe('Model', $info[0]);
        if (isset($info[1])) $this->SetValueStringSafe('Firmware', $info[1]);

        $name = trim($this->Pick($r, 'name'));
        if ($name !== '') $this->SetValueStringSafe('DeviceName', $name);

        // Kamera-Modus: Gerät antwortet im Klartext
        $modeName = strtolower(trim($this->Pick($r, 'mode')));
        if ($modeName !== '') {
            $idx = array_search($modeName, self::ModeNames(), true);
            if ($idx !== false) $this->ApplyIntWithPending('Mode', intval($idx));
        }

        // Position "x y"
        $coord = trim($this->Pick($r, 'coord'));
        if (preg_match('/^(-?\d+)\s+(-?\d+)$/', $coord, $m)) {
            $this->SetValueIntegerSafe('PosX', intval($m[1]));
            $this->SetValueIntegerSafe('PosY', intval($m[2]));
        }

        $zoom = trim($this->Pick($r, 'zoom'));
        if (preg_match('/^\d+$/', $zoom)) {
            $this->ApplyIntWithPending('Zoom', intval($zoom));
        }

        // getconfig liefert Tracking-Verhalten und Gerätegrenzen als JSON.
        // Hinweis: Für 'mirror' und 'osd' weicht die JSON-Sicht auf der getesteten
        // Firmware von 'gbconfig -s <item>' ab. Massgeblich ist hier die Einzelabfrage,
        // weil sie den per CLI gesetzten Zustand spiegelt - aus dem JSON werden daher
        // nur Tracking-Verhalten, Netzfrequenz und die Grenzwerte übernommen.
        $cfg = $this->DecodeResult($this->Pick($r, 'getconfig'));
        if (is_array($cfg)) {
            $this->StoreEPTZ($cfg);
            if (isset($cfg['autoframing'])) {
                $this->ApplyIntWithPending('AutoFraming', (strtolower($cfg['autoframing']) === 'immediate') ? 1 : 0);
            }
            if (isset($cfg['autoframingspeed'])) {
                $map = array('slow' => 0, 'normal' => 1, 'fast' => 2);
                $sp = strtolower($cfg['autoframingspeed']);
                if (isset($map[$sp])) $this->ApplyIntWithPending('AutoFramingSpeed', $map[$sp]);
            }
            if (isset($cfg['powerfreq'])) {
                $pf = intval($cfg['powerfreq']);
                if ($pf == 50 || $pf == 60) $this->ApplyIntWithPending('PowerFreq', $pf);
            }
        }

        $mirror = trim($this->Pick($r, 'mirror'));
        if ($mirror !== '') $this->ApplyBoolWithPending('Mirror', $this->YesNoToInt($mirror));

        $wdr = trim($this->Pick($r, 'wdr'));
        if (preg_match('/^-?\d+$/', $wdr)) $this->ApplyIntWithPending('WDR', intval($wdr));

        $hd = trim($this->Pick($r, 'hd'));
        if ($hd !== '') $this->ApplyBoolWithPending('HD', $this->YesNoToInt($hd));

        $mres = trim($this->Pick($r, 'maxres'));
        if (preg_match('/^\d+$/', $mres)) $this->ApplyIntWithPending('MaxResolution', intval($mres));

        $ao = trim($this->Pick($r, 'ao'));
        if (preg_match('/^\d+$/', $ao)) $this->ApplyIntWithPending('AudioOut', intval($ao));

        // 'vb-mic-disable' ist invertiert zur Variable "Mikrofon aktiv"
        $micDis = trim($this->Pick($r, 'micdis'));
        if ($micDis !== '') $this->ApplyBoolWithPending('MicEnabled', $this->YesNoToInt($micDis) ? 0 : 1);

        $mice = trim($this->Pick($r, 'mice'));
        if ($mice !== '') $this->ApplyBoolWithPending('MiceEnabled', $this->YesNoToInt($mice));

        $osd = trim($this->Pick($r, 'osd'));
        if ($osd !== '') $this->ApplyBoolWithPending('OSD', $this->YesNoToInt($osd));

        $cec = trim($this->Pick($r, 'cec'));
        if ($cec !== '') $this->ApplyBoolWithPending('CEC', $this->YesNoToInt($cec));

        $asb = trim($this->Pick($r, 'standby'));
        if ($asb !== '') $this->ApplyBoolWithPending('AutoStandby', $this->YesNoToInt($asb));

        $asbt = trim($this->Pick($r, 'standbyt'));
        if (preg_match('/^\d+$/', $asbt)) $this->ApplyIntWithPending('AutoStandbyTime', intval($asbt));

        $this->SetBuffer('LastStatusTs', strval(time()));
        $this->Unlock();
    }

    // =========================================================================
    // Helpers: Gerätegrenzen (EPTZ), Modus, Returncodes
    // =========================================================================

    // Holt xmin/xmax/xstep/ymin/ymax/ystep/phymaxzoom vom Gerät und puffert sie.
    private function RefreshEPTZ()
    {
        $raw = $this->Cli('gbconfig --camera-geteptz');
        if ($raw === false) return false;
        $d = $this->DecodeResult($raw);
        if (!is_array($d)) return false;
        $this->StoreEPTZ($d);
        return true;
    }

    private function StoreEPTZ($d)
    {
        $e = $this->GetEPTZ();
        $keys = array('xmin', 'xmax', 'xstep', 'ymin', 'ymax', 'ystep', 'phymaxzoom');
        $changed = false;
        foreach ($keys as $k) {
            if (isset($d[$k]) && intval($d[$k]) != 0) {
                if ($e[$k] != intval($d[$k])) $changed = true;
                $e[$k] = intval($d[$k]);
            }
        }
        $this->SetBuffer('EPTZ', json_encode($e));

        // Zoom-Profil an die tatsächliche Gerätegrenze anpassen
        if ($changed && $e['phymaxzoom'] > 100 && IPS_VariableProfileExists('PLVB.Zoom')) {
            @IPS_SetVariableProfileValues('PLVB.Zoom', 100, $e['phymaxzoom'], 10);
        }
    }

    // Gepufferte Grenzen; Fallback sind die Werte der getesteten Firmware.
    private function GetEPTZ()
    {
        $def = array(
            'xmin' => -108000, 'xmax' => 108000, 'xstep' => 3600,
            'ymin' => -108000, 'ymax' => 108000, 'ystep' => 3600,
            'phymaxzoom' => 500
        );
        $raw = $this->GetBuffer('EPTZ');
        if ($raw === null || $raw === '') return $def;
        $d = @json_decode($raw, true);
        if (!is_array($d)) return $def;
        foreach ($def as $k => $v) {
            if (!isset($d[$k]) || intval($d[$k]) == 0) $d[$k] = $v;
            else $d[$k] = intval($d[$k]);
        }
        return $d;
    }

    // Führt einen Befehl aus, der den Kamera-Modus 'normal' voraussetzt.
    // Lehnt das Gerät mit Code -2 ab (Tracking aktiv), wird einmal hart auf 'normal'
    // geschaltet und der Befehl wiederholt. Der Schnellpfad in EnsureManual() stützt
    // sich auf die Mode-Variable, und die kann veraltet sein - etwa wenn der Modus am
    // Gerät selbst oder über die Web-UI umgestellt wurde. Erwartet einen gehaltenen Lock.
    private function ManualCli($command)
    {
        $this->EnsureManual();
        $r = $this->CliRaw($command);
        if ($r === false) return false;
        if (trim($r) !== '-2') return $r;
        if (!$this->ReadPropertyBoolean('TrackingOffOnManual')) return $r;

        if ($this->CliRaw('gbconfig --camera-mode normal') === false) return $r;
        $this->SetPending('Mode', 0);
        $this->SetValueIntegerSafe('Mode', 0);
        return $this->CliRaw($command);
    }

    // Manuelles Fahren/Zoomen verlangt Modus 'normal'. Erwartet einen gehaltenen Lock.
    private function EnsureManual()
    {
        if (!$this->ReadPropertyBoolean('TrackingOffOnManual')) return true;

        $mid = @$this->GetIDForIdent('Mode');
        if ($mid && GetValueInteger($mid) === 0) return true;

        $mode = $this->CliRaw('gbconfig -s camera-mode');
        if ($mode === false) return false;
        if (strtolower(trim($mode)) !== 'normal') {
            $r = $this->CliRaw('gbconfig --camera-mode normal');
            if ($r === false) return false;
            $this->SetPending('Mode', 0);
            $this->SetValueIntegerSafe('Mode', 0);
        }
        return true;
    }

    // Bewertet die Antwort eines Steuerbefehls. Negative Zahl = Fehler,
    // eine zurückgegebene 'usage:'-Zeile ebenfalls (Parameter abgelehnt).
    private function EvalReturn($resp)
    {
        if ($resp === false) return false;

        $t = trim($resp);
        if (preg_match('/^-?\d+$/', $t)) {
            $n = intval($t);
            if ($n < 0) {
                $this->SetOnline(false, 'Befehl abgelehnt (Code ' . $n . '): ' . $this->ReturnCodeText($n));
                return false;
            }
        }
        // Das Gerät antwortet auf ungültige Parameter mit der Syntaxzeile.
        if (stripos($t, 'usage:') !== false || preg_match('/^gbconfig --|^gbcontrol --/', $t)) {
            $this->SetOnline(false, 'Befehl abgelehnt (Syntax): ' . $this->FirstLine($t));
            return false;
        }
        $this->ClearLastError();
        return true;
    }

    private function ReturnCodeText($n)
    {
        switch (intval($n)) {
            case -1: return 'Kamera-Engine im Leerlauf - PTZ/Zoom werden erst angenommen, '
                          . 'wenn ein Videostream läuft (USB-Host verbunden bzw. Display aktiv)';
            case -2: return 'Kamera-Modus ist nicht "normal" (Tracking aktiv)';
            case -3: return 'Preset existiert nicht';
            case -4: return 'Ungültiger Parameter';
            case -5: return 'Preset-Nummer-Fehler';
            case -6: return 'Autofokus ist an';
            default: return 'unbekannt';
        }
    }

    // Entpackt {"result":{...}} aus den JSON-Antworten der CLI.
    private function DecodeResult($raw)
    {
        if ($raw === false) return false;
        $s = trim(strval($raw));
        $p = strpos($s, '{');
        if ($p === false) return false;
        $d = @json_decode(substr($s, $p), true);
        if (!is_array($d)) return false;
        if (isset($d['result']) && is_array($d['result'])) return $d['result'];
        return $d;
    }

    private function YesNoToInt($s)
    {
        $t = strtolower(trim($s));
        if ($t === 'y' || $t === '1' || $t === 'yes') return 1;
        return 0;
    }

    private function Clamp($v, $min, $max)
    {
        $v = intval($v);
        if ($v < $min) return intval($min);
        if ($v > $max) return intval($max);
        return $v;
    }

    // Liest die aktuelle Position. Erwartet einen gehaltenen Lock.
    private function ReadCoord()
    {
        $c = $this->CliRaw('gbconfig -s camera-coord');
        if ($c === false) return false;
        if (preg_match('/(-?\d+)\s+(-?\d+)/', trim($c), $m)) {
            return array(intval($m[1]), intval($m[2]));
        }
        $this->SetOnline(false, 'Position nicht lesbar: ' . $this->FirstLine($c));
        return false;
    }

    // Holt eine Antwort aus dem Batch-Ergebnis (Schlüssel wie in Poll() vergeben).
    private function Pick($arr, $key)
    {
        if (!is_array($arr) || !isset($arr[$key]) || $arr[$key] === false) return '';
        return strval($arr[$key]);
    }

    // Setzt Variable + Pending nach einer erfolgreichen Integer-Aktion.
    private function ActionInt($ident, $value, $ok)
    {
        if (!$ok) return;
        $this->SetPending($ident, intval($value));
        $this->SetValueIntegerSafe($ident, intval($value));
        $this->BumpFastPoll();
    }

    private function ActionBool($ident, $value, $ok)
    {
        if (!$ok) return;
        $this->SetPending($ident, $value ? 1 : 0);
        $this->SetValueBooleanSafe($ident, (bool)$value);
        $this->BumpFastPoll();
    }

    // =========================================================================
    // Telnet-Transport
    // =========================================================================

    // Einzelbefehl mit eigener Lock-Verwaltung (nur wenn der Lock frei ist).
    private function Cli($command)
    {
        $ownLock = $this->TryLock();
        $res = $this->CliRaw($command);
        if ($ownLock) $this->Unlock();
        return $res;
    }

    // Einzelbefehl ohne Lock-Verwaltung (Aufruf innerhalb eines gehaltenen Locks).
    private function CliRaw($command)
    {
        $r = $this->CliBatch(array('r' => $command));
        if ($r === false) return false;
        return $this->Pick($r, 'r');
    }

    // Führt mehrere Befehle in EINER Telnet-Sitzung aus.
    // $commands ist ein Array Schlüssel => Befehl; die Rückgabe trägt dieselben
    // Schlüssel mit den bereinigten Antworten - oder false bei Verbindungsfehler.
    private function CliBatch($commands)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        if ($host == '' || $port < 1 || $port > 65535) {
            $this->SetOnline(false, 'Konfiguration unvollständig (Host/Port).');
            return false;
        }

        $user = $this->ReadPropertyString('Username');
        $pass = $this->ReadPropertyString('Password');
        $timeoutMs = intval($this->ReadPropertyInteger('TimeoutMs'));
        if ($timeoutMs < 500) $timeoutMs = 4000;
        $timeoutSec = max(1, intval(ceil($timeoutMs / 1000)));

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeoutSec);
        if ($fp === false) {
            $this->SetOnline(false, 'Telnet-Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')');
            return false;
        }
        stream_set_timeout($fp, 0, 300000);

        // Banner lesen: entweder Login-Prompt oder direkt Shell-Prompt.
        // Die getestete Firmware meldet sich ohne Login; 'gbconfig --telnet-auth y'
        // kann das am Gerät ändern, daher beide Fälle behandeln.
        $banner = $this->TelnetTransceive($fp, null, array('username:', 'login:', 'name:', '# ', '$ ', '> '), $timeoutMs);
        if ($banner === false) {
            fclose($fp);
            $this->SetOnline(false, 'Kein Prompt/Banner.');
            return false;
        }
        $bl = strtolower($banner);
        if (strpos($bl, 'username:') !== false || strpos($bl, 'login:') !== false || strpos($bl, 'name:') !== false) {
            $this->TelnetWrite($fp, $user . "\r\n");
            $this->TelnetTransceive($fp, null, array('password:', 'passwd:'), $timeoutMs);
            $this->TelnetWrite($fp, $pass . "\r\n");
            $login = $this->TelnetTransceive($fp, null, array('# ', '$ ', '> ', 'denied', 'incorrect'), $timeoutMs);
            if ($login !== false) {
                $ll = strtolower($login);
                if (strpos($ll, 'denied') !== false || strpos($ll, 'incorrect') !== false) {
                    fclose($fp);
                    $this->SetOnline(false, 'Login abgelehnt (Benutzer/Passwort prüfen).');
                    return false;
                }
            }
        }

        // Die Schlüssel der Eingabe bleiben erhalten - der Aufrufer adressiert die
        // Antworten damit benannt statt über die Position.
        $out = array();
        foreach ($commands as $key => $cmd) {
            $this->TelnetWrite($fp, $cmd . "\r\n");
            $raw = $this->TelnetTransceive($fp, null, array('# ', '$ ', '> '), $timeoutMs);
            if ($raw === false) {
                fclose($fp);
                $this->SetOnline(false, 'Kein Antwort-Prompt auf Befehl: ' . $cmd);
                return false;
            }
            $out[$key] = $this->CleanResponse($raw, $cmd);
        }
        fclose($fp);
        return $out;
    }

    private function TelnetWrite($fp, $s)
    {
        @fwrite($fp, $s);
    }

    // Liest (und beantwortet Telnet-IAC) bis ein Marker auftaucht oder Timeout.
    private function TelnetTransceive($fp, $sendOrNull, $markers, $timeoutMs)
    {
        if ($sendOrNull !== null) {
            $this->TelnetWrite($fp, $sendOrNull);
        }

        $deadline = microtime(true) + (floatval($timeoutMs) / 1000.0);
        $buf = '';
        $gotData = false;

        while (microtime(true) < $deadline) {
            $chunk = @fread($fp, 2048);
            if ($chunk === false) break;

            if ($chunk === '') {
                if (feof($fp)) break;
                usleep(20000);
            } else {
                $gotData = true;
                $buf .= $this->TelnetFilterIAC($fp, $chunk);
            }

            $low = strtolower($buf);
            foreach ($markers as $m) {
                if ($m !== '' && strpos($low, strtolower($m)) !== false) {
                    return $buf;
                }
            }
        }

        return $gotData ? $buf : false;
    }

    // Entfernt Telnet-IAC-Sequenzen und beantwortet Optionsverhandlungen (DO->WONT, WILL->DONT).
    private function TelnetFilterIAC($fp, $data)
    {
        $IAC = 255;
        $out = '';
        $reply = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            $b = ord($data[$i]);
            if ($b === $IAC && ($i + 2) < $len) {
                $cmd = ord($data[$i + 1]);
                $opt = ord($data[$i + 2]);
                if ($cmd === 0xFD) {         // DO -> WONT
                    $reply .= chr($IAC) . chr(0xFC) . chr($opt);
                } elseif ($cmd === 0xFB) {   // WILL -> DONT
                    $reply .= chr($IAC) . chr(0xFE) . chr($opt);
                }
                $i += 3;
                continue;
            }
            if ($b === $IAC && ($i + 1) < $len) {
                $i += 2;
                continue;
            }
            $out .= $data[$i];
            $i++;
        }
        if ($reply !== '') {
            $this->TelnetWrite($fp, $reply);
        }
        return $out;
    }

    // Entfernt ANSI-Codes, CR, den Prompt und den Befehls-Echo.
    private function CleanResponse($raw, $command)
    {
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw); // ANSI
        $s = str_replace("\r", '', $s);

        $lines = explode("\n", $s);
        $clean = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if ($t === trim($command)) continue;                // Befehls-Echo
            if (preg_match('/^~?\s*[#\$>]\s*$/', $t)) continue; // reine Prompt-Zeile
            $t = preg_replace('/\s*~?\s*[#\$>]\s*$/', '', $t);  // Prompt am Zeilenende
            if (strtolower($t) === 'welcome!') continue;
            if ($t !== '') $clean[] = $t;
        }
        return implode("\n", $clean);
    }

    private function SplitLines($s)
    {
        $out = array();
        foreach (explode("\n", $s) as $l) {
            $t = trim($l);
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    private function FirstLine($s)
    {
        $l = $this->SplitLines(strval($s));
        return isset($l[0]) ? $l[0] : '';
    }

    // =========================================================================
    // Polling-Timer / Fast-Poll
    // =========================================================================

    private function UpdatePollingTimers($forceFast)
    {
        $slow = intval($this->ReadPropertyInteger('PollSlow'));
        $fast = intval($this->ReadPropertyInteger('PollFast'));
        if ($slow < 5) $slow = 30;
        if ($fast < 2) $fast = 5;

        $now = time();
        $fastUntil = intval($this->GetBuffer('FastUntil'));
        $useFast = $forceFast || ($fastUntil > $now);

        if ($useFast) {
            $this->SetTimerInterval('PollFast', $fast * 1000);
            $this->SetTimerInterval('PollSlow', 0);
        } else {
            $this->SetTimerInterval('PollFast', 0);
            $this->SetTimerInterval('PollSlow', $slow * 1000);
        }
    }

    private function BumpFastPoll()
    {
        $sec = intval($this->ReadPropertyInteger('FastAfterChange'));
        if ($sec < 5) $sec = 20;
        $this->SetBuffer('FastUntil', strval(time() + $sec));
        $this->UpdatePollingTimers(true);
    }

    // =========================================================================
    // Pending-Logik (UI-Stabilität)
    // =========================================================================

    private function PendingTimeoutSec()
    {
        $t = intval($this->ReadPropertyInteger('PendingTimeout'));
        if ($t < 3) $t = 8;
        return $t;
    }

    private function SetPending($ident, $value)
    {
        $this->SetBuffer('Pend_' . $ident, json_encode(array('v' => intval($value), 'ts' => time())));
    }

    private function GetPending($ident)
    {
        $raw = $this->GetBuffer('Pend_' . $ident);
        if ($raw === null || $raw === '') return false;
        $d = @json_decode($raw, true);
        if (!is_array($d) || !isset($d['v']) || !isset($d['ts'])) return false;
        return $d;
    }

    private function ClearPending($ident)
    {
        $this->SetBuffer('Pend_' . $ident, '');
    }

    private function ApplyIntWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident);
        $dv = intval($deviceValue);
        if ($pending !== false) {
            $pv = intval($pending['v']);
            $age = time() - intval($pending['ts']);
            if ($pv === $dv) {
                $this->ClearPending($ident);
                $this->SetValueIntegerSafe($ident, $dv);
                return;
            }
            if ($age < $this->PendingTimeoutSec()) return;
            $this->ClearPending($ident);
        }
        $this->SetValueIntegerSafe($ident, $dv);
    }

    private function ApplyBoolWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident);
        $dv = intval($deviceValue) ? 1 : 0;
        if ($pending !== false) {
            $pv = intval($pending['v']) ? 1 : 0;
            $age = time() - intval($pending['ts']);
            if ($pv === $dv) {
                $this->ClearPending($ident);
                $this->SetValueBooleanSafe($ident, $dv ? true : false);
                return;
            }
            if ($age < $this->PendingTimeoutSec()) return;
            $this->ClearPending($ident);
        }
        $this->SetValueBooleanSafe($ident, $dv ? true : false);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function SetOnline($online, $err)
    {
        $this->SetValueBooleanSafe('Online', (bool)$online);
        if ($err === null) $err = '';
        $err = strval($err);
        $id = $this->GetIDForIdent('LastError');
        if (GetValueString($id) !== $err) {
            $this->SetValueStringSafe('LastError', $err);
        }
        $this->UpdatePollingTimers(false);
    }

    private function ClearLastError()
    {
        $id = $this->GetIDForIdent('LastError');
        if (GetValueString($id) !== '') {
            $this->SetValueStringSafe('LastError', '');
        }
        $this->SetValueBooleanSafe('Online', true);
    }

    private function TryLock()
    {
        $key = 'PLVB_' . $this->InstanceID;
        for ($i = 0; $i < 10; $i++) {
            if (IPS_SemaphoreEnter($key, 100)) return true;
            IPS_Sleep(20);
        }
        return false;
    }

    private function Unlock()
    {
        $key = 'PLVB_' . $this->InstanceID;
        @IPS_SemaphoreLeave($key);
        $this->UpdatePollingTimers(false);
    }

    // Legt die Instanzprofile (neu) an. Destroy() löscht sie bei jedem
    // Modul-Update - ohne diesen Aufruf wären sie danach weg.
    private function EnsureProfiles()
    {
        $this->RegisterProfileInteger('PLVB.Mode', 'Eye', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Mode', array(
            array(0, 'Normal (Tracking aus)', '', -1),
            array(1, 'Auto-Framing', '', -1),
            array(2, 'Speaker-Tracking', '', -1),
            array(3, 'Presenter-Tracking', '', -1),
            array(4, 'Individuals-Gallery', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.Drive', 'Move', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Drive', array(
            array(1, 'Links',  'ArrowLeft',  -1),
            array(2, 'Rechts', 'ArrowRight', -1),
            array(3, 'Hoch',   'ArrowUp',    -1),
            array(4, 'Runter', 'ArrowDown',  -1),
            array(5, 'Mitte',  'Crosshairs', -1)
        ));
        $this->RegisterProfileInteger('PLVB.Zoom', 'Zoom', '', '', 100, 500, 10);
        $this->RegisterProfileInteger('PLVB.Preset', 'Camera', '', '', 1, 2, 1);
        $this->RegisterProfileInteger('PLVB.Transition', 'Move', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Transition', array(
            array(0, 'Weich (smooth)', '', -1),
            array(1, 'Sofort (immediate)', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.Speed', 'Speedo', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Speed', array(
            array(0, 'Langsam', '', -1),
            array(1, 'Normal', '', -1),
            array(2, 'Schnell', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.WDR', 'Sun', '', '', -7, 7, 1);
        $this->RegisterProfileInteger('PLVB.PowerFreq', 'Lightbulb', '', ' Hz', 0, 0, 0);
        $this->SetAssociations('PLVB.PowerFreq', array(
            array(50, '50 Hz', '', -1),
            array(60, '60 Hz', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.MaxRes', 'Display', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.MaxRes', array(
            array(0, 'Stufe 0', '', -1),
            array(1, 'Stufe 1', '', -1),
            array(2, 'Stufe 2', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.AudioOut', 'Speaker', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.AudioOut', array(
            array(0, 'Lautsprecher (intern)', '', -1),
            array(1, 'HDMI', '', -1),
            array(2, 'Modus 2', '', -1)
        ));
        $this->RegisterProfileInteger('PLVB.Volume', 'Volume', '', '', 0, 0, 0);
        $this->SetAssociations('PLVB.Volume', array(
            array(1, 'Leiser', 'Minus', -1),
            array(2, 'Lauter', 'Plus', -1)
        ));
        $this->RegisterProfileInteger('PLVB.StandbyTime', 'Clock', '', ' Min.', 0, 30, 1);
    }

    private function RegisterProfileInteger($name, $icon, $prefix, $suffix, $min, $max, $step)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
            IPS_SetVariableProfileIcon($name, $icon);
            IPS_SetVariableProfileText($name, $prefix, $suffix);
            if ($max > $min) {
                IPS_SetVariableProfileValues($name, $min, $max, $step);
            }
        }
    }

    private function SetAssociations($profile, $assocs)
    {
        if (!IPS_VariableProfileExists($profile)) return;
        $p = IPS_GetVariableProfile($profile);
        if (isset($p['Associations']) && is_array($p['Associations'])) {
            foreach ($p['Associations'] as $a) {
                IPS_SetVariableProfileAssociation($profile, $a['Value'], '', '', -1);
            }
        }
        for ($i = 0; $i < count($assocs); $i++) {
            IPS_SetVariableProfileAssociation($profile, $assocs[$i][0], $assocs[$i][1], $assocs[$i][2], $assocs[$i][3]);
        }
    }

    private function SetValueBooleanSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        if (GetValueBoolean($id) !== (bool)$value) {
            SetValueBoolean($id, (bool)$value);
        }
    }

    private function SetValueIntegerSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        $v = intval($value);
        if (GetValueInteger($id) !== $v) {
            SetValueInteger($id, $v);
        }
    }

    private function SetValueStringSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        $v = strval($value);
        if (GetValueString($id) !== $v) {
            SetValueString($id, $v);
        }
    }
}
