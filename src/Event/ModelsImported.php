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

namespace Hyperf\Scout\Event;

use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\Scout\Searchable;

class ModelsImported
{
    /**
     * The model collection.
     *
     * @param Collection<int, Searchable&Model>
     */
    public $models;

    /**
     * Create a new event instance.
     */
    public function __construct(Collection $models)
    {
        $this->models = $models;
    }
}
