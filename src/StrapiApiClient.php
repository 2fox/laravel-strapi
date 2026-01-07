<?php

namespace AwStudio\LaravelStrapi;

use AwStudio\LaravelStrapi\Responses\StrapiResponse;
use Illuminate\Support\Facades\Http;

class StrapiApiClient
{
    protected string $baseUrl;

    protected array $headers = [];

    public function __construct()
    {
        // set base URL from config and remove trailing slash
        $this->baseUrl = rtrim(config('laravel-strapi.base_url'), '/');

        if (! $this->baseUrl) {
            throw new \Exception('Strapi base URL is not set in the config file.');
        }

        $this->headers = array_merge(
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            config('laravel-strapi.headers', [])
        );
    }

    /**
     * Send a GET request to Strapi.
     */
    public function get(string $endpoint, array $queryParams = []): StrapiResponse
    {
        $locale = $queryParams['locale'] ?? null;

        if (!$locale && config('laravel-strapi.locale.use_website_locale')) {
            $locale = app()->getLocale();
        }

        if ($locale) {
            $queryParams['locale'] = config("laravel-strapi.locale.aliases.$locale", $locale);
        }
        
        // Check if caching is enabled in config
        if (config('laravel-strapi.caching.active')) {
            $cacheKey = $this->generateCacheKey($endpoint, $queryParams);
            $ttl = config('laravel-strapi.caching.ttl', 3600); // Default TTL: 1 hour

            $response = cache()->remember($cacheKey, $ttl, function () use ($endpoint, $queryParams) {
                return $this->fetchFromStrapi($endpoint, $queryParams);
            });

            return new StrapiResponse($response);
        }

        // If caching is disabled, fetch directly
        return new StrapiResponse($this->fetchFromStrapi($endpoint, $queryParams));
    }

    private function generateCacheKey(string $endpoint, array $queryParams): string
    {
        return 'strapi:'.md5($endpoint.json_encode($queryParams));
    }

    private function fetchFromStrapi(string $endpoint, array $queryParams)
    {
        $response = Http::withHeaders($this->headers)
            ->get("{$this->baseUrl}/api/{$endpoint}", $queryParams);

        if ($response->failed()) {
            throw new \Exception('Error fetching data from Strapi: '.$response->body());
        }

        return $response->json();
    }
}
