<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Scout\Engine;

use Elasticsearch\Client;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\Scout\Builder;
use Hyperf\Scout\Searchable;
use Hyperf\Scout\SearchableInterface;
use Hyperf\Utils\Collection as BaseCollection;
use Throwable;

class ElasticsearchEngine extends Engine
{
    /**
     * Elastic server version.
     *
     * @var string
     */
    public static $version;

    /**
     * Index where the models will be saved.
     *
     * @var null|string
     */
    protected $index;

    /**
     * Elastic where the instance of Elastic|\Elasticsearch\Client is stored.
     *
     * @var Elastic
     */
    protected $elastic;

    /**
     * Create a new engine instance.
     */
    public function __construct(Client $client, ?string $index = null)
    {
        $this->elastic = $client;
        if ($index) {
            $this->index = $this->initIndex($client, $index);
        }
    }

    protected function initIndex(Client $client, string $index): ?string
    {
        if (!static::$version) {
            try {
                static::$version = $client->info()['version']['number'];
            } catch (Throwable $exception) {
                static::$version = '0.0.0';
            }
        }

        // When the version of elasticsearch is more than 7.0.0, it does not support type, so set `null` to `$index`.
        if (version_compare(static::$version, '7.0.0', '<')) {
            return $index;
        }

        return null;
    }

    /**
     * Update the given model in the index.
     *
     * @param Collection<int, Searchable&Model> $models
     */
    public function update($models): void
    {
        $params['body'] = [];
        $models->each(function ($model) use (&$params) {
            if ($this->index) {
                $update = [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                ];
            } else {
                $update = [
                    '_id' => $model->getKey(),
                    '_index' => $model->searchableAs(),
                ];
            }
            $params['body'][] = ['update' => $update];
            $params['body'][] = [
                'doc' => $model->toSearchableArray(),
                'doc_as_upsert' => true,
            ];
        });
        $this->elastic->bulk($params);
    }

    /**
     * Remove the given model from the index.
     *
     * @param Collection<int, Searchable&Model> $models
     */
    public function delete($models): void
    {
        $params['body'] = [];
        $models->each(function ($model) use (&$params) {
            if ($this->index) {
                $delete = [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                ];
            } else {
                $delete = [
                    '_id' => $model->getKey(),
                    '_index' => $model->searchableAs(),
                ];
            }
            $params['body'][] = ['delete' => $delete];
        });
        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type' => $builder->index ?: $builder->model->searchableAs(),
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [['query_string' => ['query' => "*{$builder->query}*"]]],
                    ],
                ],
            ],
        ];
        if (!$this->index) {
            unset($params['type']);
            $params['index'] = $builder->index ?: $builder->model->searchableAs();
        }
        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }
        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }
        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }
        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge(
                $params['body']['query']['bool']['must'],
                $options['numericFilters']
            );
        }
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elastic,
                $builder->query,
                $params
            );
        }
        return $this->elastic->search($params);
    }

    /**
     * Generates the sort if theres any.
     *
     * @param Builder $builder
     * @return null|array
     */
    protected function sort($builder)
    {
        if (count($builder->orders) == 0) {
            return null;
        }
        return collect($builder->orders)->map(function ($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }

    /**
     * Get the filter array for the query.
     *
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            if (is_array($value)) {
                return ['terms' => [$key => $value]];
            }
            return ['match_phrase' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param int $perPage
     * @param int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from' => (($page * $perPage) - $perPage),
            'size' => $perPage,
        ]);
        $result['nbPages'] = $this->getTotalCount($result) / $perPage;
        return $result;
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     */
    public function getTotalCount($results): int
    {
        $total = $results['hits']['total'];
        if (is_array($total)) {
            return $results['hits']['total']['value'];
        }

        return $total;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     */
    public function mapIds($results): BaseCollection
    {
        return (new Collection($results['hits']['hits']))->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param mixed $results
     * @param Model|SearchableInterface $model
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }
        $keys = collect($results['hits']['hits'])->pluck('_id')->values()->all();
        return $model->getScoutModelsByIds(
            $builder,
            $keys
        )->filter(function ($model) use ($keys) {
            return in_array($model->getScoutKey(), $keys);
        });
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        // @phpstan-ignore-next-line
        $model->newQuery()
            ->orderBy($model->getKeyName())
            ->unsearchable();
    }

    public function regenStruct(Model $model): void
    {
        $index = $model->searchableAs();
        if ($this->elastic->indices()->exists($index)) {
            $this->dropStruct($model);
        }
        $this->createStruct($model);
    }

    public function dropStruct(Model $model): void
    {
        $index = $model->searchableAs();
        $this->elastic->indices()->delete([
            'index' => $index
        ]);
    }

    /**
     * @param Model $model
     */
    public function createStruct(Model $model): void
    {
        $index = $model->searchableAs();

        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'number_of_shards' => 3,
                    'number_of_replicas' => 2
                ],
                'mappings' => [
                    '_source' => [
                        'enabled' => false
                    ],
                    'properties' => [
                    ]
                ]
            ]
        ];

        $object = $model->searchableStruct();

        // 如果为空则开启原文档存储，不配置任何字段
        if (empty($object)) {
            $params['body']['mappings']['__source']['enabled'] = true;
            // 如果存在 settings 和 mappings 则覆盖 body
        } else if (array_key_exists('properties', $object)) {
            $params['body']['mappings'] = $object;
            // 其他情况则设置为 body
        } else {
            $params['body'] = $object;
        }

        $object['index'] = $index;

        $this->elastic->indices()->create($params);
    }
}
