<?php
/**
 * Created by PhpStorm.
 * User: macbookpro
 * Date: 27.04.2018
 * Time: 12:34
 */

namespace vivace\db\sql\Relation;



use vivace\db\sql\Storage;

class Single extends \vivace\db\Relation\Single
{
    public function __construct(Storage $storage, array $key)
    {
        parent::__construct($storage, $key);
    }
}