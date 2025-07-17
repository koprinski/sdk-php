<?php

declare(strict_types=1);

namespace Tapfiliate\Api;

use RuntimeException;
use JsonSerializable;
use Psr\Log\LoggerInterface;
use Tapfiliate\Api\Exceptions\AccessDeniedException;
use Tapfiliate\Api\Exceptions\BadRequestException;
use Tapfiliate\Api\Exceptions\HttpException;
use Tapfiliate\Api\Exceptions\InternalServerErrorException;
use Tapfiliate\Api\Exceptions\MethodNotFoundException;
use Tapfiliate\Api\Exceptions\ServiceUnavailableException;

class ApiClient
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PATCH', 'DELETE', 'PUT'];

    private LoggerInterface $logger;

    private string $apiKey;

    private string $host;

    public function __construct(
        LoggerInterface $logger,
        string $apiKey,
        string $host = 'https://api.tapfiliate.com'
    ) {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->host = $host;
    }

    /**
     * @throws (RuntimeException)
     */
    public function sendRequest(
        string $method,
        string $path,
        array $urlOptions = [],
        JsonSerializable $model = null
    ): ?array {
        if (!in_array($method, self::ALLOWED_METHODS)) {
            throw new RuntimeException('Method is not allowed');
        }
        $url = $this->buildUrl($path, $urlOptions);
        $curlBuilder = new CurlBuilder();
        $curlBuilder
            ->setMethod($method)
            ->setUrl($url)
            ->setHeaders(['Api-Key: ' . $this->apiKey])
        ;

        if ('GET' !== $method && null !== $model) {
            $curlBuilder
                ->setBody(json_encode($model->jsonSerialize()))
            ;
        }

        return $this->sendRequestToApi($curlBuilder->toArray());
    }

    private function buildUrl(string $path, array $urlOptions = []): string
    {
        $url = $this->host . '/' . ApiConfig::API_VERSION . $path;

        if (!empty($urlOptions)) {
            $url .= '?' . http_build_query($urlOptions);
        }

        return $url;
    }

    /**
     * @throws HttpException
     */
    private function sendRequestToApi(array $curlOptions = []): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $result = (string) curl_exec($ch);

        if (curl_error($ch)) {
            $errorCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $message = curl_error($ch);
            $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $dataset = json_encode($curlOptions);
            $this->logger->info('Request to ' . $url . 'with dataset: ' . $dataset);
            $this->thrownExceptionByErrorCode($errorCode, $message);
        }
        curl_close($ch);

        if (empty($result)) {
            return null;
        }

        return json_decode($result, true, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws HttpException
     */
    private function thrownExceptionByErrorCode(int $errorCode, string $message): void
    {
        $arrayOfExceptions = [
            400 => BadRequestException::class,
            403 => AccessDeniedException::class,
            404 => MethodNotFoundException::class,
            500 => InternalServerErrorException::class,
            503 => ServiceUnavailableException::class,
        ];

        if (!array_key_exists($errorCode, $arrayOfExceptions)) {
            throw new HttpException($errorCode, $message);
        }

        throw new $arrayOfExceptions[$errorCode]($errorCode, $message);
    }
}
