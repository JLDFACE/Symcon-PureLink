<?php

/*
 * PureLink VL-PTZ100 (Compact 4K Dual-Lens PTZ Camera) - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, keine globalen Funktionen ausserhalb der Klasse.
 * - Kein IO: Steuerung ueber die dokumentierte Telnet-CLI (Port 23) mit kurzlebiger Verbindung
 *   pro Aktion (Login-Handshake + Befehl + Antwort). Login: Benutzer 'admin', Passwort LEER.
 *   Befehle: gbconfig (Konfig/Steuerung), gbcontrol (Aktionen), Query via 'gbconfig -s <item>'.
 * - Polling: Slow/Fast Timer, FastAfterChange nach Aktionen.
 * - Stabilitaet: Semaphore/Lock, niemals Fatals; Online/LastError nur bei Aenderung.
 * - UI-Stabilitaet: Pending-Logik fuer gesetzte Sollwerte.
 *
 * WICHTIG: Manuelles Pan/Tilt/Zoom/Preset/Focus geht nur bei Tracking-Modus 0 (off).
 * Bei aktivem Tracking liefert das Geraet negative Returncodes (-2). Optional kann das
 * Modul vor solchen Aktionen automatisch auf Modus 0 schalten (Property TrackingOffOnManual).
 */

class PureLinkPTZ100 extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ---- Properties ----
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 23);
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('TimeoutMs', 4000);
        $this->RegisterPropertyBoolean('TrackingOffOnManual', true);

        $this->RegisterPropertyInteger('PollSlow', 20);
        $this->RegisterPropertyInteger('PollFast', 3);
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
        $this->RegisterProfileInteger('PLPTZ.Mode', 'Eye', '', '', 0, 0, 0);
        $this->SetAssociations('PLPTZ.Mode', array(
            array(0, 'Tracking aus (manuell)', '', -1),
            array(1, 'Auto-Framing', '', -1),
            array(2, 'Speaker-Tracking', '', -1),
            array(3, 'Presenter-Tracking', '', -1),
            array(4, 'Individuals-Gallery', '', -1)
        ));
        $this->RegisterVariableInteger('Mode', 'Kamera-Modus', 'PLPTZ.Mode', 50);
        $this->EnableAction('Mode');

        $this->RegisterProfileInteger('PLPTZ.Zoom', 'Zoom', '', 'x', 100, 400, 10);
        $this->RegisterVariableInteger('Zoom', 'Zoom (100=1x)', 'PLPTZ.Zoom', 60);
        $this->EnableAction('Zoom');

        $this->RegisterProfileInteger('PLPTZ.Preset', 'Camera', '', '', 1, 9, 1);
        $this->RegisterVariableInteger('Preset', 'Preset (Laden)', 'PLPTZ.Preset', 70);
        $this->EnableAction('Preset');

        $this->RegisterVariableBoolean('Autofocus', 'Autofokus', '~Switch', 80);
        $this->EnableAction('Autofocus');

        $this->RegisterVariableBoolean('HDMIOut', 'HDMI-Ausgang', '~Switch', 90);
        $this->EnableAction('HDMIOut');

        $this->RegisterVariableBoolean('Privacy', 'Privacy-Position', '~Switch', 100);
        $this->EnableAction('Privacy');

        // ---- Timers ----
        $this->RegisterTimer('PollSlow', 0, 'PLPTZ_PollNow($_IPS["TARGET"]);');
        $this->RegisterTimer('PollFast', 0, 'PLPTZ_PollNow($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // phymaxzoom abfragen und Zoom-Profil anpassen (best-effort)
        $host = trim($this->ReadPropertyString('Host'));
        if ($host != '') {
            $mz = $this->Cli('gbconfig -s camera-phymaxzoom');
            if ($mz !== false) {
                $max = intval(trim($mz));
                if ($max >= 100 && $max <= 10000) {
                    if (IPS_VariableProfileExists('PLPTZ.Zoom')) {
                        IPS_SetVariableProfileValues('PLPTZ.Zoom', 100, $max, 10);
                    }
                }
            }
        }

        $this->UpdatePollingTimers(false);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Mode') {
            if ($this->SetCameraMode(intval($Value))) {
                $this->SetPending('Mode', intval($Value));
                $this->SetValueIntegerSafe('Mode', intval($Value));
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'Zoom') {
            if ($this->SetZoom(intval($Value))) {
                $this->SetPending('Zoom', intval($Value));
                $this->SetValueIntegerSafe('Zoom', intval($Value));
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'Preset') {
            if ($this->PresetLoad(intval($Value))) {
                $this->SetValueIntegerSafe('Preset', intval($Value));
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'Autofocus') {
            if ($this->SetAutofocus((bool)$Value)) {
                $this->SetPending('Autofocus', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('Autofocus', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'HDMIOut') {
            if ($this->SetHDMIOut((bool)$Value)) {
                $this->SetPending('HDMIOut', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('HDMIOut', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'Privacy') {
            // Toggle-Befehl; Zielzustand folgt dem Schalter
            if ($this->PrivacyToggle()) {
                $this->SetValueBooleanSafe('Privacy', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
    }

    // =========================================================================
    // Public API (Form-Buttons / Skripte)
    // =========================================================================

    // dir: 'r' | 'l' | 'u' | 'd'
    public function Move($dir)
    {
        $d = strtolower(trim(strval($dir)));
        if (!in_array($d, array('r', 'l', 'u', 'd'))) {
            $this->SetOnline(false, 'Ungueltige Richtung: ' . $dir);
            return false;
        }
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --camera-autocoord ' . $d);
        return $this->EvalReturn($r);
    }

    public function SetCameraMode($mode)
    {
        $m = intval($mode);
        if ($m < 0 || $m > 4) return false;
        $r = $this->Cli('gbconfig --camera-mode ' . $m);
        return $this->EvalReturn($r);
    }

    public function SetZoom($zoom)
    {
        $z = intval($zoom);
        if ($z < 100) $z = 100;
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --camera-zoom ' . $z);
        return $this->EvalReturn($r);
    }

    public function PresetSave($no)
    {
        $n = intval($no);
        if ($n < 1 || $n > 9) return false;
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --camera-savecoord ' . $n);
        return $this->EvalReturn($r);
    }

    public function PresetLoad($no)
    {
        $n = intval($no);
        if ($n < 1 || $n > 9) return false;
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --camera-loadcoord ' . $n);
        return $this->EvalReturn($r);
    }

    public function ResetPTZ()
    {
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --reset-camera-ptz');
        return $this->EvalReturn($r);
    }

    public function SetAutofocus($enable)
    {
        $r = $this->Cli('gbconfig --camera-autofocus ' . ($enable ? '1' : '0'));
        return $this->EvalReturn($r);
    }

    public function SetFocus($value)
    {
        $v = intval($value);
        if (!$this->EnsureManual()) return false;
        $r = $this->Cli('gbconfig --camera-focus ' . $v);
        return $this->EvalReturn($r);
    }

    public function SetHDMIOut($enable)
    {
        $r = $this->Cli('gbconfig --hdmi-out ' . ($enable ? '1' : '0'));
        return $this->EvalReturn($r);
    }

    public function PrivacyToggle()
    {
        $r = $this->Cli('gbcontrol --privacy-position');
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
        $r = $this->Cli('gbcontrol --device-info');
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

        $info = $this->CliRaw('gbcontrol --device-info');
        if ($info === false) {
            $this->SetOnline(false, 'Keine Antwort (device-info).');
            $this->Unlock();
            return;
        }
        $this->SetOnline(true, '');

        // device-info: Zeile1 Modell/Name, Zeile2 Firmware, Zeile3 Build
        $lines = $this->SplitLines($info);
        if (isset($lines[0])) $this->SetValueStringSafe('DeviceName', $lines[0]);
        if (isset($lines[1])) $this->SetValueStringSafe('Firmware', $lines[1]);

        // Modus
        $mode = $this->CliRaw('gbconfig -s camera-mode');
        if ($mode !== false && $mode !== '') {
            $this->ApplyIntWithPending('Mode', intval(trim($mode)));
        }

        // Autofokus
        $af = $this->CliRaw('gbconfig -s camera-autofocus');
        if ($af !== false && $af !== '') {
            $this->ApplyBoolWithPending('Autofocus', $this->YesNoToInt($af));
        }

        // HDMI-Ausgang
        $hd = $this->CliRaw('gbconfig -s hdmi-out');
        if ($hd !== false && $hd !== '') {
            $this->ApplyBoolWithPending('HDMIOut', $this->YesNoToInt($hd));
        }

        $this->SetBuffer('LastStatusTs', strval(time()));
        $this->Unlock();
    }

    // =========================================================================
    // Helpers: Modus-Handling / Returncodes
    // =========================================================================

    private function EnsureManual()
    {
        if (!$this->ReadPropertyBoolean('TrackingOffOnManual')) return true;

        $mode = $this->CliRaw('gbconfig -s camera-mode');
        if ($mode !== false && intval(trim($mode)) !== 0) {
            $r = $this->Cli('gbconfig --camera-mode 0');
            if ($r === false) return false;
            $this->SetPending('Mode', 0);
            $this->SetValueIntegerSafe('Mode', 0);
        }
        return true;
    }

    // Bewertet die Antwort eines gbconfig-Steuerbefehls. Negative Zahl = Fehler.
    private function EvalReturn($resp)
    {
        if ($resp === false) return false;

        $t = trim($resp);
        // Reiner negativer Returncode?
        if (preg_match('/^-?\d+$/', $t)) {
            $n = intval($t);
            if ($n < 0) {
                $this->SetOnline(false, 'Befehl abgelehnt (Code ' . $n . '): ' . $this->ReturnCodeText($n));
                return false;
            }
        }
        $this->ClearLastError();
        return true;
    }

    private function ReturnCodeText($n)
    {
        switch (intval($n)) {
            case -1: return 'Idle-Zustand';
            case -2: return 'Tracking-Modus ist nicht "off"';
            case -3: return 'Preset existiert nicht';
            case -4: return 'Ungueltiger Parameter';
            case -5: return 'Preset-Nummer-Fehler';
            case -6: return 'Autofokus ist an';
            default: return 'unbekannt';
        }
    }

    private function YesNoToInt($s)
    {
        $t = strtolower(trim($s));
        if ($t === 'y' || $t === '1' || $t === 'yes') return 1;
        return 0;
    }

    // =========================================================================
    // Telnet-Transport
    // =========================================================================

    // Fuehrt einen CLI-Befehl aus (Login + Befehl). Rueckgabe: bereinigte Antwort oder false.
    // Kapselt Lock NICHT (Aufrufer entscheidet); nutzt eigenen Lock nur wenn frei.
    private function Cli($command)
    {
        $ownLock = $this->TryLock();
        $res = $this->CliRaw($command);
        if ($ownLock) $this->Unlock();
        return $res;
    }

    // Wie Cli(), aber ohne eigene Lock-Verwaltung (fuer Aufruf innerhalb Poll()).
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

        // Login-Handshake
        if ($this->TelnetTransceive($fp, null, array('username:', 'login:', 'name:'), $timeoutMs) === false) {
            fclose($fp);
            $this->SetOnline(false, 'Kein Login-Prompt.');
            return false;
        }
        $this->TelnetWrite($fp, $user . "\r\n");

        if ($this->TelnetTransceive($fp, null, array('password:', 'passwd:'), $timeoutMs) === false) {
            fclose($fp);
            $this->SetOnline(false, 'Kein Passwort-Prompt.');
            return false;
        }
        $this->TelnetWrite($fp, $pass . "\r\n");

        $login = $this->TelnetTransceive($fp, null, array('# ', '$ ', '> ', 'denied', 'incorrect', 'welcome'), $timeoutMs);
        if ($login === false) {
            fclose($fp);
            $this->SetOnline(false, 'Login-Timeout.');
            return false;
        }
        $ll = strtolower($login);
        if (strpos($ll, 'denied') !== false || strpos($ll, 'incorrect') !== false) {
            fclose($fp);
            $this->SetOnline(false, 'Login abgelehnt (Benutzer/Passwort pruefen).');
            return false;
        }
        // Falls Prompt noch nicht erschien (nur "Welcome"), kurz nachlesen
        if (strpos($login, '# ') === false && strpos($login, '$ ') === false) {
            $this->TelnetTransceive($fp, null, array('# ', '$ ', '> '), 1500);
        }

        // WICHTIG: Die PTZ100-CLI nimmt direkt nach dem Login-Prompt noch keine
        // Befehle an - ein sofort gesendetes Kommando wird still verschluckt (kein
        // Echo, keine Antwort). Empirisch ist ab ~700ms alles stabil; wir warten
        // grosszuegig, bevor der (einmalige) Befehl gesendet wird. Eine reine
        // Leerzeile "aufzuwecken" hilft nicht - nur ein Kommando mit ausreichendem
        // zeitlichem Abstand zum Prompt kommt durch.
        IPS_Sleep(1000);

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

    // Liest (und beantwortet Telnet-IAC) bis ein Marker (lowercase) auftaucht oder Timeout.
    // Rueckgabe: gesammelte (IAC-bereinigte) Zeichenkette, oder false bei Timeout ohne Daten.
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
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) {
                    // weiter warten bis Deadline
                }
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
                if ($cmd === 0xFD) { // DO -> WONT
                    $reply .= chr($IAC) . chr(0xFC) . chr($opt);
                } elseif ($cmd === 0xFB) { // WILL -> DONT
                    $reply .= chr($IAC) . chr(0xFE) . chr($opt);
                }
                // andere (WONT/DONT/SB...) ignorieren
                $i += 3;
                continue;
            }
            if ($b === $IAC && ($i + 1) < $len) {
                $i += 2; // zweistellige IAC-Sequenz ueberspringen
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

    // Entfernt ANSI-Codes, CR, den Prompt und (falls vorhanden) den Befehls-Echo.
    private function CleanResponse($raw, $command)
    {
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw); // ANSI
        $s = str_replace("\r", '', $s);

        $lines = explode("\n", $s);
        $clean = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if ($t === trim($command)) continue;           // Befehls-Echo
            if (preg_match('/^~?\s*[#\$>]\s*$/', $t)) continue; // Prompt-Zeile
            // Prompt am Zeilenende abtrennen
            $t = preg_replace('/\s*~?\s*[#\$>]\s*$/', '', $t);
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

    // =========================================================================
    // Polling-Timer / Fast-Poll
    // =========================================================================

    private function UpdatePollingTimers($forceFast)
    {
        $slow = intval($this->ReadPropertyInteger('PollSlow'));
        $fast = intval($this->ReadPropertyInteger('PollFast'));
        if ($slow < 5) $slow = 20;
        if ($fast < 2) $fast = 3;

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
        $key = 'PLPTZ_' . $this->InstanceID;
        for ($i = 0; $i < 10; $i++) {
            if (IPS_SemaphoreEnter($key, 100)) return true;
            IPS_Sleep(20);
        }
        return false;
    }

    private function Unlock()
    {
        $key = 'PLPTZ_' . $this->InstanceID;
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
