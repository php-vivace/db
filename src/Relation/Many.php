<?php
/**
 * Created by PhpStorm.
 * User: macbookpro
 * Date: 18.04.2018
 * Time: 23:21
 */

namespace vivace\db\Relation;


use vivace\db\mixin;
use vivace\db\Relation;
use vivace\db\Filtrable;
use vivace\db\Storage;

abstract class Many implements Filtrable, Relation
{
    use mixin\Filter;
    /**
     * @var \vivace\db\Storage
     */
    protected $storage;
    /**
     * @var array
     */
    protected $key;


    public function __construct(Storage $storage, array $key)
    {
        $this->storage = $storage;
        $this->key = $key;
    }

    function populate(iterable $items, string $field): array
    {
        $map = [];
        $simpleKey = count($this->key) == 1;
        $finder = $this->storage->find();
        if ($this->filter) {
            $finder = $finder->filter($this->filter);
        }

        if ($simpleKey) {
            $internal = key($this->key);
            $external = current($this->key);
            $filter = [];
            foreach ($items as &$item) {
                $filter[] = $item[$internal];
                $map[$item[$internal]][] = &$item;
            }
            $filter = ['in', $external, array_unique($filter)];

            $founds = $finder->and($filter)->fetch()->all();

            foreach ($founds as $found) {
                $idx = $found[$external];
                if (isset($map[$idx])) {
                    foreach ($map[$idx] as &$item) {
                        $item[$field][] = $found;
                    }
                }
            }

        } else {
            $filter = ['or'];
            $i = 0;
            foreach ($items as &$item) {
                $idx = '';
                foreach ($this->key as $internal => $external) {
                    $idx .= $item[$internal] . ':';
                    $filter[++$i][$external] = $item[$internal];
                }
                $map[$idx][] = &$item;
            }

            $founds = $finder->and($filter)->fetch();

            foreach ($founds as $found) {
                $idx = '';
                foreach ($this->key as $internal => $external) {
                    $idx .= $found[$external] . ':';
                }
                if (isset($map[$idx])) {
                    foreach ($map[$idx] as &$item) {
                        $item[$field][] = $found;
                    }
                }
            }

        }

        return $items;
    }


}