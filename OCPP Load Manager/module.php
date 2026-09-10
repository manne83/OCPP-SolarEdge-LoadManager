<?php

declare(strict_types=1);

class OCPPSolarEdgeLoadManager extends IPSModule
{
    private const MODE_FULL_POWER_FIFO = 0;
    private const MODE_DYNAMIC_SHARING = 1;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyInteger('Mode', self::MODE_DYNAMIC_SHARING);
        $this->RegisterPropertyFloat('GridLimit', 50.0);
        $this->RegisterPropertyFloat('SafetyReserve', 2.0);
        $this->RegisterPropertyFloat('MinimumCurrent', 6.0);
        $this->RegisterPropertyFloat('MaximumCurrent', 16.0);
        $this->RegisterPropertyInteger('ConnectorId', 1);
        $this->RegisterPropertyInteger('NumberPhases', 3);
        $this->RegisterPropertyInteger('TestInstanceID', 17435);
        $this->RegisterPropertyString('Wallboxes', json_encode([
            ['Enabled' => false, 'InstanceID' => 33934, 'Name' => 'Wallbox 1', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 43833, 'Name' => 'Wallbox 3', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 58204, 'Name' => 'Wallbox 4', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 16452, 'Name' => 'Wallbox 5', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 17435, 'Name' => 'Wallbox 6', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 58451, 'Name' => 'Wallbox 7', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 52203, 'Name' => 'Wallbox 8', 'SmartChargingVerified' => false],
            ['Enabled' => false, 'InstanceID' => 45985, 'Name' => 'Wallbox 9', 'SmartChargingVerified' => false]
        ]));

        $this->RegisterVariableString('ControllerStatus', $this->Translate('Controller status'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 1);
        $this->RegisterTimer('ControlTimer', 0, 'OCPPLMCTRL_Run($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('ControlTimer', $this->ReadPropertyBoolean('Enabled') ? 1000 : 0);
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->SetValue('ControllerStatus', 'Deaktiviert – es werden keine Ladebefehle gesendet.');
        }
    }

    public function Run(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->SetValue('ControllerStatus', 'Deaktiviert – es werden keine Ladebefehle gesendet.');
            return;
        }

        $semaphore = 'OCPPLMCTRL_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 3000)) {
            return;
        }

        try {
            $this->control();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    public function TestChargingLimit(float $LimitAmpere = 6.0): string
    {
        if ($this->ReadPropertyBoolean('Enabled')) {
            throw new RuntimeException('Disable automatic load management before running a manual test');
        }
        $instanceId = $this->ReadPropertyInteger('TestInstanceID');
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            throw new InvalidArgumentException('Please select a valid charging point instance for the test');
        }

        return OCPPLM_SetChargingLimit(
            $instanceId,
            max(1, $this->ReadPropertyInteger('ConnectorId')),
            $LimitAmpere,
            min(3, max(1, $this->ReadPropertyInteger('NumberPhases')))
        );
    }

    public function StartTestCharging(): void
    {
        if ($this->ReadPropertyBoolean('Enabled')) {
            throw new RuntimeException('Disable automatic load management before running a manual test');
        }
        $instanceId = $this->getTestInstanceID();
        $connectorId = max(1, $this->ReadPropertyInteger('ConnectorId'));
        $profileState = $this->getChargingProfileState($instanceId, $connectorId);
        $acceptedLimit = (float) ($profileState['Limit'] ?? 0.0);
        $profileStatus = (string) ($profileState['Status'] ?? '');
        if ($profileStatus !== 'Accepted' || abs($acceptedLimit - 6.0) >= 0.11) {
            throw new RuntimeException('The 6 A charging profile must be Accepted before the test charging is started');
        }
        OCPPLM_RemoteStartTransaction($instanceId, $connectorId);
    }

    public function StopTestCharging(): void
    {
        if ($this->ReadPropertyBoolean('Enabled')) {
            throw new RuntimeException('Disable automatic load management before running a manual test');
        }
        OCPPLM_RemoteStopCurrentTransaction(
            $this->getTestInstanceID(),
            max(1, $this->ReadPropertyInteger('ConnectorId'))
        );
    }

    private function control(): void
    {
        $connectorId = max(1, $this->ReadPropertyInteger('ConnectorId'));
        $gridLimit = max(6.0, $this->ReadPropertyFloat('GridLimit'));
        $reserve = max(0.0, $this->ReadPropertyFloat('SafetyReserve'));
        $usableCurrent = max(0.0, $gridLimit - $reserve);
        $minimumCurrent = max(6.0, $this->ReadPropertyFloat('MinimumCurrent'));
        $maximumCurrent = max($minimumCurrent, $this->ReadPropertyFloat('MaximumCurrent'));
        $numberPhases = min(3, max(1, $this->ReadPropertyInteger('NumberPhases')));
        $mode = $this->ReadPropertyInteger('Mode');
        $rows = json_decode($this->ReadPropertyString('Wallboxes'), true);
        $rows = is_array($rows) ? $rows : [];

        $wallboxes = [];
        $notVerified = [];
        $invalidWallboxes = [];
        foreach ($rows as $row) {
            if (!($row['Enabled'] ?? false)) {
                continue;
            }
            $instanceId = (int) ($row['InstanceID'] ?? 0);
            if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
                $invalidWallboxes[] = (string) ($row['Name'] ?? ('Instanz ' . $instanceId));
                continue;
            }
            $wallboxes[$instanceId] = (string) ($row['Name'] ?? ('Instanz ' . $instanceId));
            if (!($row['SmartChargingVerified'] ?? false)) {
                $notVerified[] = $wallboxes[$instanceId];
            }
        }

        if (count($invalidWallboxes) > 0) {
            $this->setOverview([], [], [], 0.0, 'Sicherheitsstopp: ungültige Instanz bei ' . implode(', ', $invalidWallboxes));
            return;
        }

        if (count($wallboxes) === 0) {
            $this->setOverview([], [], [], 0.0, 'Keine aktive Wallbox konfiguriert.');
            return;
        }

        if (count($notVerified) > 0) {
            $this->setOverview([], [], [], 0.0, 'Sicherheitsstopp: 6-A-Test fehlt bei ' . implode(', ', $notVerified));
            return;
        }

        $queue = json_decode($this->GetBuffer('Queue'), true);
        $starting = json_decode($this->GetBuffer('Starting'), true);
        $lastRequested = json_decode($this->GetBuffer('LastRequested'), true);
        $queue = is_array($queue) ? array_values(array_map('intval', $queue)) : [];
        $starting = is_array($starting) ? $starting : [];
        $lastRequested = is_array($lastRequested) ? $lastRequested : [];
        $queue = array_values(array_unique(array_filter(
            $queue,
            static fn (int $id): bool => isset($wallboxes[$id])
        )));

        $now = time();
        $active = [];
        $statusByBox = [];

        foreach ($wallboxes as $instanceId => $name) {
            $status = $this->readChildValue($instanceId, 'Status_' . $connectorId, '');
            $transaction = (bool) $this->readChildValue($instanceId, 'Transaction_' . $connectorId, false);
            $isActive = $transaction || in_array($status, ['Charging', 'SuspendedEV'], true);
            $statusByBox[$instanceId] = ['status' => $status, 'active' => $isActive];

            if ($isActive) {
                $active[] = $instanceId;
                if (isset($starting[(string) $instanceId]) && ($starting[(string) $instanceId]['stage'] ?? '') === 'start') {
                    unset($starting[(string) $instanceId]);
                    $queue = $this->removeFromQueue($queue, $instanceId);
                }
            }

            if (!$isActive && in_array($status, ['Available', 'Unavailable', 'Faulted'], true)) {
                $queue = $this->removeFromQueue($queue, $instanceId);
                unset($starting[(string) $instanceId], $lastRequested[(string) $instanceId]);
                continue;
            }

            if (
                !$isActive
                && $status === 'Preparing'
                && !in_array($instanceId, $queue, true)
                && !isset($starting[(string) $instanceId])
            ) {
                $queue[] = $instanceId;
            }
        }

        foreach ($starting as $instanceIdString => $startState) {
            $instanceId = (int) $instanceIdString;
            if (!isset($wallboxes[$instanceId])) {
                unset($starting[$instanceIdString]);
                continue;
            }
            if (($now - (int) ($startState['since'] ?? $now)) <= 60) {
                continue;
            }

            unset($starting[$instanceIdString]);
            $status = $statusByBox[$instanceId]['status'] ?? '';
            if (!in_array($status, ['', 'Available', 'Unavailable', 'Faulted'], true)) {
                $queue[] = $instanceId;
            }
        }

        $participants = array_values(array_unique(array_merge(
            $active,
            array_map('intval', array_keys($starting))
        )));

        $admissionCurrent = $mode === self::MODE_FULL_POWER_FIFO ? $maximumCurrent : $minimumCurrent;
        $maxParticipants = $admissionCurrent > 0 ? (int) floor($usableCurrent / $admissionCurrent) : 0;

        while (count($participants) < $maxParticipants && count($queue) > 0) {
            $instanceId = (int) array_shift($queue);
            if (!isset($wallboxes[$instanceId]) || ($statusByBox[$instanceId]['active'] ?? false)) {
                continue;
            }
            $status = $statusByBox[$instanceId]['status'] ?? '';
            if (in_array($status, ['', 'Available', 'Unavailable', 'Faulted'], true)) {
                continue;
            }
            $starting[(string) $instanceId] = ['stage' => 'profile', 'since' => $now];
            $participants[] = $instanceId;
        }

        if (count($participants) * $minimumCurrent > $usableCurrent + 0.01) {
            $this->saveState($queue, $starting, $lastRequested);
            $this->setOverview($active, $queue, [], 0.0, 'Sicherheitsstopp: Mindeststrom der aktiven Fahrzeuge liegt über der verfügbaren Grenze.');
            return;
        }

        $limits = $this->calculateLimits($participants, $usableCurrent, $minimumCurrent, $maximumCurrent, $mode);
        $allProfilesAccepted = true;
        $profileErrors = [];

        foreach ($limits as $instanceId => $limit) {
            $profileState = $this->getChargingProfileState($instanceId, $connectorId);
            $acceptedLimit = (float) ($profileState['Limit'] ?? 0.0);
            $profileStatus = (string) ($profileState['Status'] ?? '');
            $confirmed = $profileStatus === 'Accepted' && abs($acceptedLimit - $limit) < 0.11;

            if (!in_array($profileStatus, ['', 'Pending', 'Accepted'], true)) {
                $profileErrors[] = $wallboxes[$instanceId] . ': ' . $profileStatus;
                $allProfilesAccepted = false;
                continue;
            }

            if (!$confirmed) {
                $allProfilesAccepted = false;
                $previous = $lastRequested[(string) $instanceId] ?? [];
                $different = abs((float) ($previous['limit'] ?? 0.0) - $limit) >= 0.11;
                $expired = ($now - (int) ($previous['time'] ?? 0)) >= 15;
                if ($different || $expired) {
                    OCPPLM_SetChargingLimit($instanceId, $connectorId, $limit, $numberPhases);
                    $lastRequested[(string) $instanceId] = ['limit' => $limit, 'time' => $now];
                }
            }
        }

        if ($allProfilesAccepted) {
            foreach ($starting as $instanceIdString => &$startState) {
                if (($startState['stage'] ?? '') !== 'profile') {
                    continue;
                }
                $instanceId = (int) $instanceIdString;
                OCPPLM_RemoteStartTransaction($instanceId, $connectorId);
                $startState = ['stage' => 'start', 'since' => $now];
            }
            unset($startState);
        }

        $message = count($profileErrors) > 0
            ? 'Sicherheitsstopp: ' . implode(' | ', $profileErrors)
            : ($allProfilesAccepted ? 'Aktiv – Stromprofile bestätigt.' : 'Warte auf Bestätigung der Stromprofile.');

        $this->saveState($queue, $starting, $lastRequested);
        $this->setOverview($active, $queue, $limits, array_sum($limits), $message);
    }

    private function calculateLimits(array $participants, float $available, float $minimum, float $maximum, int $mode): array
    {
        $limits = [];
        foreach ($participants as $instanceId) {
            $limits[$instanceId] = $minimum;
        }
        if (count($participants) === 0) {
            return $limits;
        }

        if ($mode === self::MODE_FULL_POWER_FIFO) {
            foreach ($limits as $instanceId => $unused) {
                $limits[$instanceId] = $maximum;
            }
            return $limits;
        }

        $remaining = max(0.0, $available - count($participants) * $minimum);
        while ($remaining > 0.01) {
            $eligible = array_keys(array_filter(
                $limits,
                static fn (float $limit): bool => $limit < $maximum - 0.01
            ));
            if (count($eligible) === 0) {
                break;
            }
            $share = $remaining / count($eligible);
            $used = 0.0;
            foreach ($eligible as $instanceId) {
                $increase = min($share, $maximum - $limits[$instanceId]);
                $limits[$instanceId] += $increase;
                $used += $increase;
            }
            if ($used <= 0.001) {
                break;
            }
            $remaining -= $used;
        }

        foreach ($limits as $instanceId => $limit) {
            // Always round down. Rounding to the nearest tenth could make the
            // sum exceed the configured limit (for example 7 x 6.9 A).
            $limits[$instanceId] = floor($limit * 10) / 10;
        }
        return $limits;
    }

    private function readChildValue(int $instanceId, string $ident, mixed $default): mixed
    {
        foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
            if (IPS_GetObject($childId)['ObjectIdent'] === $ident) {
                return GetValue($childId);
            }
        }
        return $default;
    }

    private function getTestInstanceID(): int
    {
        $instanceId = $this->ReadPropertyInteger('TestInstanceID');
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            throw new InvalidArgumentException('Please select a valid charging point instance for the test');
        }
        return $instanceId;
    }

    private function getChargingProfileState(int $instanceId, int $connectorId): array
    {
        $state = json_decode(OCPPLM_GetChargingProfileState($instanceId, $connectorId), true);
        return is_array($state) ? $state : ['Status' => '', 'Limit' => null];
    }

    private function removeFromQueue(array $queue, int $instanceId): array
    {
        return array_values(array_filter(
            $queue,
            static fn (int $id): bool => $id !== $instanceId
        ));
    }

    private function saveState(array $queue, array $starting, array $lastRequested): void
    {
        $this->SetBuffer('Queue', json_encode(array_values(array_unique($queue))));
        $this->SetBuffer('Starting', json_encode($starting));
        $this->SetBuffer('LastRequested', json_encode($lastRequested));
    }

    private function setOverview(array $active, array $queue, array $limits, float $allocated, string $message): void
    {
        $rows = json_decode($this->ReadPropertyString('Wallboxes'), true);
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $names[(int) ($row['InstanceID'] ?? 0)] = (string) ($row['Name'] ?? '');
        }

        $allocationText = [];
        foreach ($limits as $instanceId => $limit) {
            $allocationText[] = ($names[$instanceId] ?? ('Instanz ' . $instanceId)) . ': ' . number_format($limit, 1, ',', '') . ' A';
        }

        $details = [
            $message,
            'Aktiv: ' . count($active),
            'Wartend: ' . count($queue),
            'Verteilt: ' . number_format($allocated, 1, ',', '') . ' A',
            'Zuteilung: ' . (count($allocationText) > 0 ? implode(' | ', $allocationText) : '-')
        ];
        $this->SetValue('ControllerStatus', implode("\n", $details));
    }
}
