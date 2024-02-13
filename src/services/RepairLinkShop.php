<?php

namespace src\services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class RepairLinkShop implements VinInfoInterface
{
    const COOKIES_CACHE_PATH = __DIR__ . '/../../runtime/cookies.cache';

    private string $loginUrl = '/Account/Login';
    private string $logoutUrl = '/Account/Logout';
    private string $catalogUrl = '/AutomotiveCatalog/Catalog';
    private string $vehicleSelectionUrl = '/Vehicle/VehicleSelection';
    private string $vinAttributesUrl = '/Vehicle/GetVinAttribute';

    private string $username;
    private string $password;

    private Client $client;
    private CookieJar $cookieJar;

    public function __construct($config)
    {
        $baseUrl = $config['baseUrl'];

        $this->username = $config['login'];
        $this->password = $config['password'];

        $this->loginUrl = $baseUrl . $this->loginUrl;
        $this->vinAttributesUrl = $baseUrl . $this->vinAttributesUrl;
        $this->logoutUrl = $baseUrl . $this->logoutUrl;
        $this->catalogUrl = $baseUrl . $this->catalogUrl;
        $this->vehicleSelectionUrl = $baseUrl . $this->vehicleSelectionUrl;

        $cookieJar = $this->loadCookies();
        if ($cookieJar) {
            $this->cookieJar = $cookieJar;
        } else {
            $this->cookieJar = new CookieJar();
        }

        $this->client = new Client([
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => true,
                'protocols' => ['https'],
                'cookies' => true,
            ],
            'cookies' => $this->cookieJar
        ]);
    }


    /**
     * @inheritDoc
     */
    public function getVinInfo($vin): array
    {
        try {
            if (!$this->isCookiesValid()) {
                $loginResponse = $this->login();

                if ($loginResponse['status'] !== 200) {
                    return $loginResponse;
                }
            }
            $this->isCookiesValid();
            return $this->getVinAttributes($vin);
        } catch (Exception|Throwable $e) {
            var_dump($e->getTraceAsString());
            return ['status' => 300, 'description' => $e->getMessage(), 'vin' => $vin, 'vinInfo' => []];
        }
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    private function login(): array
    {
        $response = $this->client->request('POST', $this->loginUrl, [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            ],
            'form_params' => [
                'UserName' => $this->username,
                'Password' => $this->password,
                'RememberUsername' => 'false',
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200 || $statusCode === 302) {
            $this->saveCookies();

            return ['status' => 200, 'description' => '', 'vin' => '', 'vinInfo' => []];
        } else {
            return ['status' => 300, 'description' => 'Failed to login', 'vin' => '', 'vinInfo' => []];
        }
    }

    /**
     * @param $vin
     * @return array
     * @throws GuzzleException
     * @throws Exception
     */
    private function getVinAttributes($vin): array
    {
        $catalogOemId = $this->getCatalogOemId($vin);
        $payload = [
            'currentVehicle' => [
                'CatalogOemId' => $catalogOemId,
                'VIN' => $vin,
            ],
        ];

        $response = $this->client->request('POST', $this->vinAttributesUrl, [
            'json' => $payload,
        ]);

        $vinInfo = $this->parseVinAttributes($response);

        return ['status' => 200, 'description' => '', 'vin' => $vin, 'vinInfo' => $vinInfo];
    }

    /**
     * @return bool
     */
    private function isCookiesValid(): bool
    {
        foreach ($this->cookieJar as $cookie) {
            if ($cookie->getExpires() !== null) {
                $currentTimestamp = time();
                $expirationTimestamp = $cookie->getExpires();

                if ($expirationTimestamp > $currentTimestamp) {
                    return true;
                }
            }
        }


        if (file_exists(self::COOKIES_CACHE_PATH)) {
            unlink(self::COOKIES_CACHE_PATH);
        }
        return false;
    }

    /**
     * Парсинг HTML для получения данных об атрибутах
     *
     * @param ResponseInterface $response
     * @return array
     */
    private function parseVinAttributes(ResponseInterface $response): array
    {
        $crawler = new Crawler($response->getBody()->getContents());

        $vinInfo = [];
        $crawler->filter('.vinAttributes .Attributes dt')->each(function (Crawler $dt, $i) use ($crawler, &$vinInfo) {
            $label = $dt->filter('label')->text();
            $label = trim($label, ':');
            $value = $crawler->filter('.vinAttributes .Attributes dd')->eq($i)->text();
            $vinInfo[$label] = $value;
        });
        return $vinInfo;
    }


    /**
     * @param $vin
     * @return string|null
     * @throws GuzzleException
     * @throws Exception
     */
    function getCatalogOemId($vin): ?string
    {
        $this->sendVehicleSelection($vin);
        $response = $this->client->request('GET', $this->catalogUrl);

        $crawler = new Crawler($response->getBody()->getContents());
        $inputElement = $crawler->filter('input[id=catalogOemId]')->first();

        if ($inputElement->count() == 0) {
            throw new Exception('Element not found');
        }

        return $inputElement->attr('value');
    }


    /**
     * Отправить VIN для определения в последствии сервером Id нужного OEM каталога для конкретного VIN
     *
     * @param $vin
     * @return void
     * @throws GuzzleException
     * @throws Exception
     */
    private function sendVehicleSelection($vin): void
    {
        $payload = [
            'form_params' => [
                'VinNumber' => $vin,
                'VinNumberList' => [],
            ],
        ];
        $response = $this->client->request('POST', $this->vehicleSelectionUrl, $payload);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('Cannot send VehicleSelection request');
        }
    }

    /**
     * @return bool|CookieJar
     */
    private function loadCookies(): bool|CookieJar
    {
        if (!file_exists(self::COOKIES_CACHE_PATH)) {
            return false;
        }
        $content = file_get_contents(self::COOKIES_CACHE_PATH);
        if (empty($content)) {
            return false;
        }
        /** @var CookieJar $cookieJar */
        $cookieJar = unserialize($content);
        if (!$cookieJar) {
            return false;
        }

        return $cookieJar;
    }

    /**
     * @return void
     */
    private function saveCookies(): void
    {
        $serializedCookies = serialize($this->cookieJar);
        file_put_contents(self::COOKIES_CACHE_PATH, $serializedCookies);
    }

}

