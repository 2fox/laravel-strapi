<?php

namespace AwStudio\LaravelStrapi;

use AwStudio\LaravelStrapi\Models\StrapiModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Container\Container;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;

class StrapiQueryBuilder
{
    protected string $endpoint;

    protected array $queryParams = [
        'filters' => [],
        'pagination' => [],
        'sort' => [],
    ];

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

    public function filters(array $filters): static
    {
        $this->queryParams['filters'] = array_merge($this->queryParams['filters'] ?? [], $filters);

        return $this;
    }

    public function where(string $field, string $value, $operator = '$eq'): static
    {
        $this->queryParams['filters'][$field][$operator] = $value;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->queryParams['pagination']['limit'] = $limit;

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $this->queryParams['sort'][] = implode(':', [$field, $direction]);

        return $this;
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = value($perPage, $total) ?? 15;

        if ($columns) {
            $this->queryParams['fields'] = $columns;
        }

        if ($perPage) {
            $this->queryParams['pagination']['pageSize'] = $perPage;
        }

        if ($page) {
            $this->queryParams['pagination']['page'] = $page;
        }

        $response = $this->apiClient->get($this->endpoint, $this->queryParams);

        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items'       => collect($response->data->map(fn ($attributes) => new $this->modelClass($attributes))),
            'total'       => $response->meta['pagination']['total'],
            'perPage'     => $response->meta['pagination']['pageSize'],
            'currentPage' => $response->meta['pagination']['page'],
            'options'     => [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ]);
    }

    public function get()
    {
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

    public function fields($fields = '*'): static
    {
        $this->queryParams['fields'] = $fields;

        return $this;
    }

    public function populate($populate = '*'): static
    {
        if (is_array($populate)){
            foreach ($populate as $key => $value) {
                $this->queryParams['populate'][$key] = $value;
            }
        } else {
            $this->queryParams['populate'] = $populate;
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
