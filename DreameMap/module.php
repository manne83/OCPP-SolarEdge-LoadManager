<?php

declare(strict_types=1);

class DreameMap extends IPSModuleStrict
{
    private const ROBOT_MODULE_ID = '{6B87C506-2B66-48BB-8E1C-EA41DDE8D353}';
    private const STATUS_INVALID_CONFIGURATION = 200;
    private const STATUS_MAP_ERROR = 201;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('Robots', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterTimer('MapTimer', 0, "DRMM_Update(\$_IPS['TARGET']);");
        $this->RegisterVariableString('MapView', $this->Translate('Live maps'), '~HTMLBox', 10);
        $this->RegisterVariableString('MapStatus', $this->Translate('Map status'), '', 20);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last map update'), '~UnixTimestamp', 30);
        $this->SetValue('MapStatus', $this->Translate('Ready'));
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('MapTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        try {
            $this->getRobots();
        } catch (Throwable $exception) {
            $this->SetTimerInterval('MapTimer', 0);
            $this->SetValue('MapStatus', $exception->getMessage());
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }
        $this->SetTimerInterval('MapTimer', $this->getUpdateInterval() * 1000);
        $this->SetStatus(IS_ACTIVE);
        $this->Update();
    }

    public function Update(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        $semaphore = 'DRMM.Update.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            return false;
        }
        try {
            $cards = [];
            $failures = 0;
            foreach ($this->getRobots() as $robot) {
                try {
                    $map = json_decode(DRM_GetCurrentMap($robot['instanceID']), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($map) || isset($map['error'])) {
                        throw new RuntimeException((string) ($map['error'] ?? 'Ungültige Kartendaten'));
                    }
                    $cards[] = $this->renderMapCard($map, $robot);
                } catch (Throwable $exception) {
                    $failures++;
                    $cards[] = $this->renderErrorCard($robot, $exception->getMessage());
                    $this->SendDebug('Map failed', $exception->getMessage(), 0);
                }
            }
            $this->SetValue('MapView', $this->renderDashboard($cards));
            $this->SetValue('LastUpdate', time());
            $this->SetValue('MapStatus', $failures > 0 ? $this->Translate('Partial failure') : $this->Translate('Updated'));
            $this->SetStatus($failures === count($cards) ? self::STATUS_MAP_ERROR : IS_ACTIVE);
            return $failures === 0;
        } catch (Throwable $exception) {
            $this->SetValue('MapStatus', $exception->getMessage());
            $this->SetStatus(self::STATUS_MAP_ERROR);
            return false;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    /** @return array<int, array{instanceID:int, name:string, color:string}> */
    private function getRobots(): array
    {
        $rows = json_decode($this->ReadPropertyString('Robots'), true, 512, JSON_THROW_ON_ERROR);
        $robots = [];
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $robotID = (int) ($row['InstanceID'] ?? 0);
            if ($robotID <= 0) {
                continue;
            }
            if (!IPS_InstanceExists($robotID)) {
                throw new RuntimeException('Eine Roboter-Instanz existiert nicht.');
            }
            $instance = IPS_GetInstance($robotID);
            if ((string) ($instance['ModuleInfo']['ModuleID'] ?? '') !== self::ROBOT_MODULE_ID || isset($ids[$robotID])) {
                throw new RuntimeException('Ungültige oder doppelte Dreame-Roboter-Instanz.');
            }
            $ids[$robotID] = true;
            $color = strtoupper(trim((string) ($row['Color'] ?? '#E53935')));
            if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                $color = '#E53935';
            }
            $name = trim((string) ($row['Name'] ?? ''));
            $robots[] = ['instanceID' => $robotID, 'name' => $name !== '' ? $name : IPS_GetName($robotID), 'color' => $color];
        }
        if (count($robots) === 0) {
            throw new RuntimeException('Mindestens ein Roboter ist erforderlich.');
        }
        return $robots;
    }

    /** @param array<string, mixed> $map @param array<string, mixed> $robot */
    private function renderMapCard(array $map, array $robot): string
    {
        $width = (int) $map['width'];
        $height = (int) $map['height'];
        $pixels = base64_decode((string) $map['pixels'], true);
        if ($width <= 0 || $height <= 0 || $pixels === false || strlen($pixels) < $width * $height) {
            throw new RuntimeException('Kartenbild ist unvollständig.');
        }
        $rectangles = [];
        for ($y = 0; $y < $height; $y++) {
            $runStart = -1;
            $runColor = '';
            for ($x = 0; $x <= $width; $x++) {
                $color = $x < $width ? $this->pixelColor(ord($pixels[($y * $width) + $x])) : '';
                if ($color !== $runColor) {
                    if ($runStart >= 0 && $runColor !== '') {
                        $rectangles[] = sprintf('<rect x="%d" y="%d" width="%d" height="1" fill="%s"/>', $runStart, $y, $x - $runStart, $runColor);
                    }
                    $runStart = $color !== '' ? $x : -1;
                    $runColor = $color;
                }
            }
        }
        $grid = max(1, (int) $map['gridSize']);
        $robotX = ((int) $map['robot']['x'] - (int) $map['left']) / $grid;
        $robotY = ((int) $map['robot']['y'] - (int) $map['top']) / $grid;
        $chargerX = ((int) $map['charger']['x'] - (int) $map['left']) / $grid;
        $chargerY = ((int) $map['charger']['y'] - (int) $map['top']) / $grid;
        $marker = '';
        if ($robotX >= 0 && $robotX <= $width && $robotY >= 0 && $robotY <= $height) {
            $marker .= sprintf('<circle cx="%.2f" cy="%.2f" r="4" fill="%s" stroke="#fff" stroke-width="1.5"/><path d="M %.2f %.2f l 7 0" stroke="%s" stroke-width="2"/>', $robotX, $robotY, $robot['color'], $robotX, $robotY, $robot['color']);
        }
        if ($chargerX >= 0 && $chargerX <= $width && $chargerY >= 0 && $chargerY <= $height) {
            $marker .= sprintf('<rect x="%.2f" y="%.2f" width="5" height="5" rx="1" fill="#1565C0" stroke="#fff" stroke-width="1"/>', $chargerX - 2.5, $chargerY - 2.5);
        }
        $title = htmlspecialchars((string) $robot['name'], ENT_QUOTES);
        $state = htmlspecialchars((string) ($map['state'] ?? ''), ENT_QUOTES);
        return '<section class="card"><header><span class="dot" style="background:' . $robot['color'] . '"></span><strong>' . $title . '</strong><small>' . $state . '</small></header>'
            . '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="xMidYMid meet">'
            . '<rect width="100%" height="100%" fill="#101418"/>' . implode('', $rectangles) . $marker . '</svg>'
            . '<footer>Position: ' . (int) $map['robot']['x'] . ' / ' . (int) $map['robot']['y'] . ' · Quelle: ' . htmlspecialchars((string) $map['source'], ENT_QUOTES) . '</footer></section>';
    }

    private function pixelColor(int $pixel): string
    {
        if ($pixel === 0) {
            return '';
        }
        $segment = $pixel & 0x1F;
        if (($pixel & 0x60) !== 0 || $segment === 31) {
            return '#D7DEE5';
        }
        $colors = ['#29434E', '#365A65', '#406E78', '#4B7F87', '#568F95', '#617C91', '#536D82', '#46667A'];
        return $colors[$segment % count($colors)];
    }

    /** @param array<string, mixed> $robot */
    private function renderErrorCard(array $robot, string $message): string
    {
        return '<section class="card error"><header><strong>' . htmlspecialchars((string) $robot['name'], ENT_QUOTES) . '</strong></header><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></section>';
    }

    /** @param array<int, string> $cards */
    private function renderDashboard(array $cards): string
    {
        return '<style>.dreame-map{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#e8edf2;background:#0b0e11;padding:14px;border-radius:12px}.dreame-map h3{margin:0 0 12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}.card{background:#171c21;border:1px solid #2b333b;border-radius:10px;padding:10px}.card header{display:flex;gap:8px;align-items:center;margin-bottom:8px}.card header small{margin-left:auto;color:#9aa7b2}.dot{width:12px;height:12px;border-radius:50%}.card svg{width:100%;height:390px;border-radius:7px;background:#101418}.card footer{font-size:12px;color:#9aa7b2;margin-top:7px}.error{border-color:#8b3030}.error p{color:#ffb3b3}</style><div class="dreame-map"><h3>'
            . htmlspecialchars($this->Translate('Live maps'), ENT_QUOTES) . '</h3><div class="grid">' . implode('', $cards) . '</div></div>';
    }

    private function getUpdateInterval(): int
    {
        return max(30, min(600, $this->ReadPropertyInteger('UpdateInterval')));
    }
}
