<?php

namespace AwStudio\LaravelStrapi;

use AwStudio\LaravelStrapi\Models\StrapiModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StrapiQueryBuilder
{
    protected string $endpoint;

    protected array $queryParams = [];

    protected StrapiApiClient $apiClient;

    protected string $modelClass;

    public function __construct(string $endpoint, string $modelClass)
    {
        $this->endpoint = $endpoint;
        $this->apiClient = new StrapiApiClient;
        $this->modelClass = $modelClass;

        $modelInstance = new $modelClass;
        if ($populate = $modelInstance->populate) {
            $this->populate($populate);
        }
    }

    public function where(string $field, string $value): static
    {
        $this->queryParams['filters'][$field]['$eq'] = $value;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->queryParams['pagination']['limit'] = $limit;

        return $this;
    }

    public function get()
    {
        $locale = $this->queryParams['locale'] ?? null;

        if (!$locale && config('laravel-strapi.locale.use_website_locale')) {
            $locale = app()->getLocale();
        }

        if ($locale) {
            $this->queryParams['locale'] = config("laravel-strapi.locale.aliases.$locale", $locale);
        }
        
        $response = $this->apiClient->get($this->endpoint, $this->queryParams);

        // If data is an array (SingleType), return a single model instance
        if (is_array($response->data)) {
            return new $this->modelClass($response->data);
        }

        // Otherwise, assume it's a CollectionType and return a Collection of models
        return $response->data->map(fn ($attributes) => new $this->modelClass($attributes));
    }

    public function first(): ?StrapiModel
    {
        return $this->limit(1)->get()->first();
    }

    public function locale(string $locale): static
    {
        $this->queryParams['locale'] = $locale;

        return $this;
    }

    public function populate(array $populate): static
    {
        foreach ($populate as $key => $value) {
            $this->queryParams['populate'][$key] = $value;
        }

        return $this;
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return $this->$name(...$arguments);
        }

        if (Str::startsWith($name, 'populate')) {
            $key = Str::of($name)->after('populate')->toString();
            $components = Arr::get(config('laravel-strapi.components'), $key);

            foreach ($components as $component) {
                $component = new $component;
                $kebabKey = Str::of($key)->kebab()->toString();

                $componentKey = $component->getKey();

                $this->queryParams['populate'][$key]['on'][$kebabKey.'.'.$componentKey]['populate'] = $component->populate;
            }

            return $this;
        }
    }
}
