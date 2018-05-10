<?php
/**
 * Created by PhpStorm.
 * User: macbookpro
 * Date: 23.04.2018
 * Time: 17:49
 */

namespace vivace\db;


interface Reader extends \IteratorAggregate, \Countable
{
    /**
     * @return array|null
     */
    public function one(): ?Entity;

    public function all(): Collection;

    public function count(): int;

}