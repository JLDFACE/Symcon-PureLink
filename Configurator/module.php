<?php

/*
 * PureLink Configurator - IP-Symcon Modul (Typ 4)
 *
 * Sucht PureLink-Geraete im lokalen Netz und legt per Klick Instanzen an.
 * - Paralleler TCP-Connect-Scan (Port 443) ueber das /24-Subnetz.
 * - Modell-Erkennung ueber den Web-Titel (<title>VL-BYOD200</title> / VL-PTZ100).
 * - Zuordnung Modell -> passendes Geraete-Modul (GUID) + Vorbelegung des Hosts.
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, keine globalen Funktionen.
 */

class PureLinkConfigurator extends IPSModule
{
    // GUIDs der Geraete-Module dieser Library
    const GUID_BYOD200 = '{8041FE72-CD5F-45BD-B775-1FE4C5FEDB02}';
    const GUID_PTZ100  = '{30524568-8CA3-4334-955A-AD43B8FCC614}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Subnet', '');   // z.B. "192.168.2" (3-Oktett-Basis); leer = auto
        $this->RegisterPropertyInteger('ScanTimeoutMs', 800);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $values = $this->DiscoverValues();

        $form = array(
            'elements' => array(
                array(
                    'type' => 'ValidationTextBox',
                    'name' => 'Subnet',
                    'caption' => 'Subnetz-Basis (z.B. 192.168.2) - leer = automatisch'
                ),
                array(
                    'type' => 'NumberSpinner',
                    'name' => 'ScanTimeoutMs',
                    'caption' => 'Scan-Timeout je Host (ms)',
                    'minimum' => 200,
                    'maximum' => 3000
                ),
                array(
                    'type' => 'Configurator',
                    'name' => 'Devices',
                    'caption' => 'Gefundene PureLink-Geraete',
                    'rowCount' => 12,
                    'add' => false,
                    'delete' => false,
                    'sort' => array('column' => 'ip', 'direction' => 'ascending'),
                    'columns' => array(
                        array('caption' => 'Modell', 'name' => 'model', 'width' => '160px'),
                        array('caption' => 'Name', 'name' => 'name', 'width' => 'auto'),
                        array('caption' => 'IP', 'name' => 'ip', 'width' => '150px'),
                        array('caption' => 'Status', 'name' => 'status', 'width' => '120px')
                    ),
                    'values' => $values
                )
            ),
            'actions' => array(
                array(
                    'type' => 'Label',
                    'caption' => 'Nach dem Aendern des Subnetzes das Formular neu laden, um erneut zu scannen.'
                )
            ),
            'status' => array()
        );

        return json_encode($form);
    }

    // =========================================================================
    // Discovery
    // =========================================================================

    private function DiscoverValues()
    {
        $base = $this->ResolveSubnetBase();
        if ($base === '') {
            return array(array(
                'model' => '-',
                'name' => 'Subnetz-Basis konnte nicht automatisch ermittelt werden - bitte oben eintragen.',
                'ip' => '',
                'status' => '',
                'instanceID' => 0
            ));
        }

        $timeout = intval($this->ReadPropertyInteger('ScanTimeoutMs'));
        if ($timeout < 200) $timeout = 800;

        $openHosts = $this->ParallelConnectScan($base, 443, $timeout);

        $values = array();
        foreach ($openHosts as $ip) {
            $model = $this->IdentifyModel($ip);
            if ($model === '') continue; // kein PureLink-Geraet

            if ($model === 'VL-BYOD200') {
                $guid = self::GUID_BYOD200;
                $config = array('Host' => $ip, 'Port' => 23);
            } elseif ($model === 'VL-PTZ100') {
                $guid = self::GUID_PTZ100;
                $config = array('Host' => $ip, 'Port' => 23);
            } else {
                continue;
            }

            $instanceID = $this->FindExistingInstance($guid, $ip);

            $values[] = array(
                'model' => $model,
                'name' => $this->FetchDeviceName($ip, $model),
                'ip' => $ip,
                'status' => ($instanceID > 0) ? 'angelegt' : 'neu',
                'instanceID' => $instanceID,
                'create' => array(
                    'moduleID' => $guid,
                    'configuration' => $config
                )
            );
        }

        if (count($values) == 0) {
            $values[] = array(
                'model' => '-',
                'name' => 'Keine PureLink-Geraete in ' . $base . '.0/24 gefunden.',
                'ip' => '',
                'status' => '',
                'instanceID' => 0
            );
        }

        return $values;
    }

    private function ResolveSubnetBase()
    {
        $sub = trim($this->ReadPropertyString('Subnet'));
        if ($sub !== '') {
            // "192.168.2" oder "192.168.2.0/24" -> "192.168.2"
            $sub = preg_replace('#/.*$#', '', $sub);
            $parts = explode('.', $sub);
            if (count($parts) >= 3) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
            }
            return '';
        }

        // Auto: lokale IP des Servers ermitteln
        $ip = @gethostbyname(@gethostname());
        if (is_string($ip) && preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $ip, $m)) {
            if ($m[1] != '127') {
                return $m[1] . '.' . $m[2] . '.' . $m[3];
            }
        }
        return '';
    }

    // Paralleler nicht-blockierender Connect-Scan. Rueckgabe: Liste offener IPs.
    private function ParallelConnectScan($base, $port, $timeoutMs)
    {
        $sockets = array();
        $ipBySock = array();

        for ($i = 1; $i <= 254; $i++) {
            $ip = $base . '.' . $i;
            $sock = @stream_socket_client(
                'tcp://' . $ip . ':' . $port,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($sock !== false) {
                stream_set_blocking($sock, false);
                $key = intval($sock);
                $sockets[$key] = $sock;
                $ipBySock[$key] = $ip;
            }
        }

        $open = array();
        $deadline = microtime(true) + (floatval($timeoutMs) / 1000.0);

        while (!empty($sockets) && microtime(true) < $deadline) {
            $read = null;
            $write = array_values($sockets);
            $except = array_values($sockets);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) break;

            $sec = intval($remaining);
            $usec = intval(($remaining - $sec) * 1000000);

            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n === false) break;
            if ($n === 0) break;

            foreach ($write as $w) {
                $key = intval($w);
                // Verbunden, wenn Peer-Name verfuegbar
                $peer = @stream_socket_get_name($w, true);
                if ($peer !== false && $peer !== '') {
                    if (isset($ipBySock[$key])) $open[] = $ipBySock[$key];
                }
                @fclose($w);
                unset($sockets[$key]);
            }
            foreach ($except as $e) {
                $key = intval($e);
                @fclose($e);
                unset($sockets[$key]);
            }
        }

        foreach ($sockets as $s) {
            @fclose($s);
        }

        return array_values(array_unique($open));
    }

    // Ermittelt das Modell ueber den Web-Titel. Rueckgabe: 'VL-BYOD200' | 'VL-PTZ100' | ''.
    private function IdentifyModel($ip)
    {
        $html = $this->HttpGet('https://' . $ip . '/');
        if ($html === false || $html === '') return '';

        if (stripos($html, 'VL-BYOD200') !== false) return 'VL-BYOD200';
        if (stripos($html, 'VL-PTZ100') !== false) return 'VL-PTZ100';

        // Fallback: Titel extrahieren
        if (preg_match('/<title>\s*([^<]+?)\s*<\/title>/i', $html, $m)) {
            $t = trim($m[1]);
            if (stripos($t, 'BYOD200') !== false) return 'VL-BYOD200';
            if (stripos($t, 'PTZ100') !== false) return 'VL-PTZ100';
        }
        return '';
    }

    private function FetchDeviceName($ip, $model)
    {
        // Der Web-Titel entspricht dem Modell; ein spezifischerer Name kommt spaeter
        // aus der jeweiligen Instanz. Hier reicht das Modell als Anzeige.
        return $model;
    }

    private function HttpGet($url)
    {
        $timeout = intval($this->ReadPropertyInteger('ScanTimeoutMs'));
        if ($timeout < 200) $timeout = 800;

        if (!function_exists('curl_init')) return false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, max(1500, $timeout * 2));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) return false;
        return $body;
    }

    // Sucht eine bestehende Instanz des Moduls mit passendem Host. Rueckgabe: InstanceID oder 0.
    private function FindExistingInstance($moduleGuid, $ip)
    {
        $ids = @IPS_GetInstanceListByModuleID($moduleGuid);
        if (!is_array($ids)) return 0;

        foreach ($ids as $id) {
            $host = @IPS_GetProperty($id, 'Host');
            if ($host !== false && strval($host) === strval($ip)) {
                return intval($id);
            }
        }
        return 0;
    }
}
