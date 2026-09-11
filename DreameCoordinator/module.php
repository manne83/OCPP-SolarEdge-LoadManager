<?php

declare(strict_types=1);

class DreameCoordinator extends IPSModuleStrict
{
    private const ROBOT_MODULE_ID = '{6B87C506-2B66-48BB-8E1C-EA41DDE8D353}';
    private const STATUS_INVALID_CONFIGURATION = 200;
    private const STATUS_SEQUENCE_ERROR = 201;
    private const COMMAND_NONE = 0;
    private const COMMAND_START = 1;
    private const COMMAND_PAUSE = 2;
    private const COMMAND_RESUME = 3;
    private const COMMAND_ABORT = 4;
    private const PLAN_IDLE = 'idle';
    private const PLAN_RUNNING = 'running';
    private const PLAN_PAUSED = 'paused';
    private const PLAN_COMPLETED = 'completed';
    private const PLAN_ABORTED = 'aborted';
    private const PLAN_ERROR = 'error';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('Jobs', '[]');
        $this->RegisterPropertyInteger('PollInterval', 30);
        $this->RegisterPropertyInteger('StepTimeout', 240);
        $this->RegisterAttributeString('Plan', '{}');
        $this->RegisterAttributeString('CoverageLedger', '{}');
        $this->RegisterTimer('CoordinateTimer', 0, "DRMC_Coordinate(\$_IPS['TARGET']);");
        $this->registerProfiles();
        $this->RegisterVariableString('PlanStatus', $this->Translate('Plan status'), '', 10);
        $this->RegisterVariableString('CurrentRobot', $this->Translate('Current robot'), '', 20);
        $this->RegisterVariableInteger('CurrentStep', $this->Translate('Current step'), '', 30);
        $this->RegisterVariableInteger('TotalSteps', $this->Translate('Total steps'), '', 40);
        $this->RegisterVariableString('Coverage', $this->Translate('Coverage'), '', 50);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), '', 60);
        $this->RegisterVariableInteger('Command', $this->Translate('Coordinator command'), 'DRMC.Command', 70);
        $this->EnableAction('Command');
        $this->SetValue('PlanStatus', $this->Translate('Ready'));
        $this->SetValue('Coverage', $this->Translate('No cleaning recorded yet'));
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerProfiles();
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('CoordinateTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        try {
            $this->getConfiguredJobs();
            $this->SetStatus(IS_ACTIVE);
        } catch (Throwable $exception) {
            $this->SetTimerInterval('CoordinateTimer', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            $this->SetValue('LastError', $exception->getMessage());
            return;
        }
        $plan = $this->readPlan();
        $this->SetTimerInterval('CoordinateTimer', ($plan['status'] ?? '') === self::PLAN_RUNNING ? $this->getPollInterval() * 1000 : 0);
        $this->publishPlan($plan);
        $this->publishCoverage($this->readCoverage());
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Command') {
            throw new InvalidArgumentException('Unknown action ident');
        }
        try {
            match ((int) $Value) {
                self::COMMAND_START => $this->StartSequence(),
                self::COMMAND_PAUSE => $this->PauseSequence(),
                self::COMMAND_RESUME => $this->ResumeSequence(),
                self::COMMAND_ABORT => $this->AbortSequence(),
                self::COMMAND_NONE => true,
                default => throw new InvalidArgumentException('Unknown coordinator command')
            };
        } finally {
            $this->SetValue('Command', self::COMMAND_NONE);
        }
    }

    public function StartSequence(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        $existing = $this->readPlan();
        if (in_array($existing['status'] ?? '', [self::PLAN_RUNNING, self::PLAN_PAUSED], true)) {
            $this->SetValue('LastError', 'Ein Reinigungsplan ist bereits aktiv.');
            return false;
        }
        try {
            $jobs = [];
            foreach ($this->getConfiguredJobs() as $job) {
                $jobs[] = $job + [
                    'robotName' => $this->getRobotLabel($job['instanceID']),
                    'status' => 'pending', 'startedAt' => 0, 'seenRunning' => false, 'offlineCount' => 0
                ];
            }
            $plan = ['status' => self::PLAN_RUNNING, 'startedAt' => time(), 'jobs' => $jobs];
            $this->writePlan($plan);
            $this->WriteAttributeString('CoverageLedger', '{}');
            $this->SetValue('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('CoordinateTimer', $this->getPollInterval() * 1000);
            $this->publishCoverage([]);
            $this->publishPlan($plan);
            return $this->Coordinate();
        } catch (Throwable $exception) {
            $this->failPlan($exception->getMessage());
            return false;
        }
    }

    public function Coordinate(): bool
    {
        $semaphore = 'DRMC.Coordinate.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            return false;
        }
        try {
            return $this->coordinateLocked();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function coordinateLocked(): bool
    {
        $plan = $this->readPlan();
        if (($plan['status'] ?? self::PLAN_IDLE) !== self::PLAN_RUNNING) {
            return false;
        }
        $jobs = is_array($plan['jobs'] ?? null) ? $plan['jobs'] : [];
        try {
            foreach ($jobs as $index => $job) {
                if (!in_array($job['status'] ?? '', ['waiting_start', 'running'], true)) {
                    continue;
                }
                $state = $this->updateAndReadRobot((int) $job['instanceID']);
                if (!(bool) ($state['online'] ?? false)) {
                    $job['offlineCount'] = (int) ($job['offlineCount'] ?? 0) + 1;
                    $jobs[$index] = $job;
                    if ($job['offlineCount'] >= 3) {
                        throw new RuntimeException('Roboter wiederholt nicht erreichbar: ' . $job['robotName']);
                    }
                    continue;
                }
                $job['offlineCount'] = 0;
                if ((int) ($state['errorCode'] ?? 0) > 0) {
                    throw new RuntimeException('Roboterfehler ' . (int) $state['errorCode'] . ': ' . $job['robotName']);
                }
                if ((bool) ($state['taskActive'] ?? false)) {
                    $job['seenRunning'] = true;
                    $job['status'] = 'running';
                } elseif ((bool) ($job['seenRunning'] ?? false)) {
                    $job['status'] = 'completed';
                    $job['completedAt'] = time();
                    $this->recordCoverage($job);
                } elseif (time() - (int) $job['startedAt'] > 120) {
                    throw new RuntimeException('Start nicht bestätigt: ' . $job['jobID']);
                }
                if (($job['status'] ?? '') !== 'completed' && time() - (int) $job['startedAt'] > $this->getJobTimeout()) {
                    throw new RuntimeException('Maximale Dauer überschritten: ' . $job['jobID']);
                }
                $jobs[$index] = $job;
            }

            $completed = [];
            $busyRobots = [];
            $lockedAreas = [];
            foreach ($jobs as $job) {
                if (($job['status'] ?? '') === 'completed') {
                    $completed[(string) $job['jobID']] = true;
                }
                if (in_array($job['status'] ?? '', ['waiting_start', 'running'], true)) {
                    $busyRobots[(int) $job['instanceID']] = true;
                    foreach ($this->lockKeys($job) as $lockKey) {
                        $lockedAreas[$lockKey] = true;
                    }
                }
            }

            $started = false;
            foreach ($jobs as $index => $job) {
                if (($job['status'] ?? '') !== 'pending' || !$this->dependenciesCompleted($job, $completed)) {
                    continue;
                }
                $robotID = (int) $job['instanceID'];
                $jobLocks = $this->lockKeys($job);
                if (isset($busyRobots[$robotID]) || count(array_intersect($jobLocks, array_keys($lockedAreas))) > 0) {
                    continue;
                }
                $state = $this->updateAndReadRobot($robotID);
                if (!(bool) ($state['online'] ?? false)) {
                    throw new RuntimeException('Roboter vor dem Start nicht erreichbar: ' . $job['robotName']);
                }
                if ((bool) ($state['taskActive'] ?? false)) {
                    throw new RuntimeException('Roboter führt bereits eine fremde Aufgabe aus: ' . $job['robotName']);
                }
                if (!DRM_StartShortcut($robotID, (int) $job['shortcutID'])) {
                    throw new RuntimeException('Kurzbefehl konnte nicht gestartet werden: ' . $job['jobID']);
                }
                $job['status'] = 'waiting_start';
                $job['startedAt'] = time();
                $job['seenRunning'] = false;
                $job['offlineCount'] = 0;
                $jobs[$index] = $job;
                $busyRobots[$robotID] = true;
                foreach ($jobLocks as $lockKey) {
                    $lockedAreas[$lockKey] = true;
                }
                $started = true;
            }

            $allCompleted = count($jobs) > 0;
            $hasActive = false;
            $hasPending = false;
            foreach ($jobs as $job) {
                $allCompleted = $allCompleted && ($job['status'] ?? '') === 'completed';
                $hasActive = $hasActive || in_array($job['status'] ?? '', ['waiting_start', 'running'], true);
                $hasPending = $hasPending || ($job['status'] ?? '') === 'pending';
            }
            if ($allCompleted) {
                $plan['status'] = self::PLAN_COMPLETED;
                $this->SetTimerInterval('CoordinateTimer', 0);
            } elseif ($hasPending && !$hasActive && !$started) {
                throw new RuntimeException('Kein Auftrag kann gestartet werden. Abhängigkeiten prüfen.');
            }
            $plan['jobs'] = $jobs;
            $this->writePlan($plan);
            $this->publishPlan($plan);
            return true;
        } catch (Throwable $exception) {
            $plan['jobs'] = $jobs;
            $this->writePlan($plan);
            $this->failPlan($exception->getMessage());
            return false;
        }
    }

    public function PauseSequence(): bool
    {
        $plan = $this->readPlan();
        if (($plan['status'] ?? '') !== self::PLAN_RUNNING) {
            return false;
        }
        try {
            foreach ($plan['jobs'] ?? [] as $job) {
                if (in_array($job['status'] ?? '', ['waiting_start', 'running'], true) && !DRM_Pause((int) $job['instanceID'])) {
                    throw new RuntimeException('Roboter konnte nicht pausiert werden: ' . $job['robotName']);
                }
            }
            $plan['status'] = self::PLAN_PAUSED;
            $plan['pausedAt'] = time();
            $this->writePlan($plan);
            $this->SetTimerInterval('CoordinateTimer', 0);
            $this->publishPlan($plan);
            return true;
        } catch (Throwable $exception) {
            $this->failPlan($exception->getMessage());
            return false;
        }
    }

    public function ResumeSequence(): bool
    {
        $plan = $this->readPlan();
        if (($plan['status'] ?? '') !== self::PLAN_PAUSED) {
            return false;
        }
        try {
            $pausedAt = (int) ($plan['pausedAt'] ?? time());
            foreach ($plan['jobs'] ?? [] as $index => $job) {
                if (in_array($job['status'] ?? '', ['waiting_start', 'running'], true)) {
                    if (!DRM_Start((int) $job['instanceID'])) {
                        throw new RuntimeException('Roboter konnte nicht fortgesetzt werden: ' . $job['robotName']);
                    }
                    $job['startedAt'] = (int) $job['startedAt'] + max(0, time() - $pausedAt);
                    $plan['jobs'][$index] = $job;
                }
            }
            unset($plan['pausedAt']);
            $plan['status'] = self::PLAN_RUNNING;
            $this->writePlan($plan);
            $this->SetTimerInterval('CoordinateTimer', $this->getPollInterval() * 1000);
            $this->publishPlan($plan);
            return $this->Coordinate();
        } catch (Throwable $exception) {
            $this->failPlan($exception->getMessage());
            return false;
        }
    }

    public function AbortSequence(): bool
    {
        $plan = $this->readPlan();
        $robotIDs = [];
        foreach ($plan['jobs'] ?? [] as $job) {
            $robotIDs[(int) ($job['instanceID'] ?? 0)] = true;
        }
        $ok = true;
        foreach (array_keys($robotIDs) as $robotID) {
            if ($robotID <= 0 || !IPS_InstanceExists($robotID)) {
                continue;
            }
            try {
                $state = $this->readRobotState($robotID);
                if ((bool) ($state['taskActive'] ?? false)) {
                    $ok = DRM_Stop($robotID) && $ok;
                }
                $ok = DRM_ReturnToDock($robotID) && $ok;
            } catch (Throwable $exception) {
                $ok = false;
                $this->SendDebug('Abort failed', $exception->getMessage(), 0);
            }
        }
        $plan['status'] = self::PLAN_ABORTED;
        $this->writePlan($plan);
        $this->SetTimerInterval('CoordinateTimer', 0);
        $this->SetStatus($ok ? IS_ACTIVE : self::STATUS_SEQUENCE_ERROR);
        $this->SetValue('LastError', $ok ? '' : 'Mindestens ein Roboter konnte nicht zurückgerufen werden.');
        $this->publishPlan($plan);
        return $ok;
    }

    public function GetPlanState(): string
    {
        return json_encode($this->readPlan(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function GetCoverageLedger(): string
    {
        return json_encode($this->readCoverage(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<int, array<string, mixed>> */
    private function getConfiguredJobs(): array
    {
        $rows = json_decode($this->ReadPropertyString('Jobs'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            throw new RuntimeException('Auftragsliste ist ungültig.');
        }
        $jobs = [];
        $ids = [];
        $robotIDs = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !(bool) ($row['Enabled'] ?? false)) {
                continue;
            }
            $jobID = trim((string) ($row['JobID'] ?? ''));
            $robotID = (int) ($row['InstanceID'] ?? 0);
            $area = trim((string) ($row['Area'] ?? ''));
            $shortcutID = (int) ($row['ShortcutID'] ?? 0);
            $role = (int) ($row['Role'] ?? -1);
            if ($jobID === '' || preg_match('/^[A-Za-z0-9_-]+$/', $jobID) !== 1) {
                throw new RuntimeException('Auftrags-IDs dürfen nur Buchstaben, Zahlen, _ und - enthalten.');
            }
            if (isset($ids[$jobID])) {
                throw new RuntimeException('Auftrags-ID mehrfach vorhanden: ' . $jobID);
            }
            if ($robotID <= 0 || !IPS_InstanceExists($robotID)) {
                throw new RuntimeException('Eine Roboter-Instanz existiert nicht.');
            }
            $instance = IPS_GetInstance($robotID);
            if ((string) ($instance['ModuleInfo']['ModuleID'] ?? '') !== self::ROBOT_MODULE_ID) {
                throw new RuntimeException('Eine ausgewählte Instanz ist kein Dreame-Roboter.');
            }
            if ($area === '' || $shortcutID < 25 || $shortcutID > 128 || !in_array($role, [0, 1, 2], true)) {
                throw new RuntimeException('Teilfläche, Kurzbefehl-ID oder Ergebnis ist ungültig: ' . $jobID);
            }
            $dependsOn = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($row['DependsOn'] ?? '')) ?: [])));
            $locks = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($row['Locks'] ?? '')) ?: [])));
            $ids[$jobID] = true;
            $robotIDs[$robotID] = true;
            $jobs[] = [
                'jobID' => $jobID, 'instanceID' => $robotID, 'area' => $area,
                'shortcutID' => $shortcutID, 'role' => $role, 'dependsOn' => $dependsOn, 'locks' => $locks
            ];
        }
        if (count($jobs) < 2 || count($robotIDs) < 2) {
            throw new RuntimeException('Mindestens zwei Aufträge auf zwei Robotern sind erforderlich.');
        }
        foreach ($jobs as $job) {
            foreach ($job['dependsOn'] as $dependency) {
                if (!isset($ids[$dependency]) || $dependency === $job['jobID']) {
                    throw new RuntimeException('Ungültige Abhängigkeit bei ' . $job['jobID'] . ': ' . $dependency);
                }
            }
        }
        $this->assertNoDependencyCycle($jobs);
        return $jobs;
    }

    /** @param array<int, array<string, mixed>> $jobs */
    private function assertNoDependencyCycle(array $jobs): void
    {
        $dependencies = [];
        foreach ($jobs as $job) {
            $dependencies[$job['jobID']] = $job['dependsOn'];
        }
        $visited = [];
        $visiting = [];
        $visit = function (string $jobID) use (&$visit, &$visited, &$visiting, $dependencies): void {
            if (isset($visited[$jobID])) {
                return;
            }
            if (isset($visiting[$jobID])) {
                throw new RuntimeException('Zirkuläre Auftragsabhängigkeit bei ' . $jobID);
            }
            $visiting[$jobID] = true;
            foreach ($dependencies[$jobID] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$jobID]);
            $visited[$jobID] = true;
        };
        foreach (array_keys($dependencies) as $jobID) {
            $visit($jobID);
        }
    }

    /** @param array<string, mixed> $job @param array<string, bool> $completed */
    private function dependenciesCompleted(array $job, array $completed): bool
    {
        foreach ($job['dependsOn'] as $dependency) {
            if (!isset($completed[$dependency])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function updateAndReadRobot(int $robotID): array
    {
        DRM_Update($robotID);
        return $this->readRobotState($robotID);
    }

    /** @return array<string, mixed> */
    private function readRobotState(int $robotID): array
    {
        $state = json_decode(DRM_GetCoordinatorState($robotID), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new RuntimeException('Roboterstatus ist ungültig.');
        }
        return $state;
    }

    private function getRobotLabel(int $robotID): string
    {
        $state = $this->readRobotState($robotID);
        $name = trim((string) ($state['deviceName'] ?? ''));
        return $name !== '' ? $name : IPS_GetName($robotID);
    }

    private function areaKey(string $area): string
    {
        return strtolower(trim($area));
    }

    /** @param array<string, mixed> $job @return array<int, string> */
    private function lockKeys(array $job): array
    {
        $locks = [$this->areaKey((string) $job['area'])];
        foreach ($job['locks'] ?? [] as $lock) {
            $lock = $this->areaKey((string) $lock);
            if ($lock !== '') {
                $locks[] = $lock;
            }
        }
        return array_values(array_unique($locks));
    }

    /** @param array<string, mixed> $job */
    private function recordCoverage(array $job): void
    {
        $coverage = $this->readCoverage();
        $area = (string) $job['area'];
        $entry = [
            'done' => true, 'jobID' => (string) $job['jobID'], 'robotID' => (int) $job['instanceID'],
            'robotName' => (string) $job['robotName'], 'completedAt' => time()
        ];
        if ((int) $job['role'] === 0 || (int) $job['role'] === 2) {
            $coverage[$area]['vacuum'] = $entry;
        }
        if ((int) $job['role'] === 1 || (int) $job['role'] === 2) {
            $coverage[$area]['mop'] = $entry;
        }
        $this->WriteAttributeString('CoverageLedger', json_encode($coverage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->publishCoverage($coverage);
    }

    /** @return array<string, mixed> */
    private function readPlan(): array
    {
        try {
            $value = json_decode($this->ReadAttributeString('Plan'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $plan */
    private function writePlan(array $plan): void
    {
        $this->WriteAttributeString('Plan', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function readCoverage(): array
    {
        try {
            $value = json_decode($this->ReadAttributeString('CoverageLedger'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $plan */
    private function publishPlan(array $plan): void
    {
        $labels = [self::PLAN_IDLE => 'Ready', self::PLAN_RUNNING => 'Running', self::PLAN_PAUSED => 'Paused', self::PLAN_COMPLETED => 'Completed', self::PLAN_ABORTED => 'Aborted', self::PLAN_ERROR => 'Error'];
        $jobs = is_array($plan['jobs'] ?? null) ? $plan['jobs'] : [];
        $activeNames = [];
        $completed = 0;
        foreach ($jobs as $job) {
            if (($job['status'] ?? '') === 'completed') {
                $completed++;
            }
            if (in_array($job['status'] ?? '', ['waiting_start', 'running'], true)) {
                $activeNames[] = (string) ($job['robotName'] ?? '') . ' → ' . (string) ($job['area'] ?? '');
            }
        }
        $status = (string) ($plan['status'] ?? self::PLAN_IDLE);
        $this->SetValue('PlanStatus', $this->Translate($labels[$status] ?? 'Ready'));
        $this->SetValue('CurrentRobot', implode(', ', array_unique($activeNames)));
        $this->SetValue('CurrentStep', $completed);
        $this->SetValue('TotalSteps', count($jobs));
    }

    /** @param array<string, mixed> $coverage */
    private function publishCoverage(array $coverage): void
    {
        $lines = [];
        foreach ($coverage as $area => $results) {
            if (!is_array($results)) {
                continue;
            }
            foreach (['vacuum' => 'Gesaugt', 'mop' => 'Gewischt'] as $key => $label) {
                $entry = $results[$key] ?? null;
                if (is_array($entry) && (bool) ($entry['done'] ?? false)) {
                    $lines[] = sprintf('%s – %s: %s (%s)', $area, $label, $entry['robotName'], date('d.m.Y H:i', (int) $entry['completedAt']));
                }
            }
        }
        $this->SetValue('Coverage', count($lines) > 0 ? implode("\n", $lines) : $this->Translate('No cleaning recorded yet'));
    }

    private function failPlan(string $message): void
    {
        $plan = $this->readPlan();
        $plan['status'] = self::PLAN_ERROR;
        $plan['error'] = $message;
        $this->writePlan($plan);
        $this->SetTimerInterval('CoordinateTimer', 0);
        $this->SetStatus(self::STATUS_SEQUENCE_ERROR);
        $this->SetValue('LastError', $message);
        $this->publishPlan($plan);
        $this->SendDebug('Coordinator error', $message, 0);
    }

    private function getPollInterval(): int
    {
        return max(10, min(60, $this->ReadPropertyInteger('PollInterval')));
    }

    private function getJobTimeout(): int
    {
        return max(30, min(720, $this->ReadPropertyInteger('StepTimeout'))) * 60;
    }

    private function registerProfiles(): void
    {
        if (IPS_VariableProfileExists('DRMC.Command')) {
            return;
        }
        IPS_CreateVariableProfile('DRMC.Command', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('DRMC.Command', self::COMMAND_NONE, $this->Translate('No command'), '', -1);
        IPS_SetVariableProfileAssociation('DRMC.Command', self::COMMAND_START, $this->Translate('Start'), '', 0x33AA33);
        IPS_SetVariableProfileAssociation('DRMC.Command', self::COMMAND_PAUSE, $this->Translate('Pause'), '', 0xE6A700);
        IPS_SetVariableProfileAssociation('DRMC.Command', self::COMMAND_RESUME, $this->Translate('Continue'), '', 0x3388CC);
        IPS_SetVariableProfileAssociation('DRMC.Command', self::COMMAND_ABORT, $this->Translate('Abort'), '', 0xCC3333);
    }
}
