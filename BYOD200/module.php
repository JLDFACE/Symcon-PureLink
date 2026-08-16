<?php

/*
 * PureLink VL-BYOD200 (4K60 BYOD/BYOM Presentation Switcher) - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, keine globalen Funktionen ausserhalb der Klasse.
 * - Kein IO: Steuerung ueber die dokumentierte Telnet-CLI (Standard-Port 24) mit kurzlebiger
 *   Verbindung pro Aktion. Befehle: gbconfig / gbcontrol / gblayout, Query via 'gbconfig -s <item>'.
 *   Login ist optional: falls das Geraet 'username:'/'password:' anfragt, werden die konfigurierten
 *   Zugangsdaten gesendet; ansonsten wird direkt der Prompt erwartet.
 * - Polling: Slow/Fast Timer, FastAfterChange nach Aktionen.
 * - Stabilitaet: Semaphore/Lock, niemals Fatals; Online/LastError nur bei Aenderung.
 * - UI-Stabilitaet: Pending-Logik fuer gesetzte Sollwerte (Quelle/DualView/Guide).
 *
 * Quellnamen (VideoName) laut API: usbc, hdmi (wired); airplay..., miracast..., dongle...,
 * chromecast... (BYOD); guide = Guide-Screen.
 */

class PureLinkBYOD200 extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ---- Properties ----
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 23);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('TimeoutMs', 4000);

        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('FastAfterChange', 20);
        $this->RegisterPropertyInteger('PendingTimeout', 8);

        // ---- State / Buffers ----
        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('LastStatusTs', '0');

        // ---- Diagnose-Variablen ----
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        $this->DisableAction('Online');

        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 20);
        $this->RegisterVariableString('DeviceName', 'Geraetename', '~TextBox', 30);
        $this->RegisterVariableString('Firmware', 'Firmware', '~TextBox', 40);

        // ---- Steuer-/Status-Variablen ----
        $this->RegisterProfileInteger('PLBYOD.Source', 'Display', '', '', 0, 0, 0);
        $this->SetAssociations('PLBYOD.Source', array(
            array(0, 'USB-C IN', '', -1),
            array(1, 'HDMI IN', '', -1),
            array(2, 'Drahtlos (BYOD)', '', -1),
            array(3, 'Getrennt', '', -1)
        ));
        $this->RegisterVariableInteger('Source', 'Aktive Quelle (Vollbild)', 'PLBYOD.Source', 50);
        $this->EnableAction('Source');

        // Bei drahtloser Uebertragung (AirPlay/Miracast/Chromecast/Dongle) meldet
        // das Geraet einen geraetespezifischen Sendernamen (z.B. "VL-DGL200-1").
        $this->RegisterVariableString('WirelessName', 'Drahtlose Quelle', '~TextBox', 55);

        $this->RegisterVariableBoolean('DualView', 'Multiview', '~Switch', 60);
        $this->EnableAction('DualView');

        $this->RegisterVariableBoolean('GuideScreen', 'Guide-Screen', '~Switch', 70);
        $this->EnableAction('GuideScreen');

        $this->RegisterVariableString('SignalUSBC', 'Signal USB-C IN', '~TextBox', 80);
        $this->RegisterVariableString('SignalHDMI', 'Signal HDMI IN', '~TextBox', 90);

        // ---- Timers ----
        $this->RegisterTimer('PollSlow', 0, 'PLBYOD_PollNow($_IPS["TARGET"]);');
        $this->RegisterTimer('PollFast', 0, 'PLBYOD_PollNow($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->UpdatePollingTimers(false);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Source') {
            // Nur die physischen Eingaenge sind aktiv waehlbar. Werte 2 (Drahtlos)
            // und 3 (Getrennt) sind reine Statuszustaende und werden ignoriert.
            $v = intval($Value);
            if ($v !== 0 && $v !== 1) {
                return;
            }
            if ($this->SelectSource($v)) {
                $this->SetPending('Source', $v);
                $this->SetValueIntegerSafe('Source', $v);
                $this->SetValueStringSafe('WirelessName', '');
                $this->SetPending('GuideScreen', 0);
                $this->SetValueBooleanSafe('GuideScreen', false);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'DualView') {
            if ($this->SetDualView((bool)$Value)) {
                $this->SetPending('DualView', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('DualView', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'GuideScreen') {
            if ($this->SetGuideScreen((bool)$Value)) {
                $this->SetPending('GuideScreen', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('GuideScreen', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
    }

    // =========================================================================
    // Public API (Form-Buttons / Skripte)
    // =========================================================================

    // Vollbild auf eine Quelle schalten. $index: 0 = USB-C IN, 1 = HDMI IN.
    public function SelectSource($index)
    {
        $name = $this->SourceNameByIndex(intval($index));
        if ($name === '') {
            $this->SetOnline(false, 'Unbekannter Quellindex: ' . intval($index));
            return false;
        }
        $r = $this->Cli('gbconfig --video-source ' . $name);
        if ($r === false) return false;
        $this->ClearLastError();
        return true;
    }

    public function SetDualView($enable)
    {
        $r = $this->Cli('gbconfig --multiview ' . ($enable ? 'y' : 'n'));
        if ($r === false) return false;
        $this->ClearLastError();
        return true;
    }

    // true  -> Guide-Screen anzeigen; false -> zurueck auf aktuell gewaehlte Quelle (Vollbild)
    public function SetGuideScreen($enable)
    {
        if ($enable) {
            $r = $this->Cli('gbconfig --video-source guide');
        } else {
            $idx = GetValueInteger($this->GetIDForIdent('Source'));
            $name = $this->SourceNameByIndex($idx);
            if ($name === '') $name = 'usbc';
            $r = $this->Cli('gbconfig --video-source ' . $name);
        }
        if ($r === false) return false;
        $this->ClearLastError();
        return true;
    }

    // Multiview-Layout wechseln (LayoutID z.B. 0x100 = Vollbild, 0x101 = SidebySide_DualView).
    public function SetLayout($layoutIdOrName)
    {
        $r = $this->Cli('gblayout --set ' . strval($layoutIdOrName));
        if ($r === false) return false;
        $this->ClearLastError();
        return true;
    }

    public function Reboot()
    {
        $r = $this->Cli('gbcontrol --reboot');
        if ($r === false) return false;
        $this->ClearLastError();
        return true;
    }

    // Generischer CLI-Aufruf fuer Skripte/Diagnose. Rueckgabe: Antworttext oder ''.
    public function SendCommand($rawCommand)
    {
        $r = $this->Cli(strval($rawCommand));
        if ($r === false) return '';
        return $r;
    }

    public function TestConnection()
    {
        $r = $this->Cli('gbconfig -s device-info');
        if ($r === false) {
            $this->SetOnline(false, 'Keine Antwort auf device-info.');
            return false;
        }
        $this->SetOnline(true, '');
        $this->PollNow();
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
            $this->SetOnline(false, 'Konfiguration unvollstaendig (Host).');
            $this->Unlock();
            return;
        }

        $info = $this->CliRaw('gbconfig -s device-info');
        if ($info === false) {
            $this->SetOnline(false, 'Keine Antwort (device-info).');
            $this->Unlock();
            return;
        }
        $this->SetOnline(true, '');

        // device-info: Zeile1 Modell, Zeile2 Firmware
        $lines = $this->SplitLines($info);
        if (isset($lines[1])) $this->SetValueStringSafe('Firmware', $lines[1]);

        $name = $this->CliRaw('gbconfig -s name');
        if ($name !== false && trim($name) !== '') {
            $this->SetValueStringSafe('DeviceName', trim($name));
        }

        // Aktive Ausgabe / Guide
        $vs = $this->CliRaw('gbconfig -s video-source');
        if ($vs !== false) {
            $this->ApplyVideoSource($vs);
        }

        // Multiview
        $mv = $this->CliRaw('gbconfig -s multiview');
        if ($mv !== false && trim($mv) !== '') {
            $this->ApplyBoolWithPending('DualView', $this->YesNoToInt($mv));
        }

        // Eingangssignale (rohes "invalid"/"none" -> "Nicht verbunden")
        $u = $this->CliRaw('gbconfig -s input-video usbc');
        if ($u !== false) $this->SetValueStringSafe('SignalUSBC', $this->PrettySignal($u));
        $h = $this->CliRaw('gbconfig -s input-video hdmi');
        if ($h !== false) $this->SetValueStringSafe('SignalHDMI', $this->PrettySignal($h));

        $this->SetBuffer('LastStatusTs', strval(time()));
        $this->Unlock();
    }

    // Wertet 'gbconfig -s video-source' aus: { guide | disconnected | <name> [<name>...] }
    // <name> ist bei drahtlosen Quellen ein geraetespezifischer Sendername
    // (z.B. "VL-DGL200-1", AirPlay-/Miracast-/Chromecast-Geraetename).
    private function ApplyVideoSource($resp)
    {
        $raw = trim($this->FirstLine($resp));
        $t = strtolower($raw);
        if ($t === '') return;

        if (strpos($t, 'guide') === 0) {
            $this->ApplyBoolWithPending('GuideScreen', 1);
            return;
        }
        $this->ApplyBoolWithPending('GuideScreen', 0);

        if (strpos($t, 'disconnected') === 0) {
            $this->SetValueStringSafe('WirelessName', '');
            $this->ApplyIntWithPending('Source', 3); // Getrennt
            return;
        }

        // Physische Eingaenge zuerst: erstes usbc/hdmi-Token = fuehrende Quelle
        $tokens = preg_split('/\s+/', $t);
        $rawTokens = preg_split('/\s+/', $raw);
        foreach ($tokens as $tok) {
            $idx = $this->IndexBySourceName($tok);
            if ($idx >= 0) {
                $this->SetValueStringSafe('WirelessName', '');
                $this->ApplyIntWithPending('Source', $idx);
                return;
            }
        }

        // Kein usbc/hdmi -> drahtlose/BYOD-Quelle. Originalnamen (Case erhalten) anzeigen.
        $name = isset($rawTokens[0]) ? $rawTokens[0] : $raw;
        $this->SetValueStringSafe('WirelessName', $name);
        $this->ApplyIntWithPending('Source', 2); // Drahtlos (BYOD)
    }

    // Rohes Eingangssignal menschenlesbar machen: "invalid"/"none"/leer -> "Nicht verbunden".
    private function PrettySignal($resp)
    {
        $t = trim($this->FirstLine($resp));
        $l = strtolower($t);
        if ($t === '' || $l === 'invalid' || $l === 'none') {
            return 'Nicht verbunden';
        }
        return $t;
    }

    // =========================================================================
    // Quellen-Mapping
    // =========================================================================

    private function SourceNameByIndex($index)
    {
        $map = array(0 => 'usbc', 1 => 'hdmi');
        $i = intval($index);
        return isset($map[$i]) ? $map[$i] : '';
    }

    private function IndexBySourceName($name)
    {
        $n = strtolower(trim($name));
        if ($n === 'usbc') return 0;
        if ($n === 'hdmi') return 1;
        return -1;
    }

    private function YesNoToInt($s)
    {
        $t = strtolower(trim($this->FirstLine($s)));
        if ($t === 'y' || $t === '1' || $t === 'yes') return 1;
        return 0;
    }

    // =========================================================================
    // Telnet-Transport
    // =========================================================================

    private function Cli($command)
    {
        $ownLock = $this->TryLock();
        $res = $this->CliRaw($command);
        if ($ownLock) $this->Unlock();
        return $res;
    }

    private function CliRaw($command)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        if ($host == '' || $port < 1 || $port > 65535) {
            $this->SetOnline(false, 'Konfiguration unvollstaendig (Host/Port).');
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

        // Banner lesen: entweder Login-Prompt oder direkt Shell-Prompt
        $banner = $this->TelnetTransceive($fp, null, array('username:', 'login:', 'name:', '# ', '$ ', '> '), $timeoutMs);
        if ($banner === false) {
            fclose($fp);
            $this->SetOnline(false, 'Kein Prompt/Banner.');
            return false;
        }
        $bl = strtolower($banner);
        if (strpos($bl, 'username:') !== false || strpos($bl, 'login:') !== false || strpos($bl, 'name:') !== false) {
            // Login noetig
            $this->TelnetWrite($fp, $user . "\r\n");
            $this->TelnetTransceive($fp, null, array('password:', 'passwd:'), $timeoutMs);
            $this->TelnetWrite($fp, $pass . "\r\n");
            $login = $this->TelnetTransceive($fp, null, array('# ', '$ ', '> ', 'denied', 'incorrect'), $timeoutMs);
            if ($login !== false) {
                $ll = strtolower($login);
                if (strpos($ll, 'denied') !== false || strpos($ll, 'incorrect') !== false) {
                    fclose($fp);
                    $this->SetOnline(false, 'Login abgelehnt.');
                    return false;
                }
            }
        }

        // Befehl senden
        $this->TelnetWrite($fp, $command . "\r\n");
        $raw = $this->TelnetTransceive($fp, null, array('# ', '$ ', '> '), $timeoutMs);
        fclose($fp);

        if ($raw === false) {
            $this->SetOnline(false, 'Kein Antwort-Prompt auf Befehl.');
            return false;
        }
        return $this->CleanResponse($raw, $command);
    }

    private function TelnetWrite($fp, $s)
    {
        @fwrite($fp, $s);
    }

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
                if ($cmd === 0xFD) {
                    $reply .= chr($IAC) . chr(0xFC) . chr($opt);
                } elseif ($cmd === 0xFB) {
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

    private function CleanResponse($raw, $command)
    {
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw);
        $s = str_replace("\r", '', $s);

        $lines = explode("\n", $s);
        $clean = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if ($t === trim($command)) continue;
            if (preg_match('/^~?\s*[#\$>]\s*$/', $t)) continue;
            $t = preg_replace('/\s*~?\s*[#\$>]\s*$/', '', $t);
            if (strtolower($t) === 'welcome!') continue;
            if (strtolower($t) === 'none') continue;
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
        $lines = $this->SplitLines($s);
        return isset($lines[0]) ? $lines[0] : '';
    }

    // =========================================================================
    // Polling-Timer / Fast-Poll
    // =========================================================================

    private function UpdatePollingTimers($forceFast)
    {
        $slow = intval($this->ReadPropertyInteger('PollSlow'));
        $fast = intval($this->ReadPropertyInteger('PollFast'));
        if ($slow < 5) $slow = 15;
        if ($fast < 2) $fast = 2;

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
    // Pending-Logik (UI-Stabilitaet)
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

    private function ExpirePendingOnly($ident)
    {
        $pending = $this->GetPending($ident);
        if ($pending === false) return;
        if ((time() - intval($pending['ts'])) >= $this->PendingTimeoutSec()) {
            $this->ClearPending($ident);
        }
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
        $key = 'PLBYOD_' . $this->InstanceID;
        for ($i = 0; $i < 10; $i++) {
            if (IPS_SemaphoreEnter($key, 100)) return true;
            IPS_Sleep(20);
        }
        return false;
    }

    private function Unlock()
    {
        $key = 'PLBYOD_' . $this->InstanceID;
        @IPS_SemaphoreLeave($key);
        $this->UpdatePollingTimers(false);
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
