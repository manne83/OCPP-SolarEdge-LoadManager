<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DreameHomeClient.php';

class DreameRobot extends IPSModuleStrict
{
    private const STATUS_MISSING_CREDENTIALS = 200;
    private const STATUS_LOGIN_FAILED = 201;
    private const STATUS_DEVICE_NOT_FOUND = 202;
    private const STATUS_COMMUNICATION_ERROR = 203;

    private const COMMAND_NONE = 0;
    private const COMMAND_START = 1;
    private const COMMAND_PAUSE = 2;
    private const COMMAND_DOCK = 3;
    private const COMMAND_STOP = 4;
    private const COMMAND_LOCATE = 5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('Country', 'de');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterAttributeString('AccountFingerprint', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAt', 0);
        $this->RegisterAttributeString('TenantID', '000000');
        $this->RegisterAttributeString('UserID', '');
        $this->RegisterAttributeString('ResolvedDeviceID', '');
        $this->RegisterAttributeInteger('FailureCount', 0);

        $this->RegisterTimer('UpdateTimer', 0, "DRM_Update(\$_IPS['TARGET']);");

        // Custom profiles must exist before variables referencing them are registered.
        $this->registerProfiles();

        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), '~Switch', 10);
        $this->RegisterVariableString('DeviceName', $this->Translate('Device name'), '', 20);
        $this->RegisterVariableString('Model', $this->Translate('Model'), '', 30);
        $this->RegisterVariableInteger('Battery', $this->Translate('Battery'), '~Battery.100', 40);
        $this->RegisterVariableString('State', $this->Translate('State'), '', 50);
        $this->RegisterVariableBoolean('Charging', $this->Translate('Charging'), '~Switch', 60);
        $this->RegisterVariableInteger('ErrorCode', $this->Translate('Error code'), '', 70);
        $this->RegisterVariableInteger('CleaningTime', $this->Translate('Cleaning time'), 'DRM.Minutes', 80);
        $this->RegisterVariableFloat('CleanedArea', $this->Translate('Cleaned area'), 'DRM.Area', 90);
        $this->RegisterVariableInteger('StatusCode', 'Status code', '', 100);
        $this->RegisterVariableInteger('TaskStatus', 'Task status', '', 110);
        $this->RegisterVariableInteger('CleaningMode', $this->Translate('Cleaning mode'), 'DRM.CleaningMode', 120);
        $this->EnableAction('CleaningMode');
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last update'), '~UnixTimestamp', 130);
        $this->RegisterVariableInteger('Command', $this->Translate('Command'), 'DRM.Command', 140);
        $this->EnableAction('Command');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerProfiles();

        $fingerprint = hash(
            'sha256',
            strtolower(trim($this->ReadPropertyString('Username'))) . '|' .
            strtolower(trim($this->ReadPropertyString('Country')))
        );
        if ($this->ReadAttributeString('AccountFingerprint') !== $fingerprint) {
            $this->clearSession();
            $this->WriteAttributeString('ResolvedDeviceID', '');
            $this->WriteAttributeString('AccountFingerprint', $fingerprint);
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (trim($this->ReadPropertyString('Username')) === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(self::STATUS_MISSING_CREDENTIALS);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', $this->getConfiguredInterval() * 1000);
        $this->Update();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'CleaningMode') {
            $this->SetCleaningMode((int) $Value);
            return;
        }

        if ($Ident !== 'Command') {
            throw new InvalidArgumentException('Unknown action ident');
        }

        try {
            switch ((int) $Value) {
                case self::COMMAND_START:
                    $this->Start();
                    break;
                case self::COMMAND_PAUSE:
                    $this->Pause();
                    break;
                case self::COMMAND_DOCK:
                    $this->ReturnToDock();
                    break;
                case self::COMMAND_STOP:
                    $this->Stop();
                    break;
                case self::COMMAND_LOCATE:
                    $this->Locate();
                    break;
                case self::COMMAND_NONE:
                    break;
                default:
                    throw new InvalidArgumentException('Unknown robot command');
            }
        } finally {
            $this->SetValue('Command', self::COMMAND_NONE);
        }
    }

    public function Update(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        try {
            $client = $this->createPreparedClient();
            $properties = $client->getBasicState();
            $this->saveSession($client);
            $this->applyState($properties, $client);
            $this->SetValue('Online', true);
            $this->SetValue('LastUpdate', time());
            $this->WriteAttributeInteger('FailureCount', 0);
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('UpdateTimer', $this->getConfiguredInterval() * 1000);
            return true;
        } catch (Throwable $exception) {
            $this->SetValue('Online', false);
            $this->setFailureStatus($exception);
            $this->increaseBackoff();
            $this->SendDebug('Update failed', $exception->getMessage(), 0);
            return false;
        }
    }

    public function Start(): bool
    {
        return $this->executeCommand('Start', static fn (DreameHomeClient $client): array => $client->start());
    }

    public function Pause(): bool
    {
        return $this->executeCommand('Pause', static fn (DreameHomeClient $client): array => $client->pause());
    }

    public function ReturnToDock(): bool
    {
        return $this->executeCommand('Return to dock', static fn (DreameHomeClient $client): array => $client->returnToDock());
    }

    public function Stop(): bool
    {
        return $this->executeCommand('Stop', static fn (DreameHomeClient $client): array => $client->stop());
    }

    public function Locate(): bool
    {
        return $this->executeCommand('Locate', static fn (DreameHomeClient $client): array => $client->locate());
    }

    public function StartShortcut(int $ShortcutID): bool
    {
        return $this->executeCommand(
            'Start shortcut ' . $ShortcutID,
            static fn (DreameHomeClient $client): array => $client->startShortcut($ShortcutID)
        );
    }

    public function GetShortcuts(): string
    {
        try {
            $client = $this->createPreparedClient();
            $shortcuts = $client->getShortcuts();
            $this->saveSession($client);
            return json_encode($shortcuts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            $this->setFailureStatus($exception);
            $this->SendDebug('Get shortcuts failed', $exception->getMessage(), 0);
            return '[]';
        }
    }

    public function ListShortcuts(): string
    {
        $shortcuts = json_decode($this->GetShortcuts(), true);
        if (!is_array($shortcuts) || count($shortcuts) === 0) {
            return $this->Translate('No Dreame shortcuts were found.');
        }
        $lines = [$this->Translate('Available Dreame shortcuts:')];
        foreach ($shortcuts as $shortcut) {
            $lines[] = sprintf('%s | ID %d', (string) ($shortcut['name'] ?? ''), (int) ($shortcut['id'] ?? 0));
        }
        return implode("\n", $lines);
    }

    public function GetCurrentMap(): string
    {
        try {
            $client = $this->createPreparedClient();
            $map = $client->getCurrentMap();
            $this->saveSession($client);
            $map['instanceID'] = $this->InstanceID;
            $map['deviceName'] = $client->getDeviceName();
            $map['state'] = (string) $this->GetValue('State');
            $map['online'] = true;
            return json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            $this->SendDebug('Get map failed', $exception->getMessage(), 0);
            return json_encode([
                'instanceID' => $this->InstanceID,
                'deviceName' => (string) $this->GetValue('DeviceName'),
                'online' => false,
                'error' => $exception->getMessage()
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }

    public function TestMap(): string
    {
        $map = json_decode($this->GetCurrentMap(), true);
        if (!is_array($map) || isset($map['error'])) {
            return $this->Translate('Map test failed: ') . (string) ($map['error'] ?? 'unknown');
        }
        return sprintf(
            $this->Translate('Map received: %d x %d cells, position %d / %d, source %s'),
            (int) $map['width'],
            (int) $map['height'],
            (int) $map['robot']['x'],
            (int) $map['robot']['y'],
            (string) $map['source']
        );
    }

    public function SetCleaningMode(int $Mode): bool
    {
        return $this->executeCommand(
            'Set cleaning mode',
            static function (DreameHomeClient $client) use ($Mode): array {
                $client->setCleaningMode($Mode);
                return [];
            }
        );
    }

    public function GetCoordinatorState(): string
    {
        $taskStatus = (int) $this->GetValue('TaskStatus');
        return json_encode([
            'instanceID' => $this->InstanceID,
            'online' => (bool) $this->GetValue('Online'),
            'deviceName' => (string) $this->GetValue('DeviceName'),
            'model' => (string) $this->GetValue('Model'),
            'battery' => (int) $this->GetValue('Battery'),
            'state' => (string) $this->GetValue('State'),
            'statusCode' => (int) $this->GetValue('StatusCode'),
            'taskStatus' => $taskStatus,
            'taskActive' => $taskStatus > 0,
            'cleaningMode' => (int) $this->GetValue('CleaningMode'),
            'errorCode' => (int) $this->GetValue('ErrorCode'),
            'lastUpdate' => (int) $this->GetValue('LastUpdate')
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function TestConnection(): string
    {
        try {
            $client = $this->createClient();
            $client->login();
            $devices = $client->getVacuumDevices();
            $this->saveSession($client);

            if (count($devices) === 0) {
                return $this->Translate('Login successful, but no vacuum robot was found.');
            }

            $lines = [$this->Translate('Login successful. Found devices:')];
            foreach ($devices as $device) {
                $name = (string) ($device['customName'] ?? '');
                if ($name === '') {
                    $name = (string) ($device['deviceInfo']['displayName'] ?? 'Robot');
                }
                $lines[] = sprintf(
                    '%s | %s | ID %s',
                    $name,
                    (string) ($device['model'] ?? 'unknown'),
                    (string) ($device['did'] ?? 'unknown')
                );
            }

            try {
                $device = $client->selectDevice($devices, trim($this->ReadPropertyString('DeviceID')));
                $client->prepareDevice($device);
                $this->WriteAttributeString('ResolvedDeviceID', $client->getDeviceID());
                $this->saveSession($client);
                $lines[] = $this->Translate('The robot was selected for automatic use.');
            } catch (Throwable $selectionError) {
                $lines[] = $this->Translate('Enter the desired device ID in the configuration.');
            }

            return implode("\n", $lines);
        } catch (Throwable $exception) {
            $this->SendDebug('Connection test failed', $exception->getMessage(), 0);
            return $this->Translate('Connection test failed: ') . $exception->getMessage();
        }
    }

    private function createPreparedClient(): DreameHomeClient
    {
        $client = $this->createClient();
        $client->login();
        $devices = $client->getVacuumDevices();

        $requestedDeviceID = trim($this->ReadPropertyString('DeviceID'));
        if ($requestedDeviceID === '') {
            $requestedDeviceID = $this->ReadAttributeString('ResolvedDeviceID');
        }

        try {
            $device = $client->selectDevice($devices, $requestedDeviceID);
        } catch (Throwable $exception) {
            if ($this->ReadPropertyString('DeviceID') !== '' || $requestedDeviceID === '') {
                throw $exception;
            }
            $device = $client->selectDevice($devices);
        }

        $client->prepareDevice($device);
        $this->WriteAttributeString('ResolvedDeviceID', $client->getDeviceID());
        $this->saveSession($client);
        return $client;
    }

    private function createClient(): DreameHomeClient
    {
        return new DreameHomeClient(
            $this->ReadPropertyString('Country'),
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            [
                'accessToken' => $this->ReadAttributeString('AccessToken'),
                'refreshToken' => $this->ReadAttributeString('RefreshToken'),
                'tokenExpiresAt' => $this->ReadAttributeInteger('TokenExpiresAt'),
                'tenantID' => $this->ReadAttributeString('TenantID'),
                'userID' => $this->ReadAttributeString('UserID')
            ]
        );
    }

    private function saveSession(DreameHomeClient $client): void
    {
        $session = $client->exportSession();
        $this->WriteAttributeString('AccessToken', (string) $session['accessToken']);
        $this->WriteAttributeString('RefreshToken', (string) $session['refreshToken']);
        $this->WriteAttributeInteger('TokenExpiresAt', (int) $session['tokenExpiresAt']);
        $this->WriteAttributeString('TenantID', (string) $session['tenantID']);
        $this->WriteAttributeString('UserID', (string) $session['userID']);
    }

    private function clearSession(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpiresAt', 0);
        $this->WriteAttributeString('TenantID', '000000');
        $this->WriteAttributeString('UserID', '');
        $this->WriteAttributeInteger('FailureCount', 0);
    }

    /** @param array<int, array<string, mixed>> $properties */
    private function applyState(array $properties, DreameHomeClient $client): void
    {
        $values = [];
        foreach ($properties as $property) {
            if (!is_array($property) || (int) ($property['code'] ?? 0) !== 0) {
                continue;
            }
            $values[(string) ($property['did'] ?? '')] = $property['value'] ?? null;
        }

        $state = (int) ($values['0'] ?? -1);
        $this->SetValue('DeviceName', $client->getDeviceName());
        $this->SetValue('Model', $client->getModel());
        $this->SetValue('Battery', max(0, min(100, (int) ($values['2'] ?? 0))));
        $this->SetValue('State', $this->stateName($state));
        $this->SetValue('Charging', (int) ($values['3'] ?? -1) === 1);
        $this->SetValue('ErrorCode', (int) ($values['1'] ?? -1));
        $this->SetValue('CleaningTime', max(0, (int) ($values['6'] ?? 0)));
        $this->SetValue('CleanedArea', max(0.0, (float) ($values['7'] ?? 0.0)));
        $this->SetValue('StatusCode', (int) ($values['5'] ?? -1));
        $this->SetValue('TaskStatus', (int) ($values['11'] ?? -1));
        $this->SetValue('CleaningMode', $this->decodeCleaningMode((int) ($values['27'] ?? -1), $client->getModel()));
    }

    private function executeCommand(string $name, callable $command): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        try {
            $client = $this->createPreparedClient();
            $command($client);
            $this->saveSession($client);
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('UpdateTimer', 5000);
            $this->SendDebug('Command', $name . ' accepted', 0);
            return true;
        } catch (Throwable $exception) {
            $this->SetValue('Online', false);
            $this->setFailureStatus($exception);
            $this->increaseBackoff();
            $this->SendDebug('Command failed', $name . ': ' . $exception->getMessage(), 0);
            return false;
        }
    }

    private function setFailureStatus(Throwable $exception): void
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'credentials are missing')) {
            $this->SetStatus(self::STATUS_MISSING_CREDENTIALS);
        } elseif (str_contains($message, 'login failed') || str_contains($message, 'http 401')) {
            $this->SetStatus(self::STATUS_LOGIN_FAILED);
        } elseif (
            str_contains($message, 'robot was found') ||
            str_contains($message, 'device id') ||
            str_contains($message, 'more than one robot')
        ) {
            $this->SetStatus(self::STATUS_DEVICE_NOT_FOUND);
        } else {
            $this->SetStatus(self::STATUS_COMMUNICATION_ERROR);
        }
    }

    private function increaseBackoff(): void
    {
        $failures = min(4, $this->ReadAttributeInteger('FailureCount') + 1);
        $this->WriteAttributeInteger('FailureCount', $failures);
        $seconds = min(900, $this->getConfiguredInterval() * (2 ** $failures));
        $this->SetTimerInterval('UpdateTimer', $seconds * 1000);
    }

    private function getConfiguredInterval(): int
    {
        return max(30, min(3600, $this->ReadPropertyInteger('UpdateInterval')));
    }

    private function stateName(int $state): string
    {
        $states = [
            1 => 'Cleaning',
            2 => 'Idle',
            3 => 'Paused',
            4 => 'Error',
            5 => 'Returning to dock',
            6 => 'Charging',
            7 => 'Mopping',
            8 => 'Drying',
            9 => 'Washing mops',
            10 => 'Returning to wash',
            11 => 'Creating map',
            12 => 'Vacuuming and mopping',
            13 => 'Charging complete',
            14 => 'Updating',
            22 => 'Auto-emptying',
            29 => 'Waiting for task',
            30 => 'Cleaning station',
            34 => 'Emptying',
            121 => 'Entering dock',
            122 => 'Leaving dock'
        ];
        return $this->Translate($states[$state] ?? ('Unknown (' . $state . ')'));
    }

    private function decodeCleaningMode(int $rawMode, string $model): int
    {
        if (str_contains(strtolower($model), 'r6001') || str_contains(strtolower($model), 'x60')) {
            return match ($rawMode & 0x03) {
                2 => 0,
                1 => 1,
                0 => 2,
                3 => 3
            };
        }
        return in_array($rawMode, [0, 1, 2, 3], true) ? $rawMode : -1;
    }

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('DRM.Command')) {
            IPS_CreateVariableProfile('DRM.Command', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_NONE, $this->Translate('Select command'), '', -1);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_START, $this->Translate('Start / resume'), '', 0x33AA33);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_PAUSE, $this->Translate('Pause'), '', 0xE6A700);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_DOCK, $this->Translate('Return to dock'), '', 0x3388CC);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_STOP, $this->Translate('Stop'), '', 0xCC3333);
            IPS_SetVariableProfileAssociation('DRM.Command', self::COMMAND_LOCATE, $this->Translate('Locate'), '', 0x777777);
        }

        if (!IPS_VariableProfileExists('DRM.Minutes')) {
            IPS_CreateVariableProfile('DRM.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('DRM.Minutes', 0, 1440, 1);
            IPS_SetVariableProfileText('DRM.Minutes', '', ' min');
        }

        if (!IPS_VariableProfileExists('DRM.CleaningMode')) {
            IPS_CreateVariableProfile('DRM.CleaningMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRM.CleaningMode', -1, $this->Translate('Unknown'), '', 0x777777);
            IPS_SetVariableProfileAssociation('DRM.CleaningMode', 0, $this->Translate('Vacuum only'), '', 0x3388CC);
            IPS_SetVariableProfileAssociation('DRM.CleaningMode', 1, $this->Translate('Mop only'), '', 0x33AA99);
            IPS_SetVariableProfileAssociation('DRM.CleaningMode', 2, $this->Translate('Vacuum and mop'), '', 0x7755CC);
            IPS_SetVariableProfileAssociation('DRM.CleaningMode', 3, $this->Translate('Mop after vacuum'), '', 0xCC8833);
        }

        if (!IPS_VariableProfileExists('DRM.Area')) {
            IPS_CreateVariableProfile('DRM.Area', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('DRM.Area', 0, 10000, 0.1);
            IPS_SetVariableProfileDigits('DRM.Area', 1);
            IPS_SetVariableProfileText('DRM.Area', '', ' m²');
        }
    }
}
