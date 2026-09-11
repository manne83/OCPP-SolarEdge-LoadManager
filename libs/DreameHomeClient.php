<?php

declare(strict_types=1);

/**
 * Small, dependency-free Dreamehome cloud client for IP-Symcon.
 *
 * The API is undocumented and may change without notice. This implementation
 * deliberately covers only authentication, device discovery, basic state and
 * the five basic robot actions used by the module.
 */
final class DreameHomeClient
{
    private const PORT = 13267;
    private const PASSWORD_SALT = 'RAylYC%fmSKp7%Tq';
    private const USER_AGENT = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    private const CLIENT_AUTH = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    private const DEFAULT_TENANT = '000000';
    private const CHINA_AUTH = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    /** @var array<int, array{did:string, siid:int, piid:int}> */
    private const BASIC_PROPERTIES = [
        ['did' => '0', 'siid' => 2, 'piid' => 1],  // state
        ['did' => '1', 'siid' => 2, 'piid' => 2],  // error
        ['did' => '2', 'siid' => 3, 'piid' => 1],  // battery
        ['did' => '3', 'siid' => 3, 'piid' => 2],  // charging status
        ['did' => '5', 'siid' => 4, 'piid' => 1],  // status
        ['did' => '6', 'siid' => 4, 'piid' => 2],  // cleaning time
        ['did' => '7', 'siid' => 4, 'piid' => 3],  // cleaned area
        ['did' => '11', 'siid' => 4, 'piid' => 7], // task status
        ['did' => '27', 'siid' => 4, 'piid' => 23] // cleaning mode (raw/grouped)
    ];

    private string $country;
    private string $username;
    private string $password;
    private string $accessToken;
    private string $refreshToken;
    private int $tokenExpiresAt;
    private string $tenantID;
    private string $userID;
    private string $deviceID = '';
    private string $deviceName = '';
    private string $model = '';
    private string $bindDomain = '';
    private int $requestID;

    public function __construct(
        string $country,
        string $username,
        string $password,
        array $session = []
    ) {
        $country = strtolower(trim($country));
        if ($country !== 'de') {
            throw new InvalidArgumentException('Unsupported Dreamehome region');
        }

        $this->country = $country;
        $this->username = trim($username);
        $this->password = $password;
        $this->accessToken = (string) ($session['accessToken'] ?? '');
        $this->refreshToken = (string) ($session['refreshToken'] ?? '');
        $this->tokenExpiresAt = (int) ($session['tokenExpiresAt'] ?? 0);
        $this->tenantID = (string) ($session['tenantID'] ?? self::DEFAULT_TENANT);
        $this->userID = (string) ($session['userID'] ?? '');
        $this->requestID = random_int(1, 10000);
    }

    public function login(): void
    {
        if ($this->accessToken !== '' && $this->tokenExpiresAt > time() + 60) {
            return;
        }

        if ($this->refreshToken !== '' && $this->authenticate(true)) {
            return;
        }

        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('Dreamehome credentials are missing');
        }

        if (!$this->authenticate(false)) {
            throw new RuntimeException('Dreamehome login failed');
        }
    }

    /** @return array<string, mixed> */
    public function exportSession(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'tokenExpiresAt' => $this->tokenExpiresAt,
            'tenantID' => $this->tenantID,
            'userID' => $this->userID
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getVacuumDevices(): array
    {
        $response = $this->apiRequest('dreame-user-iot/iotuserbind/device/listV2');
        $records = $response['data']['page']['records'] ?? [];
        if (!is_array($records)) {
            throw new RuntimeException('Unexpected device-list response');
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $device): bool => is_array($device)
                && str_contains((string) ($device['model'] ?? ''), '.vacuum.')
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     * @return array<string, mixed>
     */
    public function selectDevice(array $devices, string $requestedDeviceID = ''): array
    {
        $requestedDeviceID = trim($requestedDeviceID);
        if ($requestedDeviceID !== '') {
            foreach ($devices as $device) {
                if ((string) ($device['did'] ?? '') === $requestedDeviceID) {
                    return $device;
                }
            }
            throw new RuntimeException('Configured device ID was not found');
        }

        $x60Devices = array_values(array_filter(
            $devices,
            static function (array $device): bool {
                $text = strtolower(
                    (string) ($device['model'] ?? '') . ' ' .
                    (string) ($device['customName'] ?? '') . ' ' .
                    (string) ($device['deviceInfo']['displayName'] ?? '')
                );
                return str_contains($text, 'x60') || str_contains($text, 'r6001');
            }
        ));

        if (count($x60Devices) === 1) {
            return $x60Devices[0];
        }
        if (count($devices) === 1) {
            return $devices[0];
        }
        if (count($devices) === 0) {
            throw new RuntimeException('No vacuum robot was found');
        }

        throw new RuntimeException('More than one robot was found; configure the device ID');
    }

    /** @param array<string, mixed> $device */
    public function prepareDevice(array $device): void
    {
        $this->deviceID = (string) ($device['did'] ?? '');
        if ($this->deviceID === '') {
            throw new RuntimeException('Robot has no device ID');
        }

        $this->model = (string) ($device['model'] ?? '');
        $this->deviceName = (string) ($device['customName'] ?? '');
        if ($this->deviceName === '') {
            $this->deviceName = (string) ($device['deviceInfo']['displayName'] ?? $this->model);
        }
        $this->bindDomain = (string) ($device['bindDomain'] ?? '');

        $response = $this->apiRequest(
            'dreame-user-iot/iotuserbind/device/info',
            ['did' => $this->deviceID]
        );
        $info = $response['data'] ?? [];
        if (is_array($info)) {
            $this->model = (string) ($info['model'] ?? $this->model);
            $this->bindDomain = (string) ($info['bindDomain'] ?? $this->bindDomain);
            $this->userID = (string) ($info['masterUid'] ?? $this->userID);
        }

        if ($this->bindDomain === '') {
            throw new RuntimeException('Robot cloud endpoint is missing');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getBasicState(): array
    {
        $result = $this->sendCommand('get_properties', self::BASIC_PROPERTIES);
        if (!is_array($result)) {
            throw new RuntimeException('Unexpected robot-state response');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        return $this->action(2, 1);
    }

    /** @return array<string, mixed> */
    public function pause(): array
    {
        return $this->action(2, 2);
    }

    /** @return array<string, mixed> */
    public function returnToDock(): array
    {
        return $this->action(3, 1);
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        return $this->action(4, 2);
    }

    /** @return array<string, mixed> */
    public function locate(): array
    {
        return $this->action(7, 1);
    }

    /** @return array<int, array{id:int, name:string}> */
    public function getShortcuts(): array
    {
        $result = $this->sendCommand('get_properties', [[
            'did' => '52',
            'siid' => 4,
            'piid' => 48
        ]]);
        $raw = $this->findPropertyValue(is_array($result) ? $result : [], '52');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }
        $shortcuts = [];
        foreach ($decoded as $shortcut) {
            if (!is_array($shortcut)) {
                continue;
            }
            $id = (int) ($shortcut['id'] ?? 0);
            $encodedName = (string) ($shortcut['name'] ?? '');
            $name = base64_decode($encodedName, true);
            if ($id >= 25 && $id <= 128) {
                $shortcuts[] = [
                    'id' => $id,
                    'name' => $name !== false && $name !== '' ? $name : ('Shortcut ' . $id)
                ];
            }
        }
        return $shortcuts;
    }

    /** @return array<string, mixed> */
    public function startShortcut(int $shortcutID): array
    {
        if ($shortcutID < 25 || $shortcutID > 128) {
            throw new InvalidArgumentException('Shortcut ID must be between 25 and 128');
        }
        return $this->action(4, 1, [
            ['piid' => 1, 'value' => 25],
            ['piid' => 10, 'value' => (string) $shortcutID]
        ]);
    }

    /** @return array<string, mixed> */
    public function getCurrentMap(): array
    {
        $response = $this->action(6, 1, [[
            'piid' => 2,
            'value' => json_encode([
                'req_type' => 1,
                'frame_type' => 'I',
                'force_type' => 1
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ]]);

        $rawMap = '';
        $objectName = '';
        foreach ($response['out'] ?? [] as $property) {
            if (!is_array($property)) {
                continue;
            }
            if ((int) ($property['piid'] ?? 0) === 1) {
                $rawMap = (string) ($property['value'] ?? '');
            } elseif ((int) ($property['piid'] ?? 0) === 3) {
                $objectName = (string) ($property['value'] ?? '');
            }
        }

        $source = 'direct';
        $mapKey = '';
        $fileName = $objectName;
        if (str_contains($objectName, ',')) {
            [$fileName, $mapKey] = explode(',', $objectName, 2);
        }
        if ($rawMap === '' && $objectName !== '') {
            $rawMap = $this->downloadMapObject($fileName);
            $source = 'cloud-file';
        }
        if ($rawMap === '') {
            throw new RuntimeException('Robot returned neither map data nor a map file');
        }
        $map = $this->decodeMapHeader($rawMap, $mapKey);
        $map['source'] = $source;
        // Do not expose the decryption key which can be appended to the name.
        $map['objectName'] = $fileName;
        return $map;
    }

    /**
     * Set the logical cleaning mode on the X60 family.
     *
     * 0 = vacuum, 1 = mop, 2 = vacuum and mop, 3 = mop after vacuum.
     */
    public function setCleaningMode(int $mode): void
    {
        if (!in_array($mode, [0, 1, 2, 3], true)) {
            throw new InvalidArgumentException('Unsupported cleaning mode');
        }

        $rawMode = $mode;
        if ($this->isX60Family()) {
            $properties = $this->getBasicState();
            $currentRaw = $this->findPropertyValue($properties, '27');
            if ($currentRaw === null) {
                throw new RuntimeException('Robot did not report its cleaning mode');
            }

            // X60 stores mode, mop-wash interval and mop humidity in one value.
            // Preserve the upper fields and only replace the two mode bits.
            $encodedMode = match ($mode) {
                0 => 2,
                1 => 1,
                2 => 0,
                3 => 3
            };
            $rawMode = (((int) $currentRaw) & ~0x03) | $encodedMode;
        }

        $result = $this->sendCommand('set_properties', [[
            'did' => $this->deviceID,
            'siid' => 4,
            'piid' => 23,
            'value' => $rawMode
        ]]);
        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? [];
        }
        if (!is_array($result) || (int) ($result['code'] ?? -1) !== 0) {
            throw new RuntimeException('Robot rejected the cleaning mode');
        }
    }

    public function getDeviceID(): string
    {
        return $this->deviceID;
    }

    public function getDeviceName(): string
    {
        return $this->deviceName;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function authenticate(bool $withRefreshToken): bool
    {
        $grant = $withRefreshToken ? 'refresh_token' : 'password';
        $fields = [
            'platform' => 'IOS',
            'scope' => 'all',
            'grant_type' => $grant
        ];
        if ($withRefreshToken) {
            $fields['refresh_token'] = $this->refreshToken;
        } else {
            $fields['username'] = $this->username;
            $fields['password'] = md5($this->password . self::PASSWORD_SALT);
            $fields['type'] = 'account';
        }

        [$status, $body] = $this->httpPost(
            $this->getBaseURL() . '/dreame-auth/oauth/token',
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            $this->loginHeaders()
        );

        if ($status !== 200) {
            if ($withRefreshToken) {
                $this->accessToken = '';
                $this->refreshToken = '';
                $this->tokenExpiresAt = 0;
            }
            return false;
        }

        $data = $this->decodeJSON($body);
        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            return false;
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = (string) ($data['refresh_token'] ?? $this->refreshToken);
        $this->tokenExpiresAt = time() + max(60, (int) ($data['expires_in'] ?? 3600)) - 120;
        $this->userID = (string) ($data['uid'] ?? $this->userID);
        $this->tenantID = (string) ($data['tenant_id'] ?? $this->tenantID);
        return true;
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return array<string, mixed>
     */
    private function apiRequest(string $path, ?array $parameters = null, bool $allowReauthentication = true): array
    {
        $this->login();
        $payload = $parameters === null
            ? ''
            : json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        [$status, $body] = $this->httpPost(
            $this->getBaseURL() . '/' . ltrim($path, '/'),
            $payload,
            $this->apiHeaders()
        );

        if ($status === 401 && $allowReauthentication && $this->refreshToken !== '') {
            $this->accessToken = '';
            $this->tokenExpiresAt = 0;
            $this->login();
            return $this->apiRequest($path, $parameters, false);
        }
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Dreamehome returned HTTP %d', $status));
        }

        $data = $this->decodeJSON($body);
        if (isset($data['code']) && (int) $data['code'] !== 0) {
            throw new RuntimeException(sprintf('Dreamehome returned API code %d', (int) $data['code']));
        }
        return $data;
    }

    /**
     * @param mixed $parameters
     * @return mixed
     */
    private function sendCommand(string $method, mixed $parameters): mixed
    {
        if ($this->deviceID === '' || $this->bindDomain === '') {
            throw new LogicException('Robot has not been prepared');
        }

        $id = ++$this->requestID;
        $payload = [
            'did' => $this->deviceID,
            'id' => $id,
            'data' => [
                'did' => $this->deviceID,
                'id' => $id,
                'method' => $method,
                'params' => $parameters
            ]
        ];

        $hostPrefix = explode('.', $this->bindDomain)[0];
        $suffix = $hostPrefix !== '' ? '-' . $hostPrefix : '';
        $response = $this->apiRequest('dreame-iot-com' . $suffix . '/device/sendCommand', $payload);
        if (!array_key_exists('result', $response['data'] ?? [])) {
            throw new RuntimeException('Robot command returned no result');
        }
        return $response['data']['result'];
    }

    /** @return array<string, mixed> */
    private function action(int $siid, int $aiid, array $input = []): array
    {
        $result = $this->sendCommand('action', [
            'did' => $this->deviceID,
            'siid' => $siid,
            'aiid' => $aiid,
            'in' => $input
        ]);
        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? [];
        }
        if (!is_array($result) || (int) ($result['code'] ?? -1) !== 0) {
            throw new RuntimeException('Robot rejected the command');
        }
        return $result;
    }

    private function isX60Family(): bool
    {
        $model = strtolower($this->model);
        return str_contains($model, 'r6001') || str_contains($model, 'x60');
    }

    private function downloadMapObject(string $objectName): string
    {
        $response = $this->apiRequest('dreame-user-iot/iotfile/getOss1dDownloadUrl', [
            'did' => $this->deviceID,
            'model' => $this->model,
            'filename' => $objectName,
            'region' => $this->country
        ]);
        $url = $response['data'] ?? '';
        if (is_array($url)) {
            $url = $url['url'] ?? $url['downloadUrl'] ?? '';
        }
        if (!is_string($url) || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('Map download URL is missing');
        }
        return $this->httpGet($url);
    }

    /** @return array<string, mixed> */
    private function decodeMapHeader(string $encodedMap, string $mapKey = ''): array
    {
        $encodedMap = trim($encodedMap);
        if (str_contains($encodedMap, ',')) {
            [$encodedMap, $embeddedKey] = explode(',', $encodedMap, 2);
            if ($mapKey === '') {
                $mapKey = $embeddedKey;
            }
        }
        $encodedMap = strtr($encodedMap, '-_', '+/');
        $padding = strlen($encodedMap) % 4;
        if ($padding > 0) {
            $encodedMap .= str_repeat('=', 4 - $padding);
        }
        $compressed = base64_decode($encodedMap, true);
        if ($compressed === false) {
            throw new RuntimeException('Map data is not valid Base64');
        }
        if ($mapKey !== '') {
            $compressed = $this->decryptMap($compressed, $mapKey);
        }
        $raw = @gzuncompress($compressed);
        if ($raw === false) {
            $raw = @zlib_decode($compressed);
        }
        if (!is_string($raw) || strlen($raw) < 27) {
            throw new RuntimeException('Map data could not be decompressed');
        }

        $readInt16 = static function (string $data, int $offset): int {
            $value = unpack('v', substr($data, $offset, 2))[1];
            return $value >= 0x8000 ? $value - 0x10000 : $value;
        };
        $width = $readInt16($raw, 19);
        $height = $readInt16($raw, 21);
        if ($width <= 0 || $height <= 0 || 27 + ($width * $height) > strlen($raw)) {
            throw new RuntimeException('Map dimensions are invalid');
        }
        $json = [];
        $jsonOffset = 27 + ($width * $height);
        if (strlen($raw) > $jsonOffset) {
            $decodedJSON = json_decode(substr($raw, $jsonOffset), true);
            $json = is_array($decodedJSON) ? $decodedJSON : [];
        }
        $left = $readInt16($raw, 23);
        $top = $readInt16($raw, 25);
        if (isset($json['origin']) && is_array($json['origin']) && count($json['origin']) >= 2) {
            $left = (int) $json['origin'][0];
            $top = (int) $json['origin'][1];
        }
        return [
            'mapID' => $readInt16($raw, 0),
            'frameID' => $readInt16($raw, 2),
            'frameType' => ord($raw[4]),
            'robot' => ['x' => $readInt16($raw, 5), 'y' => $readInt16($raw, 7), 'angle' => $readInt16($raw, 9)],
            'charger' => ['x' => $readInt16($raw, 11), 'y' => $readInt16($raw, 13), 'angle' => $readInt16($raw, 15)],
            'gridSize' => $readInt16($raw, 17),
            'width' => $width,
            'height' => $height,
            'left' => $left,
            'top' => $top,
            'pixels' => base64_encode(substr($raw, 27, $width * $height)),
            'metadata' => $json
        ];
    }

    private function decryptMap(string $encryptedMap, string $mapKey): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('The PHP OpenSSL extension is required for X60 maps');
        }

        $iv = $this->getMapEncryptionIV();
        $key = substr(hash('sha256', $mapKey), 0, 32);
        $decrypted = openssl_decrypt($encryptedMap, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            $decrypted = openssl_decrypt(
                $encryptedMap,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $iv
            );
        }
        if (!is_string($decrypted) || $decrypted === '') {
            throw new RuntimeException('X60 map could not be decrypted');
        }
        return $decrypted;
    }

    private function getMapEncryptionIV(): string
    {
        $model = strtolower($this->model);
        if (str_contains($model, 'r6001a') || str_contains($model, 'x60')) {
            return 'NRwnBj5FsNPgBNbT';
        }
        throw new RuntimeException('Map encryption is not known for model ' . $this->model);
    }

    /** @param array<int, array<string, mixed>> $properties */
    private function findPropertyValue(array $properties, string $did): mixed
    {
        foreach ($properties as $property) {
            if (
                is_array($property)
                && (string) ($property['did'] ?? '') === $did
                && (int) ($property['code'] ?? 0) === 0
            ) {
                return $property['value'] ?? null;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    private function loginHeaders(): array
    {
        $headers = [
            'Accept' => '*/*',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept-Language' => 'en-US;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'User-Agent' => self::USER_AGENT,
            'Dreame-Rlc' => self::CLIENT_AUTH,
            'Tenant-Id' => $this->tenantID !== '' ? $this->tenantID : self::DEFAULT_TENANT
        ];
        if ($this->country === 'cn') {
            $headers['Dreame-Auth'] = self::CHINA_AUTH;
        }
        return $headers;
    }

    /** @return array<string, string> */
    private function apiHeaders(): array
    {
        return [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
            'Accept-Language' => 'en-US;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'User-Agent' => self::USER_AGENT,
            'Dreame-Rlc' => self::CLIENT_AUTH,
            'Tenant-Id' => $this->tenantID !== '' ? $this->tenantID : self::DEFAULT_TENANT,
            'Authorization' => $this->accessToken
        ];
    }

    private function getBaseURL(): string
    {
        return sprintf('https://%s.iot.dreame.tech:%d', $this->country, self::PORT);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0:int, 1:string}
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not available');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize HTTPS connection');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Dreamehome connection failed: ' . $error);
        }
        return [$status, (string) $response];
    }

    private function httpGet(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize map download');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false || $status !== 200) {
            throw new RuntimeException('Map download failed: ' . ($error !== '' ? $error : ('HTTP ' . $status)));
        }
        return (string) $response;
    }

    /** @return array<string, mixed> */
    private function decodeJSON(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Dreamehome returned invalid JSON', 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Dreamehome returned an invalid response');
        }
        return $data;
    }
}
